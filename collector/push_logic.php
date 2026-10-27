<?php
/******************************************************************************
 * push_logic.php - handles one Emoncms-style push from an OpenEVSE 2.x WiFi
 * module (called by <site>/emoncms/receiver.php, and by the tests).
 *
 * 2.8.2 sends every 30 s (fixed timer, not on change):
 *   amp (mA), wh (lifetime Wh, from $GU), temp1/temp2/temp3 (0.1 C; -2560 = no
 *   sensor), pilot (A), state ($GS parsed as DECIMAL: 1/2/3 OK, but 254 "fe"
 *   sleeping and 10 "0a" over-temp both arrive as 0), freeram, divertmode.
 * Not sent: connected flag, session Wh, soft max ($GC), limits, schedule, fault
 * counters, service level. Those come from the collector's polls.
 *
 * 2.9.1 (last ESP8266 release, src/input.cpp create_rapi_json) sends
 *   /input/post?fulljson=<urlencoded JSON>&node=&apikey=  (receiver.php takes both)
 * with: amp (mA), voltage (V, from $GG mV/1000; renamed from 2.8.2's volt in mV;
 * 240 default when the controller has no reading), pilot, wh, temp1-3 (0.1 C, or
 * JSON false = no sensor), state, freeram, divertmode, srssi (dBm).
 * State there is parsed base 16 once its $GV check says RAPI >= 5.0.0 (ours: 5.1.3):
 * 254 sleeping / 255 disabled / 10 over-temp arrive as themselves, so the 2.8.2
 * pushed-0 workaround below is NOT applied; a 2.9.1 0 means "starting" (module
 * hasn't read the state yet) and is just skipped. The crossed-reply filter for
 * faults 4-9 stays on for both (a crossed "7.1.3" still parses as 7 in hex).
 * https://github.com/OpenEVSE/ESP8266_WiFi_v2.x/blob/2.9.1/src/input.cpp
 ******************************************************************************/
if (!defined('SESS_DIR')) define('SESS_DIR', __DIR__);
if (!defined('SESS_WEB')) define('SESS_WEB', dirname(__DIR__));   // the site folder
require_once SESS_WEB . '/include_v5.php';
require_once SESS_WEB . '/sessions_lib.php';
require_once SESS_DIR . '/session_logic.php';

function push_flushlog(){
	$f = SESS_DIR . '/push.log';
	if (is_file($f) && filesize($f) > 2 * 1024 * 1024) @rename($f, $f . '.1');
	$out = '';
	foreach ($GLOBALS['SESS_LOG'] as $l) $out .= date('Y-m-d H:i:s', $l[0]) . ' ' . strtoupper($l[1]) . ' ' . $l[2] . "\n";
	if ($out !== '') @file_put_contents($f, $out, FILE_APPEND | LOCK_EX);
	$GLOBALS['SESS_LOG'] = array();
}

// Emoncms accepts strict JSON and its own "{key:value,...}" form; 2.8.2 also emits
// an unquoted  volt:  when a voltage is set. Quote bare keys, then decode.
function push_parse_json($raw){
	if ($raw === null) return null;
	$raw = trim($raw);
	$j = json_decode($raw, true);
	if (!is_array($j)) {
		$fixed = preg_replace('/([{,])\s*([A-Za-z_][A-Za-z0-9_]*)\s*:/', '$1"$2":', $raw);
		$j = json_decode($fixed, true);
	}
	if (!is_array($j)) {   // CSV form: data=1,2,3 -> 1:,2:,3:
		if (preg_match('/^[-0-9.,]+$/', $raw)) { $j = array(); foreach (explode(',', $raw) as $i => $v) $j[(string)($i + 1)] = $v; }
		else return null;
	}
	return $j;
}

