<?php
/******************************************************************************
 * session_logic.php - polling, validation and session/segment/event detection
 * for the OpenEVSE session collector. Included by collector.php and the tests.
 * Needs include_v5.php (evse_http, evse_state_text, checksum) + sessions_lib.php.
 ******************************************************************************/

$GLOBALS['SESS_LOG'] = array();
function slog($level, $msg){ $GLOBALS['SESS_LOG'][] = array(time(), $level, $msg); }

define('SESS_THERMAL_C', 65.0);     // OpenEVSE ambient throttle-down threshold (650 = 65.0 C)
define('SESS_THERMAL_RESTORE_C', 62.0);
define('SESS_OUR_CLAIM', 1213399041); // EVSE_CLAIM_CLIENT in include_v5.php
// Samples outside a session: at most one per unit per this many seconds (0 = none).
// Reachability is tracked with 'reach' events, not idle samples.
define('SESS_IDLE_SAMPLE_SEC', 900);
define('SESS_PLUGGED_SAMPLE_SEC', 300);   // plugged in, not charging
// (while charging every reading is kept: ~30 s with push, 60 s with polling)

// temperatures from a /status reply: 0.1 C, -2560 / false = sensor not fitted
function sess_temps($j, &$r){
	foreach (array(1, 2, 3) as $i) {
		$v = sess_num($j, "temp$i");
		$r["temp{$i}_c"] = ($v !== null && $v > -2000 && $v < 2000) ? $v / 10 : null;
	}
}

// fields only WiFi v4/v5 sends (push + /status share create_rapi_json); absent = null
function sess_v5_extras($j){
	$n = function($k) use ($j){ return (isset($j[$k]) && is_numeric($j[$k]) && !is_bool($j[$k])) ? (float)$j[$k] : null; };
	$tc = function($k) use ($n){ $v = $n($k); return ($v !== null && $v > -400 && $v < 1200) ? $v / 10 : null; };
	$rs = $n('srssi'); $fr = $n('freeram'); $fl = $n('flags'); $mc = $n('max_current'); $se = $n('session_elapsed');
	return array(
		'srssi'=>($rs !== null && $rs < 0 && $rs >= -120) ? (int)$rs : null,
		'freeram'=>($fr !== null && $fr >= 0 && $fr < 16000000) ? (int)$fr : null,
		'status'=>(isset($j['status']) && is_string($j['status']) && strlen($j['status']) <= 20) ? $j['status'] : null,
		'flags'=>($fl !== null && $fl >= 0) ? (int)$fl : null,
		'vehicle'=>isset($j['vehicle']) ? (int)!empty($j['vehicle']) : null,
		'max_current'=>($mc !== null && $mc >= 0 && $mc <= 80) ? (int)$mc : null,
		'temp4_c'=>$tc('temp4'), 'temp_max_c'=>$tc('temp_max'),
		'session_elapsed'=>($se !== null && $se >= 0 && $se < 31536000) ? (int)$se : null);
}
// v5 'voltage' is a 240 V default until the controller answers $GG, which v5 only
// asks while charging: trust it only then (an idle v5 unit pushed 240 while its controller said 120)
function sess_v5_voltset($j, $state){
	$v = (isset($j['voltage']) && is_numeric($j['voltage'])) ? (float)$j['voltage'] : null;
	return ($state == 3 && $v !== null && $v >= 90 && $v <= 260) ? (int)round($v) : null;
}

/*------------------------------------------------------------------------------
 * RAPI over HTTP without die(): payload after "$OK", or null. Checks checksum.
 *----------------------------------------------------------------------------*/
// Every command carries a RAPI sequence id and only a reply echoing it is accepted
// (collision workaround for the 2.x WiFi's own polling; see the comment block above
// rapi() in include.php). Reads must also have the command's reply shape. A rejected
// reply returns null and is never stored; sess_try() retries reads (bounded).
// Writes ($S1 clock sync) are sent once: null = not confirmed as ours.
function sess_rapi($unit, $cmd){
	$seq = EVSE_RAPI_SEQ ? evse_rapi_seq_next() : 0;
	$wire = evse_rapi_wire(evse_rapi_with_seq($cmd, $seq));
	$r = evse_http($unit, 'GET', 'r?json=1&rapi=' . rawurlencode($wire));
	if (!$r['ok'] || !isset($r['json']['ret'])) return null;
	$GLOBALS['__sess_rapi_last'] = trim((string)$r['json']['ret']) . ' (sent ' . evse_rapi_with_seq($cmd, $seq) . ')';   // for the log line
	$rep = evse_rapi_parse_reply(trim((string)$r['json']['ret']), $seq);
	if ($rep === null || !$rep['ok']) return null;
	if (evse_rapi_is_read($cmd) && !evse_rapi_shape_ok($cmd, $rep['payload'])) return null;
	return $rep['payload'];
}
// $GS => evsestate(hex) elapsed(dec) pilotstate(hex) vflags(hex)
function sess_parse_gs($p){
	if ($p === null || !preg_match('/^([0-9a-f]{1,2}) (\d{1,7}) ([0-9a-f]{1,2}) ([0-9a-f]{1,4})$/i', $p, $m)) return null;
	$st = hexdec($m[1]);
	if (!(($st >= 0 && $st <= 11) || $st == 254 || $st == 255)) return null;
	return array('state'=>$st, 'elapsed'=>(int)$m[2], 'pilotstate'=>hexdec($m[3]), 'vflags'=>hexdec($m[4]));
}
// $GU => Wattseconds Whacc
function sess_parse_gu($p){
	if ($p === null || !preg_match('/^(\d{1,10}) (\d{1,10})$/', $p, $m)) return null;
	return array('wattsec'=>(float)$m[1], 'whacc'=>(float)$m[2]);
}
// $GE => amps(dec 6-80) flags(hex)
function sess_parse_ge($p){
	if ($p === null || !preg_match('/^(\d{1,2}) ([0-9a-f]{1,4})$/i', $p, $m)) return null;
	$a = (int)$m[1]; if ($a < 6 || $a > 80) return null;
	return array('amps'=>$a, 'flags'=>hexdec($m[2]));
}
// $GC => minamps hmaxamps pilotamps cmaxamps (decimal)
function sess_parse_gc($p){
	if ($p === null || !preg_match('/^(\d{1,2}) (\d{1,2}) (\d{1,2}) (\d{1,2})$/', $p, $m)) return null;
	$mn = (int)$m[1]; $hx = (int)$m[2]; $pl = (int)$m[3]; $cx = (int)$m[4];
	if ($mn < 6 || $mn > 16 || $hx < 6 || $hx > 80 || $pl > 80 || $cx < 6 || $cx > 80) return null;
	return array('min'=>$mn, 'hmax'=>$hx, 'pilot'=>$pl, 'cmax'=>$cx);
}
// $GD => start_h start_m stop_h stop_m (all 0 = no delay timer). Strict: 4 decimals in range,
// so crossed replies ($GT has 6 tokens, $GS has hex, $GC's amps fail the hour range) are rejected.
function sess_parse_gd($p){
	if ($p === null || !preg_match('/^(\d{1,2}) (\d{1,2}) (\d{1,2}) (\d{1,2})$/', $p, $m)) return null;
	if ($m[1] > 23 || $m[2] > 59 || $m[3] > 23 || $m[4] > 59) return null;
	$on = ($m[1] + $m[2] + $m[3] + $m[4]) > 0;
	return array('on'=>$on, 'text'=>$on ? sprintf('%02d:%02d-%02d:%02d', $m[1], $m[2], $m[3], $m[4]) : '');
}
// $G3 => cnt (x15 min), $GH => kWh
function sess_parse_int1($p){
	if ($p === null || !preg_match('/^(\d{1,4})$/', $p, $m)) return null;
	return (int)$m[1];
}
function sess_try($unit, $cmd, $parser, $tries = 3){
	for ($i = 0; $i < $tries; $i++) {
		$GLOBALS['__sess_rapi_last'] = 'no answer';
		$raw = sess_rapi($unit, $cmd);
		$v = $parser($raw);
		if ($v !== null) return $v;
		slog('warn', "{$unit['name']}: rejected $cmd reply " . json_encode($GLOBALS['__sess_rapi_last']) . ' (not ours / invalid), try ' . ($i + 1));
		usleep(evse_rapi_retry_delay_us());   // random 75-125 ms: don't fall in step with the WiFi's polling
	}
	return null;
}
function sess_num($a, $k){ return (isset($a[$k]) && is_numeric($a[$k]) && $a[$k] !== false && $a[$k] !== true) ? (float)$a[$k] : null; }
function sess_cnt($a, $k){ $v = sess_num($a, $k); return ($v !== null && $v >= 0 && $v < 1000000) ? (int)$v : null; }

