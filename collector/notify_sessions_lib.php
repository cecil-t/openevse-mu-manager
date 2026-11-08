<?php
/******************************************************************************
 * notify_sessions_lib.php - the two per-session emails (used by notifier.php):
 *  - Session summary  (users.n_sessions): once per plug-in, after unplug.
 *  - Charging stopped (users.n_stops):    once per plug-in, for the FIRST stop with
 *    a known cause while plugged in. Never for load-balancer pauses or for sleeps
 *    with no positive evidence of their cause (collector sess_stop_reason()).
 *
 * State lives in the notifier's notify_state.json ($st). Every change to the
 * queue is saved at once through $save (atomic write), before and after each send.
 *   $st['ns_v']        layout version (absent = baseline on this run)
 *   $st['ns_base_at']  baseline time: nothing that happened before it is mailed
 *   $st['sum_floor']   every session id <= floor is finished (summary). Raised only
 *                      over ids seen and finished in the same query (holes below a
 *                      seen id are permanent: SQLite never hands out a lower id later)
 *   $st['sum'][sid] / $st['stop'][sid]  one entry per session:
 *       to: [user ids]   chosen once (when the event is known)
 *       sent{uid:ts}  drop{uid:why}  tries{uid:n}  next{uid:ts}  att{uid:ts}
 *       fin: 1 = nothing left to do;  skip: why nothing is sent
 *       stop entries also: seg, at, reason, line, kwh, sec (the first event, kept until done)
 * Who: each login's own choice for the unit (user_unit_notify: On / Off) first, else
 * their account default (oev_notify_effective(), settings_lib.php).
 * Delivery: at least once. Each recipient is re-checked at every attempt (master
 * switch, their own setting for the unit, current address, access to the unit). A failed send is
 * retried with backoff (1, 2, 4 ... 60 min) and never dropped; turning the email
 * off (master switch or the user's setting) cancels what is still queued. If the
 * process dies after the mail server accepted a message but before "sent" is saved,
 * that recipient gets it again (no exactly-once claim).
 ******************************************************************************/
if (!defined('NS_CAR_SETTLE')) define('NS_CAR_SETTLE', 300); // "vehicle stopped drawing" must last this long, confirmed by readings
if (!defined('NS_FRESH')) define('NS_FRESH', 600);           // a validated reading this recent counts as current
if (!defined('NS_MIN_SEC')) define('NS_MIN_SEC', 60);        // "actually charged": this much charging time ...
if (!defined('NS_MIN_KWH')) define('NS_MIN_KWH', 0.01);      // ... or this much energy before the stop
if (!defined('NS_BACKOFF_MAX')) define('NS_BACKOFF_MAX', 3600);
if (!defined('NS_KEEP')) define('NS_KEEP', 2 * 86400);       // look at sessions unplugged this recently (plus any still queued)

// Write $data to $path atomically: temp file in the same folder, every byte written, flushed and fsync'd, size
// checked, then renamed over the old file. Any failure throws (the old file stays as it was) and the temp file is
// removed. The folder itself is not fsync'd (PHP cannot): after a power cut the rename may be lost, which leaves
// the previous complete file, never a partial one. $io lets tests inject failures.
function ns_write_atomic($path, $data, $io = array()){
	$io += array('fopen' => 'fopen', 'fwrite' => 'fwrite', 'fflush' => 'fflush', 'fsync' => function_exists('fsync') ? 'fsync' : null, 'fclose' => 'fclose', 'rename' => 'rename');
	$tmp = $path . '.tmp' . getmypid();
	$h = $io['fopen']($tmp, 'wb');
	if (!$h) throw new RuntimeException("state: cannot create $tmp");
	try {
		$len = strlen($data); $done = 0;
		while ($done < $len) { $w = $io['fwrite']($h, substr($data, $done)); if ($w === false || $w <= 0) throw new RuntimeException("state: write failed after $done of $len bytes"); $done += $w; }
		if ($io['fflush']($h) === false) throw new RuntimeException('state: flush failed');
		if ($io['fsync'] && $io['fsync']($h) === false) throw new RuntimeException('state: fsync failed');
		$c = $io['fclose']($h); $h = null;
		if ($c === false) throw new RuntimeException('state: close failed');
		clearstatcache(true, $tmp);
		if (filesize($tmp) !== $len) throw new RuntimeException('state: size check failed');
		if (!$io['rename']($tmp, $path)) throw new RuntimeException("state: cannot replace $path");
	} catch (Throwable $x) { if ($h) @fclose($h); @unlink($tmp); throw $x; }
}