// v5 (fulljson, every 30 s + on changes): exact state codes, vehicle flag, session and
// lifetime energy, temps (0.1 C, false = no sensor), voltage setting, uptime.
// Not sent: limit, schedule, override, claims, fault counters, service level.
function push_v5_reading($j, $us){
	$num = function($k) use ($j){ return (isset($j[$k]) && is_numeric($j[$k]) && !is_bool($j[$k])) ? (float)$j[$k] : null; };
	$t = array();
	foreach (array(1, 2, 3) as $i) { $v = $num("temp$i"); $t[$i] = ($v !== null && $v > -2000 && $v < 2000) ? $v / 10 : null; }
	$st = $num('state');
	$r = array('ok'=>false, 'api'=>'http', 'src'=>'push', 'state'=>null, 'connected'=>null, 'charging'=>null,
		'amps'=>($num('amp') !== null ? $num('amp') / 1000 : null), 'pilot'=>($num('pilot') !== null ? (int)$num('pilot') : null),
		'cmax'=>($us && $us['cmax'] !== null) ? (int)$us['cmax'] : null, 'temp_c'=>($num('temp') !== null ? $num('temp') / 10 : null),
		'temp1_c'=>$t[1], 'temp2_c'=>$t[2], 'temp3_c'=>$t[3],
		'lifetime_wh'=>($num('total_energy') !== null ? $num('total_energy') * 1000 : null), 'session_wh'=>$num('session_energy'),
		'volts'=>null, 'volt_set'=>null,
		'elapsed'=>($num('elapsed') !== null ? (int)$num('elapsed') : null), 'vflags'=>null,
		'limit_sleep'=>false, 'limit'=>false, 'timer_active'=>null, 'override'=>null, 'our_claim'=>null, 'sched_disabled'=>null,
		'gfci'=>null, 'nognd'=>null, 'stuck'=>null, 'error'=>'', 'push_state'=>($st !== null ? (int)$st : null),
		'freeram'=>$num('freeram'), 'uptime'=>($num('uptime') !== null ? (int)$num('uptime') : null), 'no_rebaseline'=>true);
	if ($r['temp_c'] === null) { $p = array_filter($t, function($v){ return $v !== null; }); if ($p) $r['temp_c'] = max($p); }
	if ($r['lifetime_wh'] !== null && $r['lifetime_wh'] <= 0) $r['lifetime_wh'] = null;
	if ($st !== null && (($st >= 0 && $st <= 11) || $st == 254 || $st == 255)) $r['state'] = (int)$st;
	if (isset($j['vehicle'])) $r['connected'] = (int)!empty($j['vehicle']);
	if ($r['state'] !== null) $r['charging'] = ($r['state'] == 3) ? 1 : 0;
	$r['ok'] = ($r['state'] !== null && $r['state'] !== 0);   // 0 = controller starting
	if (!$r['ok']) $r['error'] = 'push: no usable state';
	$r['volt_set'] = sess_v5_voltset($j, $r['state']);
	$r = array_merge($r, sess_v5_extras($j));
	return $r;
}

// 2.9.x push? Its payload has srssi and voltage (2.8.2: neither; volt only when set)
function push_is_29($j){ return is_array($j) && (array_key_exists('srssi', $j) || array_key_exists('voltage', $j)); }

