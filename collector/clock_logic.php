<?php
/******************************************************************************
 * clock_logic.php - the collector keeps every unit's clock on server time.
 * Included by collector.php (after session_logic.php).
 *
 * Every SESS_CLOCK_CHECK_SEC per unit: read the unit clock several times with
 * the round trip timed. A 2.x $GT reply has whole seconds, so each good read
 * gives a range for the offset: [unit - t_reply, unit + 1 - t_sent]. Reads are
 * intersected; reads that don't overlap (crossed reply, glitch) mean "no
 * trustworthy reading" and nothing is changed. The clock counts as off only
 * when the whole range is 1 s or more away from server time.
 *  - 2.x (RAPI): set with $S1 yy mm dd hh mm ss (local time, the same command
 *    the old Sync button sent), sent just before the server's second ticks over so
 *    it lands on time, then read back to confirm.
 *  - v5 with NTP: checked only. POST /time with NTP on doesn't set the clock,
 *    and turning NTP off to set it would be worse. Logged if it is off.
 *  - v5 without NTP: POST /time (as the old Sync button did).
 * At most one sync per unit per SESS_CLOCK_SYNC_MIN_SEC. Each sync -> a 'clock'
 * event + collector.log line. State in unit_state.clock_* columns.
 ******************************************************************************/
define('SESS_CLOCK_CHECK_SEC', 600);     // check each unit this often
define('SESS_CLOCK_SYNC_MIN_SEC', 600);  // never sync one unit more often than this
define('SESS_CLOCK_OFF_SEC', 1.0);       // "off" = whole measured range at least this far out
define('SESS_CLOCK_FAIL_SEC', 3.0);      // still this far off after a sync = failed (page highlights)

function clk_cols($db){
	static $done = false; if ($done) return; $done = true;
	sess_add_cols($db, 'unit_state', array('clock_checked_at'=>'INTEGER', 'clock_offset'=>'REAL', 'clock_err'=>'REAL',
		'clock_synced_at'=>'INTEGER', 'clock_sync_from'=>'REAL', 'clock_fail'=>'INTEGER DEFAULT 0', 'clock_ntp'=>'INTEGER', 'clock_note'=>'TEXT'));
}

// one read: [lo, hi, rtt] offset range in seconds (unit - server), or null
function clk_read_once($unit, $is_http){
	$t0 = microtime(true);
	if ($is_http) {
		$r = evse_http($unit, 'GET', 'time');
		$t1 = microtime(true);
		if (!$r['ok'] || !isset($r['json']['time']) || !preg_match('/^(\d{4})-(\d\d)-(\d\d)T(\d\d):(\d\d):(\d\d)Z$/', $r['json']['time'], $m)) return null;
		$ep = gmmktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
	} else {
		$p = sess_rapi($unit, '$GT');
		$t1 = microtime(true);
		$ep = ($p === null) ? null : evse_gt_parse($p);
		if ($ep === null) return null;
	}
	return array($ep - $t1, $ep + 1 - $t0, $t1 - $t0);
}

// several reads, intersected: array('off'=>est, 'lo'=>, 'hi'=>, 'rtt'=>avg, 'n'=>) or null
function clk_measure($unit, $is_http){
	$good = array();
	for ($i = 0; $i < 6 && count($good) < 3; $i++) {
		if ($i) usleep(evse_rapi_retry_delay_us());   // random 75-125 ms (see include.php)
		$x = clk_read_once($unit, $is_http);
		if ($x !== null && $x[2] < 1.5) $good[] = $x;   // ignore very slow replies
	}
	if (count($good) < 2) return null;
	$lo = max(array_column($good, 0)); $hi = min(array_column($good, 1));
	if ($lo > $hi + 0.25) return null;                 // reads disagree: don't trust any of them
	if ($lo > $hi) { $m = ($lo + $hi) / 2; $lo = $hi = $m; }
	return array('off'=>round(($lo + $hi) / 2, 2), 'lo'=>$lo, 'hi'=>$hi, 'rtt'=>array_sum(array_column($good, 2)) / count($good), 'n'=>count($good));
}

function clk_is_off($m){ return $m['lo'] >= SESS_CLOCK_OFF_SEC || $m['hi'] <= -SESS_CLOCK_OFF_SEC; }

// 2.x: $S1 with local time, sent ~one-way-latency before the server's second ticks over
function clk_set_rapi($unit, $rtt){
	$lead = min(0.4, max(0.02, $rtt / 2));
	$now = microtime(true); $target = floor($now) + 1;             // next whole second
	if ($target - $now < $lead + 0.05) $target += 1;
	usleep((int)(($target - $lead - microtime(true)) * 1e6));
	$dt = new DateTime('@' . (int)$target); $dt->setTimezone(new DateTimeZone(EVSE_LOCAL_TZ));
	$cmd = '$S1 ' . $dt->format('y n j G i s');                      // same format as evse_sync_time()
	return sess_rapi($unit, $cmd) !== null;                          // "$OK" -> '' (not null)
}