// stop reasons (segments.stop_reason) that this email reports: each has positive evidence of its cause
function ns_known_reason($r){ return in_array($r, array('limit reached', 'schedule', 'user sleep/override', 'fault', 'thermal', 'car paused/full'), true); }

function ns_name($units, $uid){ return isset($units[$uid]) ? $units[$uid]['name'] : "unit $uid"; }
function ns_users_by_id($users){ $o = array(); foreach ($users as $u) $o[(int)$u['id']] = $u; return $o; }

// may this user get $col about unit $uid now, for an event at $at (stop time / unplug time)? '' = yes, else why not
// (oev_notify_effective() is in settings_lib.php, loaded first)
function ns_why_not($u, $units, $col, $uid, $at = null){
	if (!$u) return 'login removed';
	// this unit's own choice for this login first (user_unit_notify, $u['ovr'][unit][kind]), else the account default
	$k = ($col === 'n_stops') ? 'stops' : 'sessions';
	$ef = oev_notify_effective($u, $u['ovr'][$uid][$k] ?? null, $k);
	if (!$ef['on']) return 'turned off';
	if (empty($u['email']) || !filter_var($u['email'], FILTER_VALIDATE_EMAIL)) return 'no email address';
	// strict: an event in the same second as turning on (or earlier) is not sent; 0 = on since before start times (no limit)
	if ($ef['since'] === null || ($at !== null && $ef['since'] > 0 && $at <= $ef['since'])) return 'turned on after the ' . ($k === 'stops' ? 'stop' : 'unplug');
	$all = ($u['role'] === 'admin') || !empty($u['scope_all']);
	if (!$all) { $g = (int)($units[$uid]['loadgroupid'] ?? 0); if (!$g || !in_array($g, array_map('intval', (array)($u['groups'] ?? array())), true)) return 'no access to the unit'; }
	return '';
}
function ns_rcpt($users, $units, $col, $uid, $at = null){
	$o = array(); foreach ($users as $u) if (ns_why_not($u, $units, $col, $uid, $at) === '') $o[] = (int)$u['id']; return $o;
}

// raise the floor over ids that are in $rows (one query) and finished
function ns_advance(&$st, $rows){
	$f = (int)($st['sum_floor'] ?? 0);
	foreach ($rows as $s) { $id = (int)$s['id']; if ($id <= $f) continue; if (empty($st['sum'][$id]['fin'])) break; $f = $id; }
	$st['sum_floor'] = $f;
	foreach (array_keys($st['sum']) as $id) if ($id <= $f) unset($st['sum'][$id]);
}