// Map 2.8.2 / 2.9.1 push fields onto the collector's reading shape.
function push_to_reading($j, $us){
	if (isset($j['evse_connected']) || isset($j['vehicle']) || isset($j['total_energy'])) return push_v5_reading($j, $us);
	$v29 = push_is_29($j);
	// is_numeric(false) is false, so 2.9.1's "no sensor" false becomes null (never 0)
	$num = function($k) use ($j){ return (isset($j[$k]) && !is_bool($j[$k]) && is_numeric($j[$k])) ? (float)$j[$k] : null; };
	$t = array();
	foreach (array(1, 2, 3) as $i) { $v = $num("temp$i"); $t[$i] = ($v !== null && $v > -2000 && $v < 2000) ? $v / 10 : null; }   // -2560 = sensor not fitted
	$st = $num('state');
	$r = array('ok'=>false, 'api'=>'rapi', 'src'=>'push', 'state'=>null, 'connected'=>null, 'charging'=>null,
		'amps'=>($num('amp') !== null ? $num('amp') / 1000 : null), 'pilot'=>($num('pilot') !== null ? (int)$num('pilot') : null),
		'cmax'=>null, 'temp_c'=>null, 'temp1_c'=>$t[1], 'temp2_c'=>$t[2], 'temp3_c'=>$t[3],
		'lifetime_wh'=>$num('wh'), 'session_wh'=>null, 'volts'=>null, 'elapsed'=>null, 'vflags'=>null,
		'limit_sleep'=>false, 'limit'=>false, 'timer_active'=>null, 'override'=>null, 'our_claim'=>null, 'sched_disabled'=>null,
		'gfci'=>null, 'nognd'=>null, 'stuck'=>null, 'error'=>'', 'push_state'=>($st !== null ? (int)$st : null),
		'freeram'=>$num('freeram'), 'uptime'=>null, 'no_rebaseline'=>true,
		'wifi29'=>$v29, 'srssi'=>($num('srssi') !== null && $num('srssi') < 0 && $num('srssi') > -120) ? (int)$num('srssi') : null);
	// temp_c = hottest sensor present (the controller throttles on the max of its sensors)
	$present = array_filter(array($t[1], $t[2], $t[3]), function($v){ return $v !== null; });
	if ($present) $r['temp_c'] = max($present);
	if ($r['lifetime_wh'] !== null && $r['lifetime_wh'] <= 0) $r['lifetime_wh'] = null;   // $GU not read yet after a WiFi reboot
	if ($st !== null && in_array((int)$st, array(1, 2, 3))) {
		$r['state'] = (int)$st;
		$r['connected'] = ($st == 1) ? 0 : 1;
	} elseif ($st !== null && (int)$st === 0 && $v29) {
		// 2.9.x: 0 = OPENEVSE_STATE_STARTING (module just booted / no $GS answer yet): no state
	} elseif ($st !== null && (int)$st === 0) {
		// 2.8.2: "fe"/"ff"/"0a"... parsed as decimal: sleeping, disabled or a hex state.
		// Keep the previous known state's connection; the collector polls to resolve.
		$r['state'] = ($us && $us['state'] !== null && !in_array((int)$us['state'], array(1, 2, 3))) ? (int)$us['state'] : 254;
		$r['connected'] = null;
	} elseif ($st !== null && $st >= 4 && $st <= 9) {
		// faults 4-9 survive decimal parsing, but so do crossed replies the module read as
		// its $GS (e.g. 7 from $GV "7.1.3", 6 from $GC "6 80 48 48", 7 from $GF "7 0 0"):
		// COLLISION WORKAROUND (2.8.2): a pushed fault counts only if the last confirmed
		// state is that same fault; otherwise state stays null (raw value kept in
		// push_state) and the confirm-poll (push_consistent() is false) decides.
		if ($us && $us['state'] !== null && (int)$us['state'] === (int)$st) $r['state'] = (int)$st;
	} elseif ($st !== null && in_array((int)$st, array(10, 254, 255))) {
		$r['state'] = (int)$st;     // 2.8.2: only via the $ST async event (parsed base 16); 2.9.x: always hex. Real either way
		if ($r['state'] == 254 || $r['state'] == 255) $r['connected'] = null;   // sleeping/disabled says nothing about the plug
	}
	// anything else (e.g. 26, 193913712) is a RAPI reply the module mis-read: state stays null
	if ($r['state'] !== null) $r['charging'] = ($r['state'] == 3) ? 1 : 0;
	$v = $num('volt');   // 2.8.2: the controller's $GG voltage in mV (its setting: 120000 on the L1 unit)
	if ($v !== null && $v >= 90000 && $v <= 260000) $r['volt_set'] = (int)round($v / 1000);
	$v = $num('voltage');   // 2.9.x: same $GG reading in volts (240 = its default when $GG has none)
	if ($v !== null && $v >= 90000 && $v <= 260000) $v = $v / 1000;
	if ($v !== null && $v >= 90 && $v <= 260 && !($v == 240 && $us && !empty($us['volt_set']) && (int)$us['volt_set'] != 240)) $r['volt_set'] = (int)round($v);
	$r['no_rebaseline'] = true;
	if ($us && $us['cmax'] !== null) $r['cmax'] = (int)$us['cmax'];
	$r['ok'] = ($r['state'] !== null);
	if (!$r['ok']) $r['error'] = 'push: no usable state';
	return $r;
}