function clk_check_unit($db, $unit, $now, $force = false){
	$uid = (int)$unit['id'];
	$us = $db->query("SELECT * FROM unit_state WHERE unit_id = $uid")->fetch();
	if (!$us || !$us['reach'] && $us['reach'] !== null) return null;    // not answering: skip
	if (!$force && $us['clock_checked_at'] && $now - (int)$us['clock_checked_at'] < SESS_CLOCK_CHECK_SEC - 5) return null;
	$is_http = evse_is_http($unit);
	$ntp = null;
	if ($is_http) { $d = evse_detect($unit); if ($d['api'] == 'offline') return null; $ntp = !empty($d['config']['sntp_enabled']) ? 1 : 0; }
	$lk = sess_unit_lock($uid);
	$out = array('name'=>$unit['name'], 'api'=>$is_http ? 'v5' : 'rapi', 'ntp'=>$ntp, 'synced'=>false, 'note'=>'');
	try {
		$m = clk_measure($unit, $is_http);
		$u = array('clock_checked_at'=>$now, 'clock_ntp'=>$ntp);
		if ($m === null) {
			$u['clock_note'] = 'no consistent clock reading';
			$out['note'] = 'no consistent reading, nothing changed';
		} else {
			$out['before'] = $m['off']; $out['rtt'] = round($m['rtt'] * 1000);
			$u['clock_offset'] = $m['off']; $u['clock_err'] = round(($m['hi'] - $m['lo']) / 2, 2); $u['clock_note'] = null;
			$recent = $us['clock_synced_at'] && $now - (int)$us['clock_synced_at'] < SESS_CLOCK_SYNC_MIN_SEC;
			if (!clk_is_off($m)) {
				$u['clock_fail'] = 0;
			} elseif ($is_http && $ntp) {
				$u['clock_note'] = 'NTP clock off ' . $m['off'] . ' s (not set: unit uses NTP)';
				$out['note'] = 'off but unit uses NTP, not set';
				slog('warn', "{$unit['name']}: clock " . sprintf('%+.1f', $m['off']) . " s vs server, unit uses NTP so not set");
			} elseif ($recent) {
				$out['note'] = 'off, but synced < 10 min ago';
				$u['clock_fail'] = abs($m['off']) >= SESS_CLOCK_FAIL_SEC ? 1 : 0;
			} else {
				if ($is_http) {
					$d = isset($d) ? $d : evse_detect($unit);
					$tz = isset($d['config']['time_zone']) ? $d['config']['time_zone'] : 'UTC0';
					$r = evse_http($unit, 'POST', 'time', array('sntp_enabled'=>false, 'time_zone'=>$tz, 'time'=>gmdate('Y-m-d\TH:i:s\Z', time() + 1)));
					$ok = $r['ok'];
				} else {
					$ok = clk_set_rapi($unit, $m['rtt']);
				}
				usleep(300000);
				$m2 = $ok ? clk_measure($unit, $is_http) : null;
				$after = $m2 ? $m2['off'] : null;
				$u['clock_synced_at'] = $now; $u['clock_sync_from'] = $m['off'];
				if ($m2) { $u['clock_offset'] = $m2['off']; $u['clock_err'] = round(($m2['hi'] - $m2['lo']) / 2, 2); }
				$u['clock_fail'] = (!$ok || ($after !== null && abs($after) >= SESS_CLOCK_FAIL_SEC)) ? 1 : 0;
				$out['synced'] = $ok; $out['after'] = $after;
				$det = 'clock ' . ($ok ? 'synced' : 'sync FAILED') . ': was ' . sprintf('%+.1f', $m['off']) . ' s vs server' . ($after !== null ? ', now ' . sprintf('%+.1f', $after) . ' s' : '');
				sess_begin($db);
				sess_event($db, $unit, $us['session_id'] ?: null, $us['segment_id'] ?: null, $now, 'clock', $det, $m['off'], $after, null, 'clock sync');
				sess_commit($db);
				slog($ok ? 'info' : 'warn', "{$unit['name']}: $det (rtt " . round($m['rtt'] * 1000) . " ms)");
			}
		}
		sess_update_row($db, 'unit_state', 'unit_id', $uid, $u);
	} catch (Throwable $e) {
		if ($db->inTransaction()) sess_rollback($db);
		sess_unit_unlock($lk);
		throw $e;
	}
	sess_unit_unlock($lk);
	return $out;
}