// first run of this code (new install, or the update): remember where things are, mail nothing about the past
function ns_baseline(&$st, $sdb, $now, $save = null){
	if (!empty($st['ns_v'])) return false;
	$rows = $sdb->query('SELECT id, open FROM sessions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);   // one snapshot
	$st['ns_base_at'] = $now; $st['sum'] = array(); $st['stop'] = array(); $st['sum_floor'] = 0;
	foreach ($rows as $s) if (!(int)$s['open']) { $st['sum'][(int)$s['id']] = array('skip' => 'baseline', 'fin' => 1); $st['stop'][(int)$s['id']] = array('skip' => 'baseline', 'fin' => 1); }
	ns_advance($st, $rows);
	$st['ns_v'] = 2;
	if ($save) $save();
	return true;
}

// one pass over a queued entry: re-check, send what is due, save after every change. true = finished
function ns_deliver(&$e, $col, $byid, $units, $uid, $at, $on, $subject, $body, $send, $save, $now, $log){
	if (!empty($e['fin'])) return true;
	if (!$on) { $e['fin'] = 1; $e['skip'] = 'notifications turned off'; $save(); return true; }
	foreach ($e['to'] as $ru) {
		if (isset($e['sent'][$ru]) || isset($e['drop'][$ru])) continue;
		$w = ns_why_not($byid[$ru] ?? null, $units, $col, $uid, $at);
		if ($w !== '') { $e['drop'][$ru] = $w; $save(); continue; }
		if ((int)($e['next'][$ru] ?? 0) > $now) continue;
		$e['att'][$ru] = $now; $save();
		try { $r = $send($byid[$ru]['email'], $subject, $body); } catch (Throwable $x) { $r = $x->getMessage(); }
		if ($r === true) { $e['sent'][$ru] = $now; unset($e['next'][$ru]); }
		else { $n = (int)($e['tries'][$ru] ?? 0) + 1; $e['tries'][$ru] = $n; $e['next'][$ru] = $now + min(NS_BACKOFF_MAX, 60 * (1 << min($n - 1, 10))); if ($log) $log("send to user $ru failed (try $n, next in " . ($e['next'][$ru] - $now) . " s): $r"); }
		$save();
	}
	foreach ($e['to'] as $ru) if (!isset($e['sent'][$ru]) && !isset($e['drop'][$ru])) return false;
	$e['fin'] = 1; $save(); return true;
}

/* ---- Session summary (unplugged) ---- */
function ns_summaries($sdb, $users, $units, &$st, $now, $on, $send, $save, $log = null){
	$byid = ns_users_by_id($users);
	$rows = $sdb->query('SELECT * FROM sessions WHERE id > ' . (int)($st['sum_floor'] ?? 0) . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);   // one snapshot
	foreach ($rows as $s) {
		$sid = (int)$s['id']; $uid = (int)$s['unit_id'];
		if ((int)$s['open'] || !empty($st['sum'][$sid]['fin'])) continue;
		if (!isset($st['sum'][$sid])) {
			if ($s['unplug_at'] === null) $st['sum'][$sid] = array('skip' => 'no unplug time', 'fin' => 1);
			elseif (!$on) $st['sum'][$sid] = array('skip' => 'notifications off', 'fin' => 1);
			elseif ((int)$s['unplug_at'] < (int)($st['ns_base_at'] ?? 0)) $st['sum'][$sid] = array('skip' => 'before baseline', 'fin' => 1);
			else $st['sum'][$sid] = array('to' => ns_rcpt($users, $units, 'n_sessions', $uid, (int)$s['unplug_at']), 'sent' => array());
			$save();
		}
		if (!empty($st['sum'][$sid]['fin'])) continue;
		$k = ($s['kwh'] !== null) ? number_format((float)$s['kwh'], 1) . ' kWh' : 'unknown kWh';
		$subj = ns_name($units, $uid) . ": session done, $k";
		$body = ns_name($units, $uid) . "\nPlugged in: " . date('D g:i a', (int)$s['plug_in_at']) . "\nUnplugged: " . date('D g:i a', (int)$s['unplug_at']) . "\nEnergy: $k\nCharging time: " . sess_fmt_dur((int)$s['charge_seconds']);
		ns_deliver($st['sum'][$sid], 'n_sessions', $byid, $units, $uid, (int)$s['unplug_at'], $on, $subj, $body, $send, $save, $now, $log);
	}
	ns_advance($st, $rows); $save();
	// the old notifier's cursor (only read if this update is rolled back: it then sends no backlog)
	$st['session_id'] = max((int)($st['session_id'] ?? 0), (int)$sdb->query('SELECT coalesce(max(id), 0) FROM sessions WHERE open = 0')->fetchColumn());
}

// why it stopped: the reason the collector stored when it closed the charging segment (no guessing)
function ns_stop_text($sdb, $s, $seg, $kwh = 0.0, $sec = 0){
	$r = $seg['stop_reason'];
	if ($r === 'limit reached') {
		// the limit in force at the stop: the last limit the collector saw for this plug-in at or before the stop
		// (events, never the session's current fields, which a later limit overwrites). The number is shown only
		// when the energy/time measured up to the stop agrees with it.
		// A limit change logged by the reading that closed the stretch means the limit in force at the stop is not
		// proven: say "Limit reached" only. That reading is at the stop time, unless the stop was dated back to an
		// earlier push (sess_first_seen: a push sample at the stop time); then it came up to 5 min later.
		$stopt = (int)$seg['stop_at'];
		$back = $sdb->query('SELECT 1 FROM samples WHERE unit_id = ' . (int)$seg['unit_id'] . " AND src = 'push' AND ts = $stopt LIMIT 1")->fetchColumn();
		$amb = $sdb->query('SELECT 1 FROM events WHERE session_id = ' . (int)$s['id'] . " AND kind = 'limit' AND ts BETWEEN $stopt AND " . ($stopt + ($back ? 300 : 0)) . ' LIMIT 1')->fetchColumn();
		$ev = $amb ? null : $sdb->query('SELECT detail, to_val FROM events WHERE session_id = ' . (int)$s['id'] . " AND kind = 'limit' AND ts < $stopt ORDER BY ts DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
		$t = $ev ? (strpos((string)$ev['detail'], 'energy limit') === 0 ? 'energy' : (strpos((string)$ev['detail'], 'time limit') === 0 ? 'time' : null)) : null;
		$v = ($ev && $ev['to_val'] !== null) ? (float)$ev['to_val'] : null;
		$num = function($x){ return rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.'); };
		if ($t === 'energy') return array('Energy limit reached', 'The energy limit' . ($v !== null && $v > 0 && $kwh >= 0.9 * $v && $kwh <= 1.1 * $v + 0.1 ? ' (' . $num($v) . ' kWh)' : '') . ' set on the unit was reached.');
		if ($t === 'time') return array('Time limit reached', 'The time limit' . ($v !== null && $v > 0 && $sec >= 0.9 * $v * 60 && $sec <= 1.1 * $v * 60 + 120 ? ' (' . $num($v) . ' min)' : '') . ' set on the unit was reached.');
		return array('Limit reached', 'A charge limit set on the unit was reached.');
	}
	if ($r === 'schedule') return array('Schedule', "The unit's schedule ended charging.");
	if ($r === 'user sleep/override') return array('Stopped manually', 'A manual stop (override) was set on the unit (from the unit, its app or this site; who is not recorded).');
	if ($r === 'thermal') return array('Over temperature', 'The unit stopped charging because it was too hot.');
	if ($r === 'fault') {
		$e = $sdb->query('SELECT detail FROM events WHERE unit_id = ' . (int)$seg['unit_id'] . " AND kind = 'fault' AND ts BETWEEN " . ((int)$seg['stop_at'] - 180) . ' AND ' . ((int)$seg['stop_at'] + 60) . ' ORDER BY ts DESC LIMIT 1')->fetchColumn();
		return array('Fault', 'The unit reported a fault' . ($e ? ': ' . $e : '') . '.');
	}
	if ($r === 'car paused/full') return array('Vehicle stopped drawing power', 'The charger was still offering power, but the connected vehicle stopped drawing it.');
	return null;
}

// a unit restart or lost contact between two times makes "nothing changed" unproven
function ns_gap($sdb, $uid, $from, $to){
	return (bool)$sdb->query("SELECT 1 FROM events WHERE unit_id = $uid AND kind IN ('reboot', 'reach') AND ts BETWEEN $from AND $to LIMIT 1")->fetchColumn();
}

// the first stop with a known cause in this plug-in: array(segment, kwh, sec) | 'wait' | null (none, so far)
function ns_first_stop($sdb, $s, $now){
	$sid = (int)$s['id']; $uid = (int)$s['unit_id'];
	$segs = $sdb->query("SELECT * FROM segments WHERE session_id = $sid ORDER BY start_at, id")->fetchAll(PDO::FETCH_ASSOC);
	$sec = 0; $kwh = 0.0;
	foreach ($segs as $i => $g) {
		if ((int)$g['open']) return null;   // charging now; nothing qualified before it
		$sec += (int)$g['seconds']; $kwh += (float)$g['kwh'];
		if (!ns_known_reason($g['stop_reason'])) continue;   // load-balancer pause, unknown sleep, unplug: not this email, and it does not use up the plug-in
		if ($sec < NS_MIN_SEC && $kwh < NS_MIN_KWH) continue;   // no real charging yet
		$stop = (int)$g['stop_at'];
		if ($g['stop_reason'] !== 'car paused/full') return array($g, $kwh, $sec);   // cause known from the validated reading that closed it: report now
		// vehicle stopped drawing: only with unbroken evidence from the charging stretch through the confirmation.
		// Anything ambiguous is skipped like an unknown cause (it does not use up the plug-in).
		$us = $sdb->query("SELECT * FROM unit_state WHERE unit_id = $uid")->fetch(PDO::FETCH_ASSOC);
		$from = (int)$g['start_at'];
		// readings every <= 3 min while charging up to the stop: the collector counts charging time only between
		// readings at most 3 min apart, so a gap (seen or not) shows as missing seconds
		$cont = (int)$g['seconds'] >= ($stop - $from) - 180;
		// no unit restart/lost contact from the start of charging on (events, and the unit's own last boot time)
		$boot = ($us && $us['boot_at'] !== null) ? (int)$us['boot_at'] : null;
		$clean = function($to) use ($sdb, $uid, $from, $boot){ return !ns_gap($sdb, $uid, $from, $to) && ($boot === null || $boot < $from); };
		// no energy flowed after the stop (lifetime counter), so no charging was missed between readings
		// (a counter that went DOWN = reset/re-baseline: continuity not proven either)
		$flat = function($wh) use ($g){ if ($g['lifetime_end_wh'] === null || $wh === null) return false; $dw = (float)$wh - (float)$g['lifetime_end_wh']; return $dw >= 0 && $dw <= 50; };
		$next = isset($segs[$i + 1]) ? $segs[$i + 1] : null;
		if ($next) { if ($cont && (int)$next['start_at'] - $stop >= NS_CAR_SETTLE && $clean((int)$next['start_at']) && $flat($next['lifetime_start_wh'])) return array($g, $kwh, $sec); continue; }
		if (!(int)$s['open']) { if ($cont && (int)$s['unplug_at'] - $stop >= NS_CAR_SETTLE && $clean((int)$s['unplug_at']) && $flat($s['lifetime_end_wh'])) return array($g, $kwh, $sec); return null; }
		if (!$cont) return null;   // can never be proven for this stretch
		$ok = $us ? (int)$us['last_ok_at'] : 0;   // validated readings only (pushes that fail the checks do not move it)
		if (!$clean(max($ok, $now))) return null;   // restarted / lost contact: ambiguous
		if ($ok - $stop < NS_CAR_SETTLE || $now - $ok > NS_FRESH) return 'wait';
		if ((int)$us['charging'] || !(int)$us['connected'] || (int)$us['state'] !== 2) return 'wait';
		if (!$flat($us['lifetime_wh'])) return null;
		return array($g, $kwh, $sec);
	}
	return null;
}

/* ---- Charging stopped (still plugged in) ---- */
function ns_stops($sdb, $users, $units, &$st, $now, $on, $send, $save, $log = null){
	$byid = ns_users_by_id($users); $base = (int)($st['ns_base_at'] ?? 0);
	$pend = array(); foreach ($st['stop'] as $sid => $e) if (empty($e['fin'])) $pend[] = (int)$sid;
	$rows = $sdb->query('SELECT * FROM sessions WHERE open = 1 OR unplug_at >= ' . (int)($now - NS_KEEP) . ($pend ? ' OR id IN (' . implode(',', $pend) . ')' : '') . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	$live = array();
	foreach ($rows as $s) {
		$sid = (int)$s['id']; $uid = (int)$s['unit_id']; $live[$sid] = 1;
		if (!empty($st['stop'][$sid]['fin'])) continue;
		if (!isset($st['stop'][$sid])) {
			$p = ns_first_stop($sdb, $s, $now);
			if ($p === 'wait') continue;
			if ($p === null) { if (!(int)$s['open']) { $st['stop'][$sid] = array('skip' => 'no stop to report', 'fin' => 1); $save(); } continue; }
			list($g, $kwh, $sec) = $p; $txt = ns_stop_text($sdb, $s, $g, $kwh, $sec);
			if ((int)$g['stop_at'] < $base) $st['stop'][$sid] = array('skip' => 'stop before baseline', 'seg' => (int)$g['id'], 'fin' => 1);
			elseif (!$on) $st['stop'][$sid] = array('skip' => 'notifications off', 'seg' => (int)$g['id'], 'fin' => 1);
			else $st['stop'][$sid] = array('seg' => (int)$g['id'], 'at' => (int)$g['stop_at'], 'reason' => $txt[0], 'line' => $txt[1], 'kwh' => $kwh, 'sec' => $sec,
				'plug' => (int)$s['plug_in_at'], 'to' => ns_rcpt($users, $units, 'n_stops', $uid, (int)$g['stop_at']), 'sent' => array());
			$save();
			if (!empty($st['stop'][$sid]['fin'])) continue;
		}
		$e = &$st['stop'][$sid];
		$subj = ns_name($units, $uid) . ': charging stopped (' . $e['reason'] . ')';
		$body = ns_name($units, $uid) . "\nStopped: " . date('D g:i a', $e['at']) . "\nReason: " . $e['line'] . "\nPlugged in: " . date('D g:i a', $e['plug'])
			. "\nEnergy before the stop: " . number_format((float)$e['kwh'], 1) . " kWh\nCharging time before the stop: " . sess_fmt_dur((int)$e['sec'])
			. "\n\nYou get this once per plug-in. Load-balancer pauses are not reported.";
		ns_deliver($e, 'n_stops', $byid, $units, $uid, $e['at'], $on, $subj, $body, $send, $save, $now, $log);
		unset($e);
	}
	foreach (array_keys($st['stop']) as $sid) if (!isset($live[$sid]) && !empty($st['stop'][$sid]['fin'])) unset($st['stop'][$sid]);
	$save();
}