// Does the push agree with what we know? 2.8.2 sends sleeping/disabled/over-temp
// as 0, so 0 is consistent with any state outside 1-9.
function push_consistent($us, $r){
	if (!$us || $us['state'] === null) return false;
	$ps = $r['push_state']; $st = (int)$us['state'];
	// garbage state (the module's own RAPI reply got crossed, e.g. 193913712 seen on one unit
	// right after one of our polls): not a change; the reading is skipped (ok = false)
	if ($ps === null || $ps < 0 || ($ps > 11 && $ps != 254 && $ps != 255)) return true;
	if ($r['api'] == 'http') { if ($ps !== $st) return false; }       // v5 sends exact codes
	elseif ($ps === 0 && !empty($r['wifi29'])) return true;          // 2.9.x "starting": says nothing
	elseif ($ps === 0) { if ($st >= 1 && $st <= 9) return false; }
	elseif ($ps !== $st) return false;
	if ($r['pilot'] !== null && $us['pilot'] !== null && (int)$us['pilot'] !== $r['pilot'] && $r['pilot'] > 0) return false;
	return true;
}

// One samples row per push. state: only when the push states it unambiguously (2.8.2 sends
// sleeping/disabled/over-temp as 0: stored as NULL, raw value in push_state). lifetime_wh: as
// sent, unless it is clearly a crossed reply (below the last accepted value or > 200 kWh above).
function push_store_sample($db, $uid, $us, $r, $now, $pres){
	$sid = $us && $us['session_id'] ? (int)$us['session_id'] : null;
	if ($pres && !empty($pres['ok'])) $sid = $pres['session_id'] ?: $sid;   // opened, or closed by this push
	$ps = $r['push_state'];
	if ($r['api'] == 'http') $state = ($ps !== null && (($ps >= 0 && $ps <= 11) || $ps == 254 || $ps == 255)) ? $ps : null;
	else $state = $r['state'];   // 2.8.2: only what push_to_reading() accepted (crossed-reply faults and the ambiguous 0 => NULL)
	if ($r['api'] != 'http' && $ps === 0) $state = null;
	$conn = ($state !== null || $r['api'] == 'http') ? $r['connected'] : null;
	$lw = $r['lifetime_wh'];
	if ($lw !== null && $us && $us['lifetime_wh'] !== null && ($lw < (float)$us['lifetime_wh'] - 1 || $lw > (float)$us['lifetime_wh'] + 200000)) $lw = null;
	$res = ($us && $us['last_push_at'] && $now > (int)$us['last_push_at']) ? min(300, $now - (int)$us['last_push_at']) : 30;
	$g = function($k) use ($r){ return isset($r[$k]) ? $r[$k] : null; };
	$db->prepare("INSERT INTO samples (unit_id, session_id, ts, state, connected, amps, pilot, temp_c, temp1_c, temp2_c, temp3_c, lifetime_wh, session_wh, res, src, push_state,
			srssi, freeram, status, flags, vehicle, max_current, temp4_c, temp_max_c, session_elapsed, uptime) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'push',?,?,?,?,?,?,?,?,?,?,?)")
		->execute(array($uid, $sid, $now, $state, $conn, $r['amps'], $r['pilot'], $r['temp_c'], $r['temp1_c'], $r['temp2_c'], $r['temp3_c'], $lw, $r['session_wh'], (int)$res, $ps,
			$g('srssi'), $g('freeram') !== null ? (int)$g('freeram') : null, $g('status'), $g('flags'), $g('vehicle'), $g('max_current'), $g('temp4_c'), $g('temp_max_c'), $g('session_elapsed'), $g('uptime')));
}