/*------------------------------------------------------------------------------
 * Read one unit. $ctx: unit_state row (or false) + 'config' from evse_detect.
 * Extra calls (limits, schedule, $GC...) only while a session is open.
 *----------------------------------------------------------------------------*/
function sess_read_unit($unit, $api, $ctx){
	$r = array('ok'=>false, 'api'=>$api, 'state'=>null, 'connected'=>null, 'charging'=>null, 'amps'=>null,
		'pilot'=>null, 'cmax'=>null, 'temp_c'=>null, 'lifetime_wh'=>null, 'session_wh'=>null, 'volts'=>null,
		'elapsed'=>null, 'vflags'=>null, 'limit_sleep'=>false, 'limit'=>false, 'timer_active'=>null,
		'override'=>null, 'our_claim'=>null, 'sched_disabled'=>null,
		'gfci'=>null, 'nognd'=>null, 'stuck'=>null, 'error'=>'',
		'temp1_c'=>null, 'temp2_c'=>null, 'temp3_c'=>null, 'src'=>'poll');
	$us = isset($ctx['us']) ? $ctx['us'] : false;
	$in_session = $us && $us['session_id'];
	$s = evse_http($unit, 'GET', 'status');
	if (!$s['ok'] || !is_array($s['json'])) { $r['error'] = 'status: ' . ($s['error'] ?: 'HTTP ' . $s['code']); return $r; }
	$j = $s['json'];
	$r['gfci'] = sess_cnt($j, 'gfcicount'); $r['nognd'] = sess_cnt($j, 'nogndcount'); $r['stuck'] = sess_cnt($j, 'stuckcount');
	sess_temps($j, $r);
	$r['uptime'] = ($x = sess_num($j, 'uptime')) !== null ? (int)$x : null;          // v5 only
	$r['comm_sent'] = ($x = sess_num($j, 'comm_sent')) !== null ? (int)$x : null;    // 2.x and v5
	$r['status_ok'] = true;

	if ($api == 'http') {          // WiFi v4/v5: all in /status (+ /config from detect)
		$st = sess_num($j, 'state');
		if ($st !== null && (($st >= 0 && $st <= 11) || $st == 254 || $st == 255)) $r['state'] = (int)$st;
		$r['connected']   = isset($j['vehicle']) ? (int)!empty($j['vehicle']) : null;
		$r['amps']        = ($a = sess_num($j, 'amp')) !== null ? $a / 1000 : null;
		$r['pilot']       = ($p = sess_num($j, 'pilot')) !== null ? (int)$p : null;
		$t = sess_num($j, 'temp'); $r['temp_c'] = $t !== null ? $t / 10 : null;
		$r['lifetime_wh'] = ($l = sess_num($j, 'total_energy')) !== null ? $l * 1000 : null;
		$r['session_wh']  = sess_num($j, 'session_energy');
		$r['elapsed']     = ($e = sess_num($j, 'elapsed')) !== null ? (int)$e : null;
		$lvl = sess_num($j, 'service_level'); if ($lvl == 1 || $lvl == 2) $r['level'] = (int)$lvl;
		$r['volt_set'] = sess_v5_voltset($j, $r['state']);
		$r = array_merge($r, sess_v5_extras($j));
		if (isset($ctx['config']['max_current_soft'])) $r['cmax'] = (int)$ctx['config']['max_current_soft'];
		$plugged = $in_session || !empty($j['vehicle']);
		if ($plugged) {
			$lim = evse_get($unit, 'limit');
			if (is_array($lim) && isset($lim['type']) && $lim['type'] != 'none') {
				$r['limit'] = array('type'=>$lim['type'], 'value'=>$lim['type'] == 'energy' ? round($lim['value'] / 1000, 2) : $lim['value']);
			}
			$sch = evse_get($unit, 'schedule');
			$r['timer_active'] = is_array($sch) ? (count($sch) > 0 ? 1 : 0) : null;
			$plan = $r['timer_active'] ? evse_get($unit, 'schedule/plan') : null;
			$r['sched_disabled'] = (is_array($plan) && isset($plan['current_event']['state'])) ? ($plan['current_event']['state'] == 'disabled') : false;
			$o = evse_get($unit, 'override');
			$r['override'] = (is_array($o) && $o) ? $o : null;
			$cl = evse_get($unit, 'claims');
			if (is_array($cl)) foreach ($cl as $c) if (isset($c['client']) && $c['client'] == SESS_OUR_CLAIM) $r['our_claim'] = $c;
		}
		$r['limit_sleep'] = ($r['state'] == 254 && $r['limit'] !== false && empty($j['limit']) === false);
	} else {                       // WiFi 2.x: /status cache + RAPI
		$r['amps']  = ($a = sess_num($j, 'amp')) !== null ? $a / 1000 : null;
		$r['pilot'] = ($p = sess_num($j, 'pilot')) !== null ? (int)$p : null;
		// hottest fitted sensor (same rule as the push path)
		$tp = array_filter(array($r['temp1_c'], $r['temp2_c'], $r['temp3_c']), function($v){ return $v !== null; });
		$r['temp_c'] = $tp ? max($tp) : null;
		$st_wh = sess_num($j, 'watthour');
		$st_ws = sess_num($j, 'wattsec');
		$gs = sess_try($unit, '$GS', 'sess_parse_gs');
		if ($gs) {
			$r['state'] = $gs['state']; $r['elapsed'] = $gs['elapsed']; $r['vflags'] = $gs['vflags'];
			$r['limit_sleep']  = (bool)($gs['vflags'] & 0x0004);
			$r['timer_active'] = ($gs['vflags'] & 0x1000) ? 1 : 0;
			if ($gs['state'] != 255) $r['connected'] = ($gs['vflags'] & 0x0100) ? 1 : 0;   // invalid when pilot is -12 V
			if ($gs['state'] == 3) $r['connected'] = 1;
		}
		$gu = sess_try($unit, '$GU', 'sess_parse_gu', 2);
		if ($gu && $st_wh !== null && abs($gu['whacc'] - $st_wh) > 2000) {
			slog('warn', "{$unit['name']}: \$GU Whacc {$gu['whacc']} disagrees with /status watthour $st_wh, using /status");
			$gu = null;
		}
		$r['lifetime_wh'] = $gu ? $gu['whacc'] : $st_wh;
		$ws = $gu ? $gu['wattsec'] : $st_ws;
		$r['session_wh'] = ($ws !== null) ? $ws / 3600 : null;
		$plugged = $in_session || $r['connected'];
		// delay timer: 7.1.3 doesn't keep ECVF_TIMER_ON (0x1000) set reliably (e.g. a nightly
		// timer runs with the bit clear), so read $GD while plugged in; vflags only if $GD fails
		if ($plugged) {
			$gd = sess_try($unit, '$GD', 'sess_parse_gd', 2);
			if ($gd) { $r['timer_active'] = $gd['on'] ? 1 : 0; $r['timer_text'] = $gd['text']; }
		}
		if (!$us || !$us['level'] || ($r['state'] == 3 && !$us['charging'])) {
			$ge = sess_try($unit, '$GE', 'sess_parse_ge', 2);
			if ($ge) $r['level'] = ($ge['flags'] & 1) ? 2 : 1;     // bit0 = L2
		}
		// configured current: on first sight, when plugged in and the pilot moved
		if ($plugged && (!$us || $us['cmax'] === null || ($r['pilot'] !== null && (int)$us['pilot'] !== $r['pilot']))) {
			$gc = sess_try($unit, '$GC', 'sess_parse_gc', 2);
			if ($gc) $r['cmax'] = $gc['cmax'];
		} elseif ($us && $us['cmax'] !== null) {
			$r['cmax'] = (int)$us['cmax'];
		}
		// limits: only when the flags say one is set
		if ($plugged && $gs && ($gs['vflags'] & 0x2010)) {
			if ($gs['vflags'] & 0x0010) { $n = sess_try($unit, '$G3', 'sess_parse_int1', 2); if ($n) $r['limit'] = array('type'=>'time', 'value'=>$n * 15); }
			if (!$r['limit'] && ($gs['vflags'] & 0x2000)) { $n = sess_try($unit, '$GH', 'sess_parse_int1', 2); if ($n) $r['limit'] = array('type'=>'energy', 'value'=>$n); }
		}
	}
	if ($r['state'] !== null) $r['charging'] = ($r['state'] == 3) ? 1 : 0;
	$r['ok'] = ($r['state'] !== null);
	if (!$r['ok']) $r['error'] = 'no valid state';
	return $r;
}