function push_receive($node, $key, $raw, $ip, $now){
	$GLOBALS['SESS_LOG'] = array();
	$db = sess_db();
	$st = $db->prepare("SELECT * FROM emon_nodes WHERE node = ?"); $st->execute(array($node));
	$n = $st->fetch();
	$st->closeCursor(); unset($st);   // an unfinished SELECT pins a read snapshot; BEGIN IMMEDIATE
	                                  // would then fail at once if anyone else wrote meanwhile
	$ok_key = $n && $n['key_hash'] && $key !== '' && hash_equals($n['key_hash'], hash('sha256', $key));
	if (is_file(SESS_DIR . '/push_capture.on')) {   // raw capture; the key is never written, only whether it matched
		$line = date('Y-m-d H:i:s', $now) . " ip=$ip node=$node key=" . ($ok_key ? 'valid' : ($key === '' ? 'none' : 'INVALID')) . ' method=' . ($_SERVER['REQUEST_METHOD'] ?? 'cli') . ' json=' . substr((string)$raw, 0, 4000) . "\n";
		@file_put_contents(SESS_DIR . '/push_raw.log', $line, FILE_APPEND | LOCK_EX);
	}
	if (!$n) { slog('warn', "push from $ip for unknown node '$node' ignored"); push_flushlog(); return 'ok'; }
	if (!$ok_key) { slog('warn', "push from $ip for node $node: invalid API key"); push_flushlog(); return 'Invalid API key'; }
	$j = push_parse_json($raw);
	if ($j === null) { slog('warn', "node $node: unparsable push"); push_flushlog(); return 'Format error, json string supplied is not valid'; }
	$units = evse_units(array((int)$n['unit_id']));
	$unit = isset($units[(int)$n['unit_id']]) ? $units[(int)$n['unit_id']] : null;
	if (!$unit) { push_flushlog(); return 'ok'; }
	$uid = (int)$unit['id'];
	$kick = false; $pres = null; $wa = false;
	$lk = sess_unit_lock($uid);
	try {
		$us = $db->query("SELECT * FROM unit_state WHERE unit_id = $uid")->fetch();
		$r = push_to_reading($j, $us);
		sess_begin($db);
		$u = array('last_push_at'=>$now, 'push_count'=>($us ? (int)$us['push_count'] : 0) + 1, 'push_ip'=>$ip,
			'push_freeram'=>$r['freeram'] !== null ? (int)$r['freeram'] : null, 'push_state'=>$r['push_state']);
		if ($us && $us['push_reach'] !== null && (int)$us['push_reach'] === 0) {
			sess_event($db, $unit, $us['session_id'] ?: null, $us['segment_id'] ?: null, $now, 'reach',
				'pushes resumed after ' . sess_fmt_dur($now - (int)$us['push_reach_since']), null, null, $r['temp_c'], 'push resumed');
			slog('info', "{$unit['name']}: pushes resumed after " . sess_fmt_dur($now - (int)$us['push_reach_since']));
		}
		if (!$us || (int)$us['push_reach'] !== 1) { $u['push_reach'] = 1; $u['push_reach_since'] = $now; }
		if ($us && $r['uptime'] !== null)   // v5 sends uptime: reboots are caught on every push
			$u = array_merge($u, sess_check_reboot($db, $unit, $us, array('status_ok'=>true, 'uptime'=>$r['uptime'], 'comm_sent'=>null), $now));
		$due = $us && $us['poll_due'];
		if ($due && $us['poll_due_at'] && $now - (int)$us['poll_due_at'] > SESS_POLL_DUE_MAX_SEC) {
			slog('warn', "{$unit['name']}: no successful poll for " . sess_fmt_dur($now - (int)$us['poll_due_at']) . ' after a change, using pushes alone');
			$due = false; $u['poll_due'] = 0;
		} elseif (!$due && !push_consistent($us, $r)) {
			$due = true; $u['poll_due'] = 1; $u['poll_due_at'] = $now;
		}
		// WORKAROUND for a firmware bug in OpenEVSE ESP8266_WiFi_v2.x 2.8.2 (the 2.x WiFi modules):
		// src/input.cpp update_rapi_values() parses the $GS state token with strtol(val, NULL, 10)
		// although RAPI sends it in hex, so "fe" (sleeping), "ff" (disabled) and "0a" (over-temp)
		// are all pushed as state 0; states 1-9 are unaffected, and the $ST async-event path
		// (on_rapi_event) parses base 16 correctly.
		// https://github.com/OpenEVSE/ESP8266_WiFi_v2.x/blob/2.8.2/src/input.cpp
		// So a pushed 0 says nothing: poll the unit at once ($GS, validated, under the unit lock)
		// to read the real state. Sleeping units push 0 every ~30 s, so only when the 0 is new
		// (previous push state was not 0) or the last confirmed state isn't already 254/255/10;
		// otherwise the last confirmed state stands. The pushed 0 itself never dates a change;
		// the poll's reading does. 2.x (rapi) units only, never v5.
		// 2.9.x parses the state in hex: never applied there (push_is_29: srssi/voltage in the push).
		$wa = false;
		if ($us && $r['api'] == 'rapi' && empty($r['wifi29']) && $r['push_state'] === 0) {
			$prevps = $us['push_state'] !== null ? (int)$us['push_state'] : null;
			if ($prevps !== 0 || !in_array((int)$us['state'], array(254, 255, 10), true)) {
				$wa = true;
				if (!$due) { $due = true; $u['poll_due'] = 1; $u['poll_due_at'] = $now; }
			}
		}
		if ($due) {
			// the full poll processes this change; pushes wait so a stop reason or the
			// connection isn't guessed from the limited push fields
			if (!$us['poll_kick_at'] || $now - (int)$us['poll_kick_at'] >= 20) { $kick = true; $u['poll_kick_at'] = $now; }
		} elseif ($us && $r['ok']) {
			$r['sample_stored'] = true;
			$pres = sess_process($db, $unit, $r, $now);
		}
		// every push is stored as its own sample: ts = arrival time here, the fields as sent
		push_store_sample($db, $uid, $us, $r, $now, $pres);
		if ($us) sess_update_row($db, 'unit_state', 'unit_id', $uid, $u);
		else $kick = true;   // never seen: let the poll create the row
		sess_commit($db);
	} catch (Throwable $e) {
		if ($db->inTransaction()) sess_rollback($db);
		slog('error', "{$unit['name']}: push: " . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
	}
	sess_unit_unlock($lk);
	if ($kick) {
		// answer the unit first, then poll it in this worker (a few seconds of RAPI)
		if (function_exists('fastcgi_finish_request')) { header('Content-Length: 2'); header('Connection: close'); echo 'ok'; fastcgi_finish_request(); $GLOBALS['PUSH_ALREADY_SENT'] = true; }
		try {
			$pr = sess_poll_one($db, $unit, $wa ? 'pushed state 0 (firmware bug workaround)' : 'push saw a change');
			if ($wa) slog('info', "{$unit['name']}: pushed 0 -> poll says " . ($pr[0]['ok'] ? $pr[0]['state'] . ' (' . evse_state_text($pr[0]['state']) . ')' : 'no reading (' . $pr[0]['error'] . ')'));
		}
		catch (Throwable $e) { slog('error', "{$unit['name']}: poll after push: " . $e->getMessage()); }
	}
	push_flushlog();
	return 'ok';
}