/*------------------------------------------------------------------------------
 * Helpers
 *----------------------------------------------------------------------------*/
function sess_update_row($db, $table, $key, $id, $u){
	if (!$u) return;
	$cols = array(); $vals = array();
	foreach ($u as $k => $v) { $cols[] = "$k = ?"; $vals[] = $v; }
	$vals[] = $id;
	$db->prepare("UPDATE $table SET " . implode(', ', $cols) . " WHERE $key = ?")->execute($vals);
}
function sess_event($db, $unit, $sid, $segid, $ts, $kind, $detail, $from, $to, $temp, $reason){
	$db->prepare("INSERT INTO events (unit_id, unit_name, session_id, segment_id, ts, kind, detail, from_val, to_val, temp_c, reason, srssi)
		VALUES (?,?,?,?,?,?,?,?,?,?,?,(SELECT srssi FROM unit_state WHERE unit_id = ?))")
		->execute(array((int)$unit['id'], $unit['name'], $sid, $segid, $ts, $kind, $detail, $from, $to, $temp, $reason, (int)$unit['id']));
	if (in_array($kind, array('fault', 'fault_count', 'thermal'))) {
		slog('warn', "{$unit['name']}: $kind - $detail" . ($temp !== null ? " ({$temp} C)" : ''));
		if ($sid) $db->exec("UPDATE sessions SET faults = faults + 1 WHERE id = " . (int)$sid);
	}
}

// Why did charging stop? $r = reading after the stop, $conn = still plugged in
function sess_stop_reason($r, $conn){
	$st = $r['state'];
	if (!$conn) return 'unplugged';
	if ($st == 10) return 'thermal';
	if ($st >= 4 && $st <= 11) return 'fault';
	if ($st == 2) return 'car paused/full';
	if ($st == 254 || $st == 255) {
		if ($r['limit_sleep']) return 'limit reached';
		if (!empty($r['our_claim']) && isset($r['our_claim']['state']) && $r['our_claim']['state'] == 'disabled') return 'load management';
		if (!empty($r['override'])) return 'user sleep/override';
		if ($r['sched_disabled'] || ($r['api'] == 'rapi' && $r['timer_active'])) return 'schedule';
		return 'user sleep/override';
	}
	return 'unknown';
}

/*------------------------------------------------------------------------------
 * Validate + apply one reading. Pure DB logic; $now = unix time.
 *----------------------------------------------------------------------------*/
function sess_process($db, $unit, $r, $now){
	$uid = (int)$unit['id'];
	$name = $unit['name'];
	$breaker = max(6, (int)$unit['breaker'] ?: 48);
	$us = $db->query("SELECT * FROM unit_state WHERE unit_id = $uid")->fetch();
	$first = !$us;
	if ($first) {
		$db->prepare("INSERT INTO unit_state (unit_id, unit_name) VALUES (?, ?)")->execute(array($uid, $name));
		$us = $db->query("SELECT * FROM unit_state WHERE unit_id = $uid")->fetch();
	}
	$set = array('unit_name'=>$name, 'last_poll_at'=>$now, 'api'=>$r['api']);
	if (!$r['ok']) {
		$set['fail_count'] = (int)$us['fail_count'] + 1;
		$set['last_error'] = $r['error'];
		if ($set['fail_count'] == 1 || $set['fail_count'] % 60 == 0) slog('warn', "$name: read failed ({$r['error']}), {$set['fail_count']} in a row");
		sess_update_row($db, 'unit_state', 'unit_id', $uid, $set);
		return array('ok'=>false);
	}
	$set['fail_count'] = 0; $set['last_error'] = null;
	$gap = $us['last_ok_at'] ? $now - (int)$us['last_ok_at'] : null;
	$set['last_ok_at'] = $now;

	// ---- sanity checks
	if ($r['amps'] !== null && ($r['amps'] < 0 || $r['amps'] > $breaker * 1.25 + 2)) { slog('warn', "$name: ignored implausible amps {$r['amps']}"); $r['amps'] = null; }
	if ($r['pilot'] !== null && ($r['pilot'] < 0 || $r['pilot'] > 80)) { slog('warn', "$name: ignored implausible pilot {$r['pilot']}"); $r['pilot'] = null; }
	if ($r['cmax'] !== null && ($r['cmax'] < 6 || $r['cmax'] > 80)) $r['cmax'] = null;
	if ($r['temp_c'] !== null && ($r['temp_c'] < -40 || $r['temp_c'] > 120)) { slog('warn', "$name: ignored implausible temp {$r['temp_c']}"); $r['temp_c'] = null; }
	foreach (array('temp1_c', 'temp2_c', 'temp3_c') as $k) { if (!isset($r[$k]) || ($r[$k] !== null && ($r[$k] < -40 || $r[$k] > 120))) $r[$k] = null; }
	// volts used for kW (at report time): level + voltage setting, remembered per unit
	$lvl = !empty($r['level']) ? (int)$r['level'] : (isset($us['level']) ? $us['level'] : null);
	$vs  = !empty($r['volt_set']) ? (int)$r['volt_set'] : (isset($us['volt_set']) ? $us['volt_set'] : null);
	$v = evse_volts($unit, $lvl, $vs);   // override / voltmeter / assumed for the level
	if ($v) $r['volts'] = $v; elseif (empty($r['volts'])) $r['volts'] = $us['volts'] ? (int)$us['volts'] : null;
	if (!empty($r['level'])) $set['level'] = (int)$r['level'];
	if (!empty($r['volt_set'])) $set['volt_set'] = (int)$r['volt_set'];
	if (!isset($r['src'])) $r['src'] = 'poll';
	if ($r['session_wh'] !== null && ($r['session_wh'] < 0 || $r['session_wh'] > 200000)) $r['session_wh'] = null;
	if (!$r['charging'] && $r['amps'] !== null && $r['amps'] > 1) $r['amps'] = 0;
	$X = array('srssi', 'freeram', 'status', 'flags', 'vehicle', 'max_current', 'temp4_c', 'temp_max_c', 'session_elapsed');
	foreach ($X as $k) if (!isset($r[$k]) || $r['api'] != 'http') $r[$k] = null;   // 2.x readings: none of these
	if (!isset($r['uptime'])) $r['uptime'] = null;
	foreach ($X as $k) if ($r[$k] !== null) $set[$k] = $r[$k];

	// ---- lifetime counter: monotonic, plausible step; re-baseline after 5 agreeing outliers
	$lw = $r['lifetime_wh']; $accepted_lw = null;
	if ($lw !== null && $lw >= 0) {
		if ($us['lifetime_wh'] === null) {
			$accepted_lw = $lw;
			slog('info', "$name: baseline lifetime set to " . round($lw / 1000, 2) . ' kWh');
		} else {
			$last = (float)$us['lifetime_wh'];
			// 2.x controllers add a session's energy to the lifetime counter only when it
			// ends, in one step: allow for the whole time since the open (or just-closed,
			// < 30 min ago) session began, not just since the last accepted reading
			$since = (int)$us['lifetime_at'];
			$ps = $db->query("SELECT min(plug_in_at) FROM sessions WHERE unit_id = $uid AND (open = 1 OR unplug_at >= " . ($now - 1800) . ")")->fetchColumn();
			if ($ps && (int)$ps < $since) $since = (int)$ps;
			$dt = max(60, $now - $since);
			$allowed = $breaker * 250 * 1.5 * $dt / 3600 + 100;
			$d = $lw - $last;
			if ($d >= -1 && $d <= $allowed) {
				$accepted_lw = max($last, $lw);
				$set['pending_wh'] = null; $set['pending_count'] = 0;
			} else {
				// pushes never re-baseline: the 2.x module's own $GU can be crossed and
				// stick on a wrong value; only a validated poll may move the baseline
				if (!empty($r['no_rebaseline'])) { $lw = null; goto lw_done; }
				$pend = $us['pending_wh'] !== null ? (float)$us['pending_wh'] : null;
				$cnt = ($pend !== null && abs($lw - $pend) <= 200) ? (int)$us['pending_count'] + 1 : 1;
				slog('warn', "$name: rejected lifetime " . round($lw) . " Wh (last " . round($last) . ", step " . round($d) . " Wh, max " . round($allowed) . "), outlier $cnt/5");
				$set['pending_wh'] = $lw; $set['pending_count'] = $cnt;
				if ($cnt >= 5) {
					$accepted_lw = $lw; $set['pending_wh'] = null; $set['pending_count'] = 0;
					slog('warn', "$name: lifetime counter re-baselined to " . round($lw / 1000, 2) . ' kWh (5 consistent readings)');
					if ($us['session_id']) $db->exec("UPDATE sessions SET notes = trim(coalesce(notes,'') || ' counter re-baselined;'), lifetime_start_wh = NULL WHERE id = " . (int)$us['session_id']);
					if ($us['segment_id']) $db->exec("UPDATE segments SET lifetime_start_wh = NULL WHERE id = " . (int)$us['segment_id']);
				}
			}
		}
	}
	lw_done:
	if ($accepted_lw !== null) { $set['lifetime_wh'] = $accepted_lw; $set['lifetime_at'] = $now; }
	$cur_lw = ($accepted_lw !== null) ? $accepted_lw : $us['lifetime_wh'];

	// ---- connection (hold previous when unknown)
	$conn = $r['connected'];
	if ($conn === null) $conn = ($us['connected'] !== null) ? (int)$us['connected'] : 0;
	if ($r['charging']) $conn = 1;
	$charging = (int)$r['charging'];
	$prev_charging = (int)$us['charging'];
	$temp = $r['temp_c'];
	// Times are never interpolated: every recorded time is the time of a real reading (push or
	// poll), and a change is dated by the FIRST reading that shows it. $dt = time since the
	// previous good reading, used to add up charging time between two readings that both
	// showed charging; 0 if readings were missing for more than 3 min (that time is not counted).
	$dt = ($gap !== null && $gap <= 180) ? $gap : 0;
	$gapnote = ($gap !== null && $gap > 180) ? ' after ' . sess_fmt_dur($gap) . ' without a reading' : '';
	// first notice of a change: the arrival time of the push that flagged it (the receiver
	// then polls; that poll is the confirming reading). A push reading is its own notice.
	$notice = null;
	if ($r['src'] == 'push') $notice = $now;
	elseif ($us['poll_due'] && $us['poll_due_at'] && (int)$us['poll_due_at'] <= $now && (!$us['last_ok_at'] || (int)$us['poll_due_at'] > (int)$us['last_ok_at'])) $notice = (int)$us['poll_due_at'];
	// earliest stored push since the previous processed reading whose state clearly shows the
	// change (and no later push contradicts it); else this reading. See sess_first_seen().
	$since = $us['last_ok_at'] ? (int)$us['last_ok_at'] : null;
	$seen = function($cond) use ($db, $uid, $r, $since, $now){ return ($r['src'] == 'poll' && $since) ? sess_first_seen($db, $uid, $r['api'], $since, $now, $cond) : $now; };
	$srcnote = function($what, $at) use ($now){ return $at < $now ? "$what (push " . date('H:i:s', $at) . ', confirmed by poll ' . date('H:i:s', $now) . ')' : $what; };
	$noticetxt = function($what) use ($notice, $now){ return ($notice !== null && $notice != $now) ? "$what (first push notice " . date('H:i:s', $notice) . ', confirmed by poll ' . date('H:i:s', $now) . ')' : $what; };

	$sid = $us['session_id'] ? (int)$us['session_id'] : null;
	$sess = $sid ? $db->query("SELECT * FROM sessions WHERE id = $sid")->fetch() : null;
	if ($sid && !$sess) { $sid = null; }
	$segid = $us['segment_id'] ? (int)$us['segment_id'] : null;
	$seg = $segid ? $db->query("SELECT * FROM segments WHERE id = $segid")->fetch() : null;
	if ($segid && !$seg) { $segid = null; }

	// ---- faults: state changes into an error, and counters that moved between polls
	if ($r['state'] >= 4 && $r['state'] <= 11 && (int)$us['state'] !== $r['state']) {
		sess_event($db, $unit, $sid, $segid, $now, 'fault', evse_state_text($r['state']), $us['state'], $r['state'], $temp, $r['state'] == 10 ? 'thermal' : 'fault');
	}
	foreach (array('gfci'=>'GFCI trip', 'nognd'=>'No-ground trip', 'stuck'=>'Stuck-relay trip') as $k => $label) {
		$col = $k . '_count';
		if ($r[$k] === null) continue;
		if ($us[$col] !== null && $r[$k] > (int)$us[$col] && $r[$k] - (int)$us[$col] < 50) {
			sess_event($db, $unit, $sid, $segid, $now, 'fault_count', "$label count +" . ($r[$k] - (int)$us[$col]) . " (now {$r[$k]})", (int)$us[$col], $r[$k], $temp, 'fault');
		}
		$set[$col] = $r[$k];
	}
	// ---- thermal threshold crossing
	if ($temp !== null && $us['temp_c'] !== null) {
		if ($temp >= SESS_THERMAL_C && (float)$us['temp_c'] < SESS_THERMAL_C)
			sess_event($db, $unit, $sid, $segid, $now, 'thermal', 'temperature reached throttle threshold (' . SESS_THERMAL_C . ' C)', (float)$us['temp_c'], $temp, $temp, 'thermal');
		elseif ($temp <= SESS_THERMAL_RESTORE_C && (float)$us['temp_c'] > SESS_THERMAL_RESTORE_C && (float)$us['temp_c'] >= SESS_THERMAL_C - 3)
			sess_event($db, $unit, $sid, $segid, $now, 'thermal', 'temperature back below restore threshold (' . SESS_THERMAL_RESTORE_C . ' C)', (float)$us['temp_c'], $temp, $temp, 'thermal restore');
	}
	if ($temp !== null) $set['temp_c'] = $temp;
	foreach (array('temp1_c', 'temp2_c', 'temp3_c') as $k) if ($r[$k] !== null) $set[$k] = $r[$k];

	// ---- charging stop / unplug: close the segment
	if ($seg && (!$charging || !$conn)) {
		$reason = sess_stop_reason($r, $conn);
		// stop time = the first real reading (push or poll) that shows charging stopped. The time
		// from the previous reading (still charging) to it counts as charging time.
		$at = $seen('stop');
		$add = $dt ? max(0, $at - (int)$us['last_ok_at']) : 0;
		$mnow = $sess['kwh_meter'] !== null ? (float)$sess['kwh_meter'] : null;
		if ($r['session_wh'] !== null) $mnow = max((float)$mnow, round($r['session_wh'] / 1000, 3));
		sess_close_segment($db, $seg, $at, $cur_lw, $reason, $add, $mnow);
		$su = array('end_reason'=>$reason, 'charge_end_at'=>$at);
		if ($add) $su['charge_seconds'] = (int)$sess['charge_seconds'] + $add;
		if ($gapnote) $su['notes'] = trim(($sess['notes'] ?: '') . ' charge stop seen at first reading' . $gapnote . ' (that time not counted as charging);');
		if ($sess) sess_update_row($db, 'sessions', 'id', $sid, $su);
		$sess = $sid ? $db->query("SELECT * FROM sessions WHERE id = $sid")->fetch() : null;
		$seg = null; $segid = null;
	}
	// ---- unplug: close the session
	$closed_sid = null;
	if ($sess && !$conn) {
		$closed_sid = $sid;
		// the unplug reading often carries the final session meter (2.x keeps it until next plug-in)
		if ($r['session_wh'] !== null) { $m = round($r['session_wh'] / 1000, 3); if ($m > (float)$sess['kwh_meter']) { $sess['kwh_meter'] = $m; $db->prepare('UPDATE sessions SET kwh_meter = ? WHERE id = ?')->execute(array($m, $sess['id'])); } }
		$at = $seen('unplug');
		sess_close($db, $sess, $us, $at, $cur_lw, $gap);
		if ($notice !== null) $db->prepare('UPDATE sessions SET unplug_push_at = ? WHERE id = ?')->execute(array($notice, $sess['id']));
		sess_event($db, $unit, $sess['id'], null, $at, 'plug', $at < $now ? $srcnote('unplugged', $at) : $noticetxt('unplugged'), null, null, $temp, 'unplug');
		slog('info', "$name: session #{$sess['id']} closed");
		$sid = null; $sess = null;
	}
	// ---- plug-in: open a session
	if (!$sess && $conn) {
		$partial = ($first || ($gap !== null && $gap > 600)) ? 1 : 0;
		$st = $db->prepare("INSERT INTO sessions (unit_id, unit_name, plug_in_at, first_push_at, lifetime_start_wh, volts, pilot_start, partial, notes, open) VALUES (?,?,?,?,?,?,?,?,?,1)");
		$pn = $first ? 'already plugged in when logging started;' : ($gapnote ? 'plug-in seen at first reading' . $gapnote . ';' : null);
		$pat = $seen('conn');
		$st->execute(array($uid, $name, $pat, $notice, $cur_lw, $r['volts'] ?: $us['volts'], $r['pilot'], $partial, $pn));
		$sid = (int)$db->lastInsertId();
		$sess = $db->query("SELECT * FROM sessions WHERE id = $sid")->fetch();
		$db->exec("UPDATE events SET session_id = $sid WHERE unit_id = $uid AND session_id IS NULL AND ts = $now");
		// pushes stored between the plug-in reading and this one belong to the session
		if ($pat < $now) $db->exec("UPDATE samples SET session_id = $sid WHERE unit_id = $uid AND session_id IS NULL AND ts >= $pat AND ts < $now");
		sess_event($db, $unit, $sid, null, $pat, 'plug', $pat < $now ? $srcnote('plugged in', $pat) : $noticetxt('plugged in'), null, null, $temp, 'plug in');
		slog('info', "$name: session #$sid opened" . ($partial ? ' (partial)' : ''));
	}
	// ---- charging start: open a segment
	if ($sess && $charging && !$seg) {
		// start = this reading, the first that shows charging. Energy counts from the previous
		// reading's lifetime counter (a real reading) so the first minute's energy is included.
		$seg_lw = ($us['lifetime_wh'] !== null && !$first) ? (float)$us['lifetime_wh'] : $cur_lw;
		// start = the first real reading (push or poll) that shows charging, never before plug-in;
		// the time from it to this reading (both charging) counts as charging time
		$cat = max($seen('charging'), (int)$sess['plug_in_at']);
		$db->prepare("INSERT INTO segments (session_id, unit_id, start_at, stop_at, seconds, lifetime_start_wh, lifetime_end_wh, open) VALUES (?,?,?,?,?,?,?,1)")
			->execute(array($sid, $uid, $cat, $now, $now - $cat, $seg_lw, $cur_lw));
		$newseg_at = $cat;
		if ($gapnote && (int)$sess['plug_in_at'] !== $now) $db->prepare("UPDATE sessions SET notes = trim(coalesce(notes, '') || ?) WHERE id = ?")->execute(array(' charge start seen at first reading' . $gapnote . ';', $sid));
		$segid = (int)$db->lastInsertId();
		$seg = $db->query("SELECT * FROM segments WHERE id = $segid")->fetch();
		$db->exec("UPDATE sessions SET segments = segments + 1 WHERE id = $sid");
		$set['evt_amps'] = null;
	}

	if ($sess) {
		$u = array();
		if ($gap !== null && $gap > 600) { $sess['notes'] = $db->query("SELECT notes FROM sessions WHERE id = $sid")->fetchColumn(); $u['notes'] = trim(($sess['notes'] ?: '') . ' gap ' . round($gap / 60) . 'm;'); }
		if ($sess['lifetime_start_wh'] === null && $cur_lw !== null) $u['lifetime_start_wh'] = $cur_lw;
		if ($sess['pilot_start'] === null && $r['pilot'] !== null) $u['pilot_start'] = $r['pilot'];
		if ($r['limit'] && ($sess['limit_type'] != $r['limit']['type'] || (float)$sess['limit_value'] != (float)$r['limit']['value'])) {
			$u['limit_type'] = $r['limit']['type']; $u['limit_value'] = $r['limit']['value'];
			sess_event($db, $unit, $sid, $segid, $now, 'limit', "{$r['limit']['type']} limit " . $r['limit']['value'] . ($r['limit']['type'] == 'time' ? ' min' : ($r['limit']['type'] == 'energy' ? ' kWh' : '')), null, $r['limit']['value'], $temp, 'limit set');
		}
		if ($r['timer_active'] && !$sess['timer_active']) {
			$u['timer_active'] = 1;
			sess_event($db, $unit, $sid, $segid, $now, 'timer', 'schedule set' . (!empty($r['timer_text']) ? ' ' . $r['timer_text'] : ''), null, null, $temp, 'timer');
		}

		// ---- pilot / current changes while plugged in
		$prev_pilot = $us['pilot'] !== null ? (int)$us['pilot'] : null;
		if ($r['pilot'] !== null && $prev_pilot !== null && $r['pilot'] != $prev_pilot && $r['pilot'] > 0 && $prev_pilot > 0) {
			$reason = 'unknown';
			if (!empty($r['our_claim'])) $reason = 'load management (claim)';
			elseif (!empty($r['override']) && (isset($r['override']['charge_current']) || isset($r['override']['max_current']))) $reason = 'override';
			elseif ($r['cmax'] !== null && $us['cmax'] !== null && (int)$us['cmax'] != $r['cmax']) $reason = 'setting change (user)';
			elseif ($r['pilot'] < $prev_pilot && $temp !== null && $temp >= SESS_THERMAL_C - 5) $reason = 'thermal derate';
			elseif ($r['pilot'] > $prev_pilot && $us['temp_c'] !== null && (float)$us['temp_c'] >= SESS_THERMAL_C - 5) $reason = 'thermal restore';
			elseif ($r['cmax'] !== null && $r['pilot'] == $r['cmax']) $reason = 'setting change (user)';
			sess_event($db, $unit, $sid, $segid, $now, 'pilot', "pilot $prev_pilot A -> {$r['pilot']} A", $prev_pilot, $r['pilot'], $temp, $reason);
			if ($reason == 'thermal derate') sess_event($db, $unit, $sid, $segid, $now, 'thermal', "thermal derate, pilot $prev_pilot -> {$r['pilot']} A", $prev_pilot, $r['pilot'], $temp, 'thermal');
			$set['evt_amps'] = null;
		} elseif ($charging && $r['amps'] !== null) {
			$ea = $us['evt_amps'] !== null ? (float)$us['evt_amps'] : null;
			if ($ea === null || !$prev_charging) {
				$set['evt_amps'] = $r['amps'];
			} elseif (abs($r['amps'] - $ea) >= max(2.0, 0.15 * $ea)) {
				$why = ($r['amps'] < $ea) ? 'car-requested drop (pilot unchanged)' : 'car ramp up';
				sess_event($db, $unit, $sid, $segid, $now, 'amps', 'current ' . round($ea, 1) . ' A -> ' . round($r['amps'], 1) . ' A', round($ea, 2), round($r['amps'], 2), $temp, $why);
				$set['evt_amps'] = $r['amps'];
			}
		}
		if ($charging) {
			// first reading that shows charging = charge start (no back-dating)
			if (!$sess['charge_start_at']) $u['charge_start_at'] = isset($newseg_at) ? $newseg_at : $now;
			if (isset($newseg_at) && $newseg_at < $now) $u['charge_seconds'] = (int)$sess['charge_seconds'] + ($now - $newseg_at);
			if ($prev_charging && $dt) {
				$u['charge_seconds'] = (int)$sess['charge_seconds'] + $dt;
				if ($r['amps'] !== null) $u['amp_seconds'] = (float)$sess['amp_seconds'] + $r['amps'] * $dt;
			}
			if ($r['amps'] !== null && $r['amps'] > (float)$sess['peak_amps']) $u['peak_amps'] = $r['amps'];
			if ($r['pilot'] !== null) {
				$u['pilot_amps'] = $r['pilot'];
				if ($sess['pilot_min'] === null || $r['pilot'] < (int)$sess['pilot_min']) $u['pilot_min'] = $r['pilot'];
				if ($sess['pilot_max'] === null || $r['pilot'] > (int)$sess['pilot_max']) $u['pilot_max'] = $r['pilot'];
			}
			$u['end_reason'] = null;
		}
		if ($r['volts']) $u['volts'] = $r['volts'];
		if ($temp !== null && ($sess['max_temp_c'] === null || $temp > (float)$sess['max_temp_c'])) $u['max_temp_c'] = $temp;
		if ($temp !== null) {   // running average over every reading while plugged in
			$u['temp_sum'] = (float)$sess['temp_sum'] + $temp; $u['temp_n'] = (int)$sess['temp_n'] + 1;
			$u['avg_temp_c'] = round($u['temp_sum'] / $u['temp_n'], 1);
		}
		if ($r['session_elapsed'] !== null && $r['session_elapsed'] > (int)$sess['unit_elapsed']) $u['unit_elapsed'] = $r['session_elapsed'];
		if ($r['temp_max_c'] !== null && ($sess['unit_temp_max_c'] === null || $r['temp_max_c'] > (float)$sess['unit_temp_max_c'])) $u['unit_temp_max_c'] = $r['temp_max_c'];
		if ($r['src'] == 'push') $u['pushes'] = (int)$sess['pushes'] + 1;
		if ($r['session_wh'] !== null) { $m = round($r['session_wh'] / 1000, 3); if ($m > (float)$sess['kwh_meter']) $u['kwh_meter'] = $m; }
		$u['last_state'] = $r['state'];
		if ($cur_lw !== null) $u['lifetime_end_wh'] = $cur_lw;
		$start = array_key_exists('lifetime_start_wh', $u) ? $u['lifetime_start_wh'] : $sess['lifetime_start_wh'];
		if ($start !== null && $cur_lw !== null) $u['kwh'] = round(($cur_lw - $start) / 1000, 3);
		sess_update_row($db, 'sessions', 'id', $sid, $u);

		if ($seg) {
			$g = array('stop_at'=>$now);
			if ($prev_charging && $dt) {
				$g['seconds'] = (int)$seg['seconds'] + $dt;
				if ($r['amps'] !== null) $g['amp_seconds'] = (float)$seg['amp_seconds'] + $r['amps'] * $dt;
			}
			if ($r['amps'] !== null && $r['amps'] > (float)$seg['peak_amps']) $g['peak_amps'] = $r['amps'];
			if ($temp !== null && $charging) {
				if ($seg['max_temp_c'] === null || $temp > (float)$seg['max_temp_c']) $g['max_temp_c'] = $temp;
				$g['temp_sum'] = (float)$seg['temp_sum'] + $temp; $g['temp_n'] = (int)$seg['temp_n'] + 1;
				$g['avg_temp_c'] = round($g['temp_sum'] / $g['temp_n'], 1);
			}
			if ($seg['lifetime_start_wh'] === null && $cur_lw !== null) $g['lifetime_start_wh'] = $cur_lw;
			if ($cur_lw !== null) { $g['lifetime_end_wh'] = $cur_lw; $s0 = isset($g['lifetime_start_wh']) ? $g['lifetime_start_wh'] : $seg['lifetime_start_wh']; if ($s0 !== null) $g['kwh'] = round(($cur_lw - $s0) / 1000, 3); }
			sess_update_row($db, 'segments', 'id', $segid, $g);
		}
	}

	// ---- samples: every reading while charging (~30 s push / 60 s poll), 5 min plugged
	// in, plus any change inside a session (the unplug sample belongs to the closed
	// session); outside sessions at most one per SESS_IDLE_SAMPLE_SEC (0 = none)
	$smp_sid = $sid ?: $closed_sid;
	$since = $us['last_sample_at'] ? $now - (int)$us['last_sample_at'] : null;
	if ($smp_sid) {
		$want = ($since === null || $since >= ($charging ? 1 : SESS_PLUGGED_SAMPLE_SEC - 10) || $charging != $prev_charging || (int)$conn != (int)$us['connected']
			|| ($r['pilot'] !== null && $us['pilot'] !== null && $r['pilot'] != (int)$us['pilot']));
		$res = $charging ? ($dt ?: 60) : SESS_PLUGGED_SAMPLE_SEC;
	} else {
		$want = SESS_IDLE_SAMPLE_SEC > 0 && ($since === null || $since >= SESS_IDLE_SAMPLE_SEC - 10);
		$res = SESS_IDLE_SAMPLE_SEC;
	}
	if ($want && empty($r['sample_stored'])) {   // pushes store their own sample (push_logic.php)
		// kW is not stored: amps + the voltage in use; reports compute kW = A x V
		$db->prepare("INSERT INTO samples (unit_id, session_id, ts, state, connected, amps, pilot, volts, temp_c, temp1_c, temp2_c, temp3_c, lifetime_wh, session_wh, res, src,
				srssi, freeram, status, flags, vehicle, max_current, temp4_c, temp_max_c, session_elapsed, uptime) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
			->execute(array($uid, $smp_sid, $now, $r['state'], $conn, $r['amps'], $r['pilot'], $r['volts'] ?: null, $temp, $r['temp1_c'], $r['temp2_c'], $r['temp3_c'], $accepted_lw, $r['session_wh'], (int)$res, $r['src'],
				$r['srssi'], $r['freeram'], $r['status'], $r['flags'], $r['vehicle'], $r['max_current'], $r['temp4_c'], $r['temp_max_c'], $r['session_elapsed'], $r['uptime']));
		$set['last_sample_at'] = $now;
	}
	$set['connected'] = $conn; $set['state'] = $r['state']; $set['charging'] = $charging;
	$set['session_id'] = $sid; $set['segment_id'] = $segid;
	if ($r['vflags'] !== null) $set['vflags'] = $r['vflags'];
	if ($r['pilot'] !== null) $set['pilot'] = $r['pilot'];
	if ($r['cmax'] !== null) $set['cmax'] = $r['cmax'];
	if ($r['volts']) $set['volts'] = $r['volts'];
	sess_update_row($db, 'unit_state', 'unit_id', $uid, $set);
	return array('ok'=>true, 'session_id'=>$sid, 'segment_id'=>$segid, 'connected'=>$conn, 'charging'=>$charging, 'lifetime_wh'=>$cur_lw);
}

// Earliest stored push sample in ($since, $now) that clearly shows $cond, with no later push
// contradicting it; $now if none. 2.8.2 states: 1 = not connected, 2 = connected, 3 = charging,
// 0 (sleeping/disabled/over-temp) is stored as NULL and never dates anything. v5: exact state
// codes and the vehicle flag.
function sess_first_seen($db, $uid, $api, $since, $now, $cond){
	$st = $db->prepare("SELECT ts, state, connected FROM samples WHERE unit_id = ? AND src = 'push' AND ts > ? AND ts < ? ORDER BY ts DESC");
	$st->execute(array($uid, $since, $now));
	$best = $now;
	foreach ($st->fetchAll() as $p) {
		$s = $p['state'] !== null ? (int)$p['state'] : null; $c = $p['connected'] !== null ? (int)$p['connected'] : null;
		$v = null;   // true = shows the change, false = contradicts it, null = says nothing
		if ($cond == 'conn')        $v = $c === null ? null : ($c === 1);
		elseif ($cond == 'unplug')  $v = $c === null ? null : ($c === 0);
		elseif ($cond == 'charging') $v = $s === null ? null : ($s === 3);
		elseif ($cond == 'stop') {
			if ($s === null) $v = null;
			elseif ($api == 'http') $v = ($s !== 3);
			else $v = ($s === 3) ? false : (($s === 1 || $s === 2) ? true : null);
		}
		if ($v === false) break;
		if ($v === true) $best = (int)$p['ts'];
	}
	return $best;
}

function sess_close_segment($db, $seg, $stop_at, $cur_lw, $reason, $extra_sec = 0, $meter_now = null){
	$g = array('open'=>0, 'stop_reason'=>$reason, 'stop_at'=>$stop_at, 'seconds'=>(int)$seg['seconds'] + $extra_sec);
	$seg['seconds'] = $g['seconds'];
	if ($cur_lw !== null) $g['lifetime_end_wh'] = $cur_lw;
	$s0 = $seg['lifetime_start_wh']; $e0 = isset($g['lifetime_end_wh']) ? $g['lifetime_end_wh'] : $seg['lifetime_end_wh'];
	if ($s0 !== null && $e0 !== null) $g['kwh'] = round(($e0 - $s0) / 1000, 3);
	// 2.x controllers add a session's energy to the lifetime counter only at the end, so a
	// segment's lifetime delta is 0 mid-session (or the whole session at the end). The unit's
	// own session meter (real data) minus the earlier segments is used when they disagree.
	if ($meter_now !== null && $meter_now > 0) {
		$prev = (float)$db->query("SELECT coalesce(sum(kwh), 0) FROM segments WHERE session_id = " . (int)$seg['session_id'] . " AND id <> " . (int)$seg['id'] . " AND open = 0")->fetchColumn();
		$mk = round($meter_now - $prev, 3);
		if ($mk >= 0 && (!isset($g['kwh']) || abs($g['kwh'] - $mk) > max(0.3, 0.1 * $mk))) $g['kwh'] = $mk;
	}
	if ((int)$seg['seconds'] > 0) $g['avg_amps'] = round((float)$seg['amp_seconds'] / (int)$seg['seconds'], 2);
	if ((int)$seg['temp_n'] > 0) $g['avg_temp_c'] = round((float)$seg['temp_sum'] / (int)$seg['temp_n'], 1);
	sess_update_row($db, 'segments', 'id', (int)$seg['id'], $g);
}

function sess_close($db, $sess, $us, $now, $cur_lw, $gap){
	// unplug time = this reading, the first that shows the car unplugged (never estimated)
	$u = array('open'=>0, 'unplug_at'=>$now);
	$notes = $db->query("SELECT notes FROM sessions WHERE id = " . (int)$sess['id'])->fetchColumn() ?: '';
	if ($gap !== null && $gap > 180) $notes .= ' unplug seen at first reading after ' . sess_fmt_dur($gap) . ' without a reading;';
	if (!$sess['charge_start_at'])                 $u['end_reason'] = 'no charge';
	elseif ($sess['end_reason'] == 'unplugged')    $u['end_reason'] = 'unplugged while charging';
	elseif (!$sess['end_reason'])                  $u['end_reason'] = 'unknown';
	if ($cur_lw !== null) $u['lifetime_end_wh'] = $cur_lw;
	$start = $sess['lifetime_start_wh']; $end = isset($u['lifetime_end_wh']) ? $u['lifetime_end_wh'] : $sess['lifetime_end_wh'];
	$meter = $sess['kwh_meter'] !== null ? (float)$sess['kwh_meter'] : null;
	if ($start !== null && $end !== null && !($end - $start < 50 && $meter !== null && $meter >= 0.5)) {
		$u['kwh'] = round(($end - $start) / 1000, 3); $u['kwh_source'] = 'lifetime';
		if ($meter !== null && $meter > 0 && abs($u['kwh'] - $meter) > max(0.3, 0.1 * $meter)) $notes .= " unit session meter says {$meter} kWh;";
	} elseif ($meter !== null) {
		// no usable lifetime step (counter not updated yet / reading rejected): the unit's session meter
		$u['kwh'] = $meter; $u['kwh_source'] = 'meter';
		if ($start !== null && $end !== null) $notes .= ' lifetime counter had not moved at unplug, kWh from unit session meter;';
	}
	// charging time from segments (more exact than per-poll accumulation)
	$segsec = (int)$db->query("SELECT coalesce(sum(seconds),0) FROM segments WHERE session_id = " . (int)$sess['id'])->fetchColumn();
	$cs = max((int)$sess['charge_seconds'], $segsec);
	$u['charge_seconds'] = $cs;
	if ($cs > 0) {
		$u['avg_amps'] = round((float)$sess['amp_seconds'] / $cs, 2);
	}
	$v = $sess['volts'] ?: 240;
	// very short sessions: 1-minute polling can't time them exactly; never show avg above peak
	$u['notes'] = trim($notes) ?: null;
	sess_update_row($db, 'sessions', 'id', (int)$sess['id'], $u);
}

/*------------------------------------------------------------------------------
 * Daily maintenance: idle samples kept (seasonal temps); session samples older than
 * 90 days averaged into 10-minute rows. Sessions, segments, events kept.
 *----------------------------------------------------------------------------*/
function sess_maintenance($db, $now, $force = false){
	$last = (int)$db->query("SELECT v FROM meta WHERE k = 'last_maintenance'")->fetchColumn();
	if (!$force && $now - $last < 86400) return;
	sess_begin($db);
	$n1 = 0;   // idle samples (<= 1 per 15 min per unit, with temperatures) are kept for seasonal history
	$cut = $now - 90 * 86400;
	$db->exec("INSERT INTO samples (unit_id, session_id, ts, state, connected, amps, pilot, volts, temp_c, temp1_c, temp2_c, temp3_c, lifetime_wh, session_wh, res, src,
			srssi, freeram, status, flags, vehicle, max_current, temp4_c, temp_max_c, session_elapsed, uptime)
		SELECT unit_id, session_id, MIN(ts), MAX(state), MAX(connected), AVG(amps), MAX(pilot), MAX(volts), MAX(temp_c), MAX(temp1_c), MAX(temp2_c), MAX(temp3_c), MAX(lifetime_wh), MAX(session_wh), 600, 'thin',
			CAST(round(AVG(srssi)) AS INTEGER), MIN(freeram), MAX(status), MAX(flags), MAX(vehicle), MAX(max_current), MAX(temp4_c), MAX(temp_max_c), MAX(session_elapsed), MAX(uptime)
		FROM samples WHERE session_id IS NOT NULL AND res < 600 AND ts < $cut GROUP BY session_id, ts / 600");
	$n2 = $db->exec("DELETE FROM samples WHERE session_id IS NOT NULL AND res < 600 AND ts < $cut");
	// idle samples (outside sessions; every push is stored): after 7 days keep one real row per
	// unit per 15 min (a poll if there is one, else the first push) for seasonal history
	$icut = $now - 7 * 86400;
	$n1 = $db->exec("DELETE FROM samples WHERE session_id IS NULL AND ts < $icut AND res < 900 AND id NOT IN (
		SELECT id FROM (SELECT id, row_number() OVER (PARTITION BY unit_id, ts / 900 ORDER BY (src = 'poll') DESC, ts) rn
			FROM samples WHERE session_id IS NULL AND ts < $icut AND res < 900) WHERE rn = 1)");
	$db->exec("UPDATE samples SET res = 900 WHERE session_id IS NULL AND ts < $icut AND res < 900");
	$db->prepare("INSERT OR REPLACE INTO meta (k, v) VALUES ('last_maintenance', ?)")->execute(array($now));
	sess_commit($db);
	$db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
	$db->exec('PRAGMA optimize');
	slog('info', "maintenance: downsampled $n2 old session samples, thinned $n1 idle samples");
}

/*------------------------------------------------------------------------------
 * Full poll of one unit (collector, or the receiver right after a push showed a
 * change). HTTP reads happen outside the per-unit lock; the DB update inside it,
 * so a push for the same unit never interleaves with a half-applied poll.
 *----------------------------------------------------------------------------*/
define('SESS_PUSH_STALE_SEC', 150);      // 5 missed 30-s pushes = "pushes stopped"
define('SESS_POLL_SESSION_SEC', 120);    // push units: poll this often while plugged in
define('SESS_POLL_IDLE_SEC', 900);       // push units: slow check (settings, counters, lifetime)
define('SESS_POLL_DUE_MAX_SEC', 300);    // poll still failing after this: process pushes anyway
define('SESS_UNREACH_FAILS', 2);         // failed HTTP polls in a row = stopped answering

function sess_unit_lock($uid){
	$lk = @fopen(SESS_DIR . '/unit_' . (int)$uid . '.lock', 'c');
	if ($lk) flock($lk, LOCK_EX);
	return $lk;
}
function sess_unit_unlock($lk){ if ($lk) { flock($lk, LOCK_UN); fclose($lk); } }

function sess_poll_one($db, $unit, $why = ''){
	$uid = (int)$unit['id'];
	$us = $db->query("SELECT * FROM unit_state WHERE unit_id = $uid")->fetch();
	$d = evse_detect($unit);
	$api = $d['api'] == 'offline' ? (($us && $us['api']) ? $us['api'] : 'rapi') : $d['api'];
	if ($us && $us['api'] && $d['api'] != 'offline' && $us['api'] != $d['api']) slog('info', "{$unit['name']}: API changed {$us['api']} -> {$d['api']} ({$d['version']})");
	$r = ($d['api'] == 'offline') ? array('ok'=>false, 'api'=>$api, 'error'=>'offline (HTTP ' . $d['code'] . ')', 'http_fail'=>true)
		: sess_read_unit($unit, $api, array('us'=>$us, 'config'=>$d['config']));
	if (!$r['ok'] && !isset($r['http_fail'])) $r['http_fail'] = (strpos((string)$r['error'], 'status:') === 0);
	$r['src'] = 'poll';
	$lk = sess_unit_lock($uid);
	try {
		$now = time();
		sess_begin($db);
		$res = sess_process($db, $unit, $r, $now);
		$us2 = $db->query("SELECT * FROM unit_state WHERE unit_id = $uid")->fetch();
		$u = sess_check_reboot($db, $unit, $us2, $r, $now);
		$u['last_full_poll_at'] = $now;
		$recent_push = $us2['last_push_at'] && $now - (int)$us2['last_push_at'] <= 90;
		if ($r['ok']) {
			$u['poll_due'] = 0;
			if ($us2['reach'] !== null && (int)$us2['reach'] === 0) {
				sess_event($db, $unit, $us2['session_id'] ?: null, $us2['segment_id'] ?: null, $now, 'reach',
					'answering again after ' . sess_fmt_dur($now - (int)$us2['reach_since']), null, null, null, 'reachable');
				slog('info', "{$unit['name']}: answering again after " . sess_fmt_dur($now - (int)$us2['reach_since']));
			}
			if ((int)$us2['reach'] !== 1) { $u['reach'] = 1; $u['reach_since'] = $now; }
		} elseif ($r['http_fail'] && !$recent_push && (int)$us2['fail_count'] >= SESS_UNREACH_FAILS && $us2['reach'] !== null && (int)$us2['reach'] === 1) {
			$since = $us2['last_ok_at'] ? (int)$us2['last_ok_at'] : $now;
			sess_event($db, $unit, $us2['session_id'] ?: null, $us2['segment_id'] ?: null, $since, 'reach',
				'stopped answering (' . $r['error'] . ')', null, null, null, 'unreachable');
			slog('warn', "{$unit['name']}: stopped answering since " . date('H:i:s', $since));
			$u['reach'] = 0; $u['reach_since'] = $since;
		}
		sess_update_row($db, 'unit_state', 'unit_id', $uid, $u);
		sess_commit($db);
	} catch (Throwable $e) {
		if ($db->inTransaction()) sess_rollback($db);
		sess_unit_unlock($lk);
		throw $e;
	}
	sess_unit_unlock($lk);
	return array($r, $res, $api);
}

// WiFi module reboot: v5 uptime gives the boot time; on 2.x the comm_sent counter
// going backwards means it restarted since the last poll (time estimated from the
// counter); the restart time is not known, so the event is dated when it was detected and
// boot_at holds the previous poll (the restart happened after it): uptime is shown as "under".
function sess_check_reboot($db, $unit, $us, $r, $now){
	$u = array();
	if (empty($r['status_ok']) || !$us) return $u;
	$boot = null; $exact = 0;
	if (isset($r['uptime']) && $r['uptime'] !== null) {
		$boot = $now - $r['uptime']; $exact = 1;
		$rebooted = $us['boot_at'] !== null && $boot > (int)$us['boot_at'] + 120;
	} else {
		$rebooted = isset($r['comm_sent']) && $r['comm_sent'] !== null && $us['comm_sent'] !== null && $r['comm_sent'] < (int)$us['comm_sent'];
		if ($rebooted) $boot = $us['last_full_poll_at'] ? (int)$us['last_full_poll_at'] : $now;
	}
	if ($rebooted) {
		$prev = $us['last_full_poll_at'] ? (int)$us['last_full_poll_at'] : null;
		sess_event($db, $unit, $us['session_id'] ?: null, $us['segment_id'] ?: null, $exact ? (int)$boot : $now, 'reboot',
			$exact ? 'WiFi module restarted' : 'WiFi module restart detected (restarted after ' . ($prev ? date('H:i:s', $prev) : '?') . ')', null, null, null, 'wifi reboot');
		slog('warn', "{$unit['name']}: WiFi module " . ($exact ? 'restarted at ' . date('Y-m-d H:i:s', (int)$boot) : 'restart detected, restarted after ' . ($prev ? date('H:i:s', $prev) : '?')));
	}
	if ($exact) { if ($us['boot_at'] === null || $rebooted) $u['boot_at'] = $boot; $u['boot_exact'] = 1; }
	elseif ($rebooted) { $u['boot_at'] = $boot; $u['boot_exact'] = 0; }
	if (isset($r['uptime']) && $r['uptime'] !== null) $u['wifi_uptime'] = $r['uptime'];
	if (isset($r['comm_sent']) && $r['comm_sent'] !== null) $u['comm_sent'] = $r['comm_sent'];
	return $u;
}

// collector: pushes went quiet -> one event (time = last push), cleared by the next push
function sess_check_push_stale($db, $unit, $now){
	$uid = (int)$unit['id'];
	$us = $db->query("SELECT * FROM unit_state WHERE unit_id = $uid")->fetch();
	if (!$us || !$us['last_push_at'] || (int)$us['push_reach'] !== 1) return;
	if ($now - (int)$us['last_push_at'] <= SESS_PUSH_STALE_SEC) return;
	$lk = sess_unit_lock($uid);
	sess_begin($db);
	sess_event($db, $unit, $us['session_id'] ?: null, $us['segment_id'] ?: null, (int)$us['last_push_at'], 'reach', 'pushes stopped', null, null, null, 'push stopped');
	sess_update_row($db, 'unit_state', 'unit_id', $uid, array('push_reach'=>0, 'push_reach_since'=>(int)$us['last_push_at']));
	sess_commit($db);
	sess_unit_unlock($lk);
	slog('warn', "{$unit['name']}: pushes stopped, last at " . date('H:i:s', (int)$us['last_push_at']));
}

// should the collector poll this unit now? returns reason or ''
function sess_poll_reason($us, $push_unit, $now){
	if (!$push_unit || !$us) return 'poll-only unit';
	if ($us['poll_due']) return 'push saw a change';
	if (!$us['last_push_at'] || $now - (int)$us['last_push_at'] > SESS_PUSH_STALE_SEC) return 'no recent push';
	$last = (int)$us['last_full_poll_at'];
	if ($us['session_id'] && $now - $last >= SESS_POLL_SESSION_SEC - 5) return 'in session';
	if ($now - $last >= SESS_POLL_IDLE_SEC - 5) return 'slow check';
	return '';
}
