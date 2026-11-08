<?php
// php tests/notify_sessions_test.php
// Session summary + Charging stopped emails (collector/notify_sessions_lib.php), driven both by
// hand-made rows and by the REAL collector classifier (sess_process / sess_stop_reason).
// In-memory SQLite only; the mailer is a stub (no email is sent); never opens a live DB.
$f = 0; $n = 0;
function ck($name, $ok){ global $f, $n; $n++; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; }
$tmp = __DIR__ . '/tmp/ns_' . getmypid(); @mkdir($tmp . '/data', 0700, true); @mkdir($tmp . '/collector', 0700, true);
foreach (array('sessions_lib.php', 'sess_path.php', 'volts_lib.php', 'settings_lib.php', 'secret_lib.php', 'db_path.php', 'collector/session_logic.php') as $x) copy(__DIR__ . '/../' . $x, "$tmp/$x");
require_once "$tmp/sessions_lib.php";
require_once "$tmp/settings_lib.php";
require_once "$tmp/collector/session_logic.php";
if (!function_exists('evse_state_text')) { function evse_state_text($c){ return "state $c"; } }
require_once __DIR__ . '/../collector/notify_sessions_lib.php';

function mkdb(){ $d = new PDO('sqlite::memory:'); $d->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $d->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); sess_schema($d); return $d; }
function sess($d, $unit, $plug, $open = 1, $unplug = null, $extra = array()){
	$c = array_merge(array('unit_id'=>$unit, 'unit_name'=>"U$unit", 'plug_in_at'=>$plug, 'open'=>$open, 'unplug_at'=>$unplug, 'kwh'=>1.0, 'charge_seconds'=>600, 'lifetime_end_wh'=>5000), $extra);
	$d->prepare('INSERT INTO sessions (' . implode(',', array_keys($c)) . ') VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($c));
	return (int)$d->lastInsertId();
}
// hand-made rows: lifetime counter 5000 Wh at every stop/start unless given (no energy between stretches)
function seg($d, $sid, $unit, $start, $stop, $sec, $kwh, $reason, $open = 0, $lws = 5000, $lwe = 5000){
	$d->prepare('INSERT INTO segments (session_id, unit_id, start_at, stop_at, seconds, kwh, stop_reason, open, lifetime_start_wh, lifetime_end_wh) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute(array($sid, $unit, $start, $stop, $sec, $kwh, $reason, $open, $lws, $lwe));
}
function us($d, $unit, $ok, $charging = 0, $conn = 1, $state = 2, $push = null, $lw = 5000, $boot = null){
	$d->prepare('INSERT OR REPLACE INTO unit_state (unit_id, last_ok_at, charging, connected, state, last_push_at, lifetime_wh, boot_at) VALUES (?,?,?,?,?,?,?,?)')->execute(array($unit, $ok, $charging, $conn, $state, $push, $lw, $boot));
}
function limev($d, $sid, $ts, $type, $v){ $d->prepare("INSERT INTO events (unit_id, session_id, ts, kind, detail, to_val, reason) VALUES (4, ?, ?, 'limit', ?, ?, 'limit set')")->execute(array($sid, $ts, "$type limit $v" . ($type == 'time' ? ' min' : ' kWh'), $v)); }
$units = array(4 => array('name'=>'N Penn: 1F', 'loadgroupid'=>1), 5 => array('name'=>'Other: 9X', 'loadgroupid'=>2), 2 => array('name'=>'Loose', 'loadgroupid'=>null));
$T0 = 1790000000;
$USERS0 = array(
	array('id'=>1, 'email'=>'admin@example.test', 'role'=>'admin', 'scope_all'=>1, 'n_sessions'=>1, 'n_stops'=>1, 'n_stops_since'=>$T0 - 99999, 'groups'=>array()),
	array('id'=>2, 'email'=>'viewer2@example.test', 'role'=>'viewer', 'scope_all'=>0, 'n_sessions'=>1, 'n_stops'=>1, 'n_stops_since'=>$T0 - 99999, 'groups'=>array(2)),
	array('id'=>3, 'email'=>'off@example.test', 'role'=>'admin', 'scope_all'=>1, 'n_sessions'=>0, 'n_stops'=>0, 'n_stops_since'=>null, 'groups'=>array()),
);
$users = $USERS0;
$OUT = array(); $FAIL = array(); $HOOK = null; $SAVES = 0;
$send = function($to, $subj, $body) use (&$OUT, &$FAIL, &$HOOK){ if ($HOOK) { $h = $HOOK; $h($to, $subj); } if (!empty($FAIL[$to])) { $FAIL[$to]--; return 'smtp down'; } $OUT[] = array($to, $subj, $body); return true; };
$save = function() use (&$SAVES){ $SAVES++; };
function run($d, &$st, $now, $on = true){ global $users, $units, $send, $save; ns_baseline($st, $d, $now, $save); ns_stops($d, $users, $units, $st, $now, $on, $send, $save); ns_summaries($d, $users, $units, $st, $now, $on, $send, $save); }
function got($pat){ global $OUT; return count(array_filter($OUT, function($m) use ($pat){ return preg_match($pat, $m[1]); })); }
function fresh(){ global $OUT, $FAIL, $HOOK, $users, $USERS0; $OUT = array(); $FAIL = array(); $HOOK = null; $users = $USERS0; }

// real collector flow: feed readings through sess_process (the code that stores stop reasons)
$unitA = array('id'=>4, 'name'=>'N Penn: 1F', 'breaker'=>48, 'evseurl'=>'x', 'volts_override'=>null, 'voltmeter'=>null);
function rd($api, $st, $x = array()){ return array_merge(array('ok'=>true, 'api'=>$api, 'state'=>$st, 'connected'=>$st == 1 ? 0 : 1, 'charging'=>$st == 3 ? 1 : 0, 'amps'=>$st == 3 ? 16 : 0,
	'pilot'=>16, 'cmax'=>null, 'temp_c'=>25, 'lifetime_wh'=>null, 'session_wh'=>null, 'volts'=>240, 'elapsed'=>null, 'vflags'=>null, 'limit_sleep'=>false, 'limit'=>false, 'timer_active'=>null,
	'override'=>null, 'our_claim'=>null, 'sched_disabled'=>null, 'gfci'=>0, 'nognd'=>0, 'stuck'=>0, 'error'=>'', 'temp1_c'=>null, 'temp2_c'=>null, 'temp3_c'=>null, 'src'=>'poll'), $x); }
// plug in at $t, charge 20 min (0.5 kWh/min), then $stop reading; returns the lifetime counter
function charge($d, $api, $t, $stop, &$lw, $from_plug = true){ global $unitA;
	if ($from_plug) sess_process($d, $unitA, rd($api, 2, array('lifetime_wh'=>$lw)), $t);
	for ($i = 1; $i <= 20; $i++) sess_process($d, $unitA, rd($api, 3, array('lifetime_wh'=>$lw += 500)), $t + 60 * $i);
	sess_process($d, $unitA, rd($api, $stop[0], array('lifetime_wh'=>$lw) + $stop[1]), $t + 1260);
	return $t + 1260;
}
function lastreason($d){ return $d->query('SELECT stop_reason FROM segments ORDER BY id DESC LIMIT 1')->fetchColumn(); }

/* ===== classifier (real sess_process) ===== */
$cls = array(
	'RAPI load-balancer pause ($FD -> 255), nothing else known' => array('rapi', 255, array(), 'disabled (cause unknown)', false),
	'RAPI 255 with a timer set (still could be the LB $FD)' => array('rapi', 255, array('timer_active'=>1), 'disabled (cause unknown)', false),
	'RAPI 254 with a timer set' => array('rapi', 254, array('timer_active'=>1), 'schedule', true),
	'v5 LB pause (our claim disabled)' => array('http', 254, array('our_claim'=>array('client'=>1213399041, 'state'=>'disabled')), 'load management', false),
	'v5 sleep, no claim/override/schedule/limit' => array('http', 254, array(), 'sleep (cause unknown)', false),
	'v5 disabled 255, unclaimed' => array('http', 255, array(), 'disabled (cause unknown)', false),
	'v5 override that only sets current' => array('http', 254, array('override'=>array('charge_current'=>10)), 'sleep (cause unknown)', false),
	'v5 manual override disabled' => array('http', 254, array('override'=>array('state'=>'disabled', 'auto_release'=>true)), 'user sleep/override', true),
	'v5 LB claim + manual override: LB wins' => array('http', 254, array('our_claim'=>array('client'=>1213399041, 'state'=>'disabled'), 'override'=>array('state'=>'disabled')), 'load management', false),
	'v5 energy limit sleep' => array('http', 254, array('limit_sleep'=>true, 'limit'=>array('type'=>'energy', 'value'=>2)), 'limit reached', true),
	'v5 schedule plan disabled' => array('http', 254, array('sched_disabled'=>true), 'schedule', true),
	'vehicle stopped drawing (state 2)' => array('http', 2, array(), 'car paused/full', true),
	'fault (GFCI state 7)' => array('http', 7, array(), 'fault', true),
	'over temperature (state 10)' => array('http', 10, array(), 'thermal', true),
);
foreach ($cls as $label => $c) {
	$d = mkdb(); $lw = 1000000; $at = charge($d, $c[0], $T0, array($c[1], $c[2]), $lw);
	ck("classifier: $label -> " . $c[3], lastreason($d) === $c[3]);
	ck("classifier: $label -> " . ($c[4] ? 'reportable' : 'not reported'), ns_known_reason(lastreason($d)) === $c[4]);
}

/* ===== real flow: LB pause does not send and does not use up the plug-in; then a real limit does ===== */
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10); $lw = 1000000;
$at = charge($d, 'rapi', $T0, array(255, array()), $lw);                // LB $FD pause after charging
sess_process($d, $unitA, rd('rapi', 255, array('lifetime_wh'=>$lw)), $at + 60);
run($d, $st, $at + 60); run($d, $st, $at + 900);
ck('flow: RAPI LB pause (255) -> no email, not "Stopped manually"', count($OUT) === 0);
$at2 = charge($d, 'http', $at + 1000, array(254, array('limit_sleep'=>true, 'limit'=>array('type'=>'energy', 'value'=>2))), $lw, false);
run($d, $st, $at2 + 30);
ck('flow: later limit stop -> one prompt email (next pass, no 5-min wait); limit first seen in the stop reading -> "Limit reached"', got('/charging stopped \(Limit reached\)$/') === 1 && count($OUT) === 1);
$at3 = charge($d, 'http', $at2 + 120, array(2, array()), $lw, false);   // resumes 2 min later, car stops
run($d, $st, $at3 + 900); ck('flow: second stop same plug-in -> nothing more', count($OUT) === 1);

/* ===== explicit limit then resume in 120 s / unplug in 60 s: first event still reported ===== */
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$s = sess($d, 4, $T0, 1, null, array('limit_type'=>'energy', 'limit_value'=>2));
limev($d, $s, $T0 + 10, 'energy', 2); seg($d, $s, 4, $T0 + 10, $T0 + 3000, 2990, 2, 'limit reached'); seg($d, $s, 4, $T0 + 3120, $T0 + 3600, 480, 0.3, 'car paused/full');
us($d, 4, $T0 + 3700); run($d, $st, $T0 + 3700);
ck('limit then resume after 120 s: the limit is reported (not the later vehicle stop)', got('/Energy limit reached/') === 1 && got('/Vehicle stopped/') === 0);
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$s = sess($d, 4, $T0, 0, $T0 + 3060, array('limit_type'=>'time', 'limit_value'=>50)); limev($d, $s, $T0 + 10, 'time', 50); seg($d, $s, 4, $T0 + 10, $T0 + 3000, 2990, 2, 'limit reached');
us($d, 4, $T0 + 3060, 0, 0, 1); run($d, $st, $T0 + 3090);
ck('limit then unplug 60 s later, before the mailer ran: stop email still sent', got('/Time limit reached/') === 1);
ck('... and the summary too', got('/session done/') === 1);
ck('stop email goes out before the summary', strpos($OUT[0][1], 'charging stopped') !== false);

/* ===== vehicle stopped drawing: validated readings only, restart guard ===== */
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$s = sess($d, 4, $T0); seg($d, $s, 4, $T0 + 10, $T0 + 3000, 2990, 4, 'car paused/full');
us($d, 4, $T0 + 3000, 0, 1, 2, $T0 + 3500);   // pushes arriving, last validated reading at the stop
run($d, $st, $T0 + 3500); ck('fresh push receipt but no validated reading after the stop: no email', count($OUT) === 0);
us($d, 4, $T0 + 3400, 0, 1, 2); $d->exec("INSERT INTO events (unit_id, ts, kind, detail) VALUES (4, " . ($T0 + 3200) . ", 'reboot', 'WiFi module restarted')");
run($d, $st, $T0 + 3450); ck('unit restarted after the stop: "still stopped" not proven, no email', count($OUT) === 0);
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$s = sess($d, 4, $T0); seg($d, $s, 4, $T0 + 10, $T0 + 3000, 2990, 4, 'car paused/full');
us($d, 4, $T0 + 3200, 0, 1, 2); run($d, $st, $T0 + 3200); ck('vehicle stop: waits for 5 min of validated readings', count($OUT) === 0);
us($d, 4, $T0 + 3310, 0, 1, 2); run($d, $st, $T0 + 3320);
ck('vehicle stop confirmed: "Vehicle stopped drawing power", never full/100%', got('/Vehicle stopped drawing power/') === 1 && stripos($OUT[0][2], 'full') === false && strpos($OUT[0][2], '100') === false);
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$s = sess($d, 4, $T0); seg($d, $s, 4, $T0 + 10, $T0 + 3000, 2990, 4, 'car paused/full'); seg($d, $s, 4, $T0 + 3060, $T0 + 4000, 940, 1, 'schedule');
us($d, 4, $T0 + 4100, 0, 1, 254); run($d, $st, $T0 + 4100);
ck('vehicle blip (restarted in 60 s) skipped; first real stop = schedule', got('/charging stopped \(Schedule\)/') === 1 && count($OUT) === 1);
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$s = sess($d, 4, $T0); seg($d, $s, 4, $T0 + 10, $T0 + 3000, 2990, 4, 'car paused/full'); us($d, 4, $T0 + 3000, 0, 1, 2);
run($d, $st, $T0 + 3000 + 2000); ck('no data at all after the stop (offline): no email', count($OUT) === 0);
$d->exec("UPDATE sessions SET open = 0, unplug_at = " . ($T0 + 3100) . " WHERE id = $s"); run($d, $st, $T0 + 5100);
ck('unplugged 100 s after a vehicle stop: summary only', got('/charging stopped/') === 0 && got('/session done/') === 1);

/* ===== no charging / unplug while charging ===== */
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$s = sess($d, 4, $T0); seg($d, $s, 4, $T0 + 10, $T0 + 20, 10, 0, 'user sleep/override'); us($d, 4, $T0 + 100, 0, 1, 254);
run($d, $st, $T0 + 100); ck('no real charging before the stop: no email', count($OUT) === 0);
$s = sess($d, 2, $T0, 0, $T0 + 900); seg($d, $s, 2, $T0 + 20, $T0 + 900, 880, 1, 'unplugged'); run($d, $st, $T0 + 960);
ck('unplugged while charging: summary only', got('/Loose: session done/') === 1 && got('/Loose: charging stopped/') === 0);

/* ===== summaries: baseline, out of order, snapshot race ===== */
fresh(); $d = mkdb(); $st = array();
$a = sess($d, 4, $T0 - 9000, 0, $T0 - 8000); $b = sess($d, 4, $T0 - 7000, 1); seg($d, $b, 4, $T0 - 6900, $T0 - 6000, 900, 3, 'schedule');
$c = sess($d, 5, $T0 - 5000, 0, $T0 - 4000); us($d, 4, $T0, 0, 1, 254);
run($d, $st, $T0); ck('baseline: nothing about the past', count($OUT) === 0 && $st['sum_floor'] === $b - 1);
$d->exec("UPDATE sessions SET open = 0, unplug_at = " . ($T0 + 100) . " WHERE id = $b"); run($d, $st, $T0 + 160);
ck('older session closed after a newer one: summary sent', got('/1F: session done/') === 1);
ck('its stop happened before the baseline: no stop email', got('/charging stopped/') === 0);
// the reviewer's race: while mailing, another session closes (new id); it must still be mailed later
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$x = sess($d, 4, $T0, 0, $T0 + 100); $y = sess($d, 4, $T0 + 200, 0, $T0 + 300);
$HOOK = function($to, $subj) use ($d, $T0, &$HOOK){ $HOOK = null; sess($d, 5, $T0 + 400, 0, $T0 + 450); };
run($d, $st, $T0 + 460);
ck('race: session closed during the mailing pass is not skipped by the floor', (int)$st['sum_floor'] === $y);
run($d, $st, $T0 + 520); ck('race: it is mailed on the next pass (3 summaries, no repeats)', got('/session done/') === 4);   // 1F x2 (admin), 9X x2 (admin + viewer2)

/* ===== recipients re-checked at every attempt ===== */
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$s = sess($d, 4, $T0); seg($d, $s, 4, $T0 + 10, $T0 + 3000, 2990, 2, 'schedule'); us($d, 4, $T0 + 3010, 0, 1, 254);
$FAIL = array('admin@example.test' => 1); run($d, $st, $T0 + 3010);
run($d, $st, $T0 + 3100, false); ck('failed once, then master switch off: never sent', count($OUT) === 0 && !empty($st['stop'][$s]['fin']));
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$s = sess($d, 4, $T0); seg($d, $s, 4, $T0 + 10, $T0 + 3000, 2990, 2, 'schedule'); us($d, 4, $T0 + 3010, 0, 1, 254);
$FAIL = array('admin@example.test' => 1); run($d, $st, $T0 + 3010);
$users[0]['n_stops'] = 0; run($d, $st, $T0 + 3100); ck('failed once, then the user turned it off: never sent', count($OUT) === 0 && $st['stop'][$s]['drop'][1] === 'turned off');
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$s = sess($d, 4, $T0); seg($d, $s, 4, $T0 + 10, $T0 + 3000, 2990, 2, 'schedule'); us($d, 4, $T0 + 3010, 0, 1, 254);
$FAIL = array('admin@example.test' => 1); run($d, $st, $T0 + 3010);
$users[0]['email'] = 'admin-new@example.test'; run($d, $st, $T0 + 3100); ck('address changed before the retry: goes to the current address', count($OUT) === 1 && $OUT[0][0] === 'admin-new@example.test');
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$s = sess($d, 5, $T0); seg($d, $s, 5, $T0 + 10, $T0 + 3000, 2990, 2, 'schedule'); us($d, 5, $T0 + 3010, 0, 1, 254);
$FAIL = array('viewer2@example.test' => 1); run($d, $st, $T0 + 3010);
$users[1]['groups'] = array(1); run($d, $st, $T0 + 3100); ck('viewer lost access to the unit before the retry: not sent', count(array_filter($OUT, function($m){ return $m[0] === 'viewer2@example.test'; })) === 0);
array_splice($users, 0, 1);
$s2 = sess($d, 5, $T0 + 5000, 0, $T0 + 6000); run($d, $st, $T0 + 6060); ck('removed login: nothing queued for them', count(array_filter($OUT, function($m){ return $m[0] === 'admin@example.test' && strpos($m[1], 'session done') !== false; })) === 0);

/* ===== failed sends: kept, backed off, delivered after recovery ===== */
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$s = sess($d, 4, $T0); seg($d, $s, 4, $T0 + 10, $T0 + 3000, 2990, 2, 'schedule'); us($d, 4, $T0 + 3010, 0, 1, 254);
$FAIL = array('admin@example.test' => 5); $tries = 0; $t = $T0 + 3010;
for ($i = 0; $i < 200; $i++, $t += 60) { $before = (int)($st['stop'][$s]['tries'][1] ?? 0); run($d, $st, $t); if ((int)($st['stop'][$s]['tries'][1] ?? 0) > $before) $tries++; }
ck('5 failed sends: still queued, retried with backoff (5 tries in 200 min, not 200)', $tries === 5);
ck('after the mail server recovers: delivered exactly once', got('/charging stopped/') === 1 && !empty($st['stop'][$s]['fin']));

/* ===== crash safety: progress is saved per recipient ===== */
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$s = sess($d, 5, $T0); seg($d, $s, 5, $T0 + 10, $T0 + 3000, 2990, 2, 'schedule'); us($d, 5, $T0 + 3010, 0, 1, 254);
$SNAP = null; $save = function() use (&$st, &$SNAP){ $SNAP = json_encode($st); };
$HOOK = function($to) use (&$OUT){ if (count($OUT) === 1) throw new Exception('process killed'); };
try { run($d, $st, $T0 + 3010); } catch (Exception $e) {}
$st = json_decode($SNAP, true); $HOOK = null;     // restart from what was on disk
run($d, $st, $T0 + 3070);
$to = array_count_values(array_column(array_filter($OUT, function($m){ return strpos($m[1], 'charging stopped') !== false; }), 0));
ck('killed after the 1st recipient: on restart only the 2nd is mailed', ($to['admin@example.test'] ?? 0) === 1 && ($to['viewer2@example.test'] ?? 0) === 1);
$save = function() use (&$SAVES){ $SAVES++; };

/* ===== vehicle stop: continuity from the last charging reading (real sess_process + sess_check_reboot) ===== */
// one collector poll as collector.php does it: sess_process, then the reboot check on the same reading
function poll($d, $r, $t){ global $unitA; $r += array('status_ok'=>true); sess_process($d, $unitA, $r, $t);
	$us = $d->query('SELECT * FROM unit_state WHERE unit_id = 4')->fetch(); $u = sess_check_reboot($d, $unitA, $us, $r, $t); $u['last_full_poll_at'] = $t; sess_update_row($d, 'unit_state', 'unit_id', 4, $u); }
function cflow($d, $t, $opt){   // plug, charge every 60 s to t+1200 (opt gap: no readings in (gapfrom, gapto)), stop (state 2), stopped readings
	$lw = 1000000; $boot = $t - 10000; $last = null;
	poll($d, rd('http', 2, array('lifetime_wh'=>$lw, 'uptime'=>$t - $boot)), $t);
	for ($x = 60; $x <= 1200; $x += 60) {
		if (isset($opt['gap']) && $x > $opt['gap'][0] && $x < $opt['gap'][1]) continue;
		poll($d, rd('http', 3, array('lifetime_wh'=>$lw += 60, 'uptime'=>$t + $x - $boot)), $t + $x);
	}
	if (!empty($opt['reboot'])) { $boot = $t + 1225; poll($d, rd('http', 3, array('lifetime_wh'=>$lw += 30, 'uptime'=>5)), $t + 1230); }
	$stop = $opt['stop_at'] ?? 1260;
	for ($x = $stop; $x <= ($opt['until'] ?? 1700); $x += 60) poll($d, rd('http', 2, array('lifetime_wh'=>$lw, 'uptime'=>$t + $x - $boot)), $t + $x);
}
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
cflow($d, $T0, array('until'=>1380));
run($d, $st, $T0 + 1400); ck('real flow: vehicle stop not yet 5 min old: no email', count($OUT) === 0);
for ($x = 1440; $x <= 1680; $x += 60) poll($d, rd('http', 2, array('lifetime_wh'=>1000000 + 20 * 60, 'uptime'=>10000 + $x)), $T0 + $x);
run($d, $st, $T0 + 1700); ck('real flow: continuous charging -> stop -> 5 min of stopped readings: "Vehicle stopped drawing power"', got('/Vehicle stopped drawing power/') === 1);
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
cflow($d, $T0, array('reboot'=>1));
ck('real flow: the collector recorded the restart (t+1230)', (int)$d->query("SELECT count(*) FROM events WHERE kind = 'reboot'")->fetchColumn() === 1);
run($d, $st, $T0 + 1700); ck('real flow: restart just before the stop (t+1230), stop t+1260, stopped to t+1620+: no vehicle email', count($OUT) === 0);
poll($d, rd('http', 1, array('lifetime_wh'=>1000000 + 20 * 60 + 30, 'uptime'=>600)), $T0 + 1800); run($d, $st, $T0 + 1860);
ck('... and after unplug: summary only', got('/session done/') === 1 && got('/charging stopped/') === 0);
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
cflow($d, $T0, array('gap'=>array(600, 1300), 'stop_at'=>1500, 'until'=>1900));   // no readings 660..1240, first reading after: stopped at t+1500
ck('real flow: unobserved gap left no restart/lost-contact event (nothing to rely on)', (int)$d->query("SELECT count(*) FROM events WHERE kind IN ('reboot', 'reach')")->fetchColumn() === 0);
run($d, $st, $T0 + 1900); ck('real flow: unobserved gap before the stop: no vehicle email', count($OUT) === 0);
// ambiguous stretch does not use up the plug-in: a later known stop is still reported
poll($d, rd('http', 3, array('lifetime_wh'=>1000000 + 20 * 60, 'uptime'=>99999)), $T0 + 2000);
for ($x = 2060; $x <= 2400; $x += 60) poll($d, rd('http', 3, array('lifetime_wh'=>1000000 + 20 * 60 + ($x - 2000), 'uptime'=>99999)), $T0 + $x);
poll($d, rd('http', 254, array('lifetime_wh'=>1000000 + 20 * 60 + 460, 'override'=>array('state'=>'disabled'), 'uptime'=>99999)), $T0 + 2460);
run($d, $st, $T0 + 2500); ck('ambiguous vehicle stop not used up: later manual stop reported', got('/charging stopped \(Stopped manually\)/') === 1 && count($OUT) === 1);

fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$s = sess($d, 4, $T0); seg($d, $s, 4, $T0 + 10, $T0 + 3000, 2990, 4, 'car paused/full');
us($d, 4, $T0 + 3400, 0, 1, 2, null, 5600); run($d, $st, $T0 + 3410);
ck('energy counted after the vehicle stop with no charging seen (missed stretch): no email', count($OUT) === 0);
us($d, 4, $T0 + 3400, 0, 1, 2, null, 5000); run($d, $st, $T0 + 3420); ck('same with the counter flat: email', got('/Vehicle stopped/') === 1);

/* ===== limit details come from the stop, not from later changes (real sess_process) ===== */
function lflow($d, $t, $lim1, $lim2, $mins){ global $unitA;   // 60 Wh a minute (16 A at 240 V is ~64), $mins minutes, then the limit stop
	$lw = 1000000; $e = $t + 60 * ($mins + 1);
	sess_process($d, $unitA, rd('http', 2, array('lifetime_wh'=>$lw, 'limit'=>$lim1)), $t);
	for ($x = 1; $x <= $mins; $x++) sess_process($d, $unitA, rd('http', 3, array('lifetime_wh'=>$lw += 60, 'limit'=>$lim1)), $t + 60 * $x);
	sess_process($d, $unitA, rd('http', 254, array('lifetime_wh'=>$lw, 'limit'=>$lim1, 'limit_sleep'=>true)), $e);
	sess_process($d, $unitA, rd('http', 2, array('lifetime_wh'=>$lw, 'limit'=>$lim2)), $e + 60);          // new limit set, charging resumes
	for ($x = 2; $x <= 5; $x++) sess_process($d, $unitA, rd('http', 3, array('lifetime_wh'=>$lw += 60, 'limit'=>$lim2)), $e + 60 * $x);
	return $e + 360;
}
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$te = lflow($d, $T0, array('type'=>'energy', 'value'=>2), array('type'=>'energy', 'value'=>20), 34);
ck('real flow: session row now says 20 kWh (the later limit)', (float)$d->query('SELECT limit_value FROM sessions')->fetchColumn() === 20.0);
run($d, $st, $te + 20); ck('limit 2 kWh reached, then 20 kWh set and charging again before the mailer: email says 2 kWh', got('/Energy limit reached/') === 1 && strpos($OUT[0][2], '(2 kWh)') !== false && strpos($OUT[0][2], '20') === false);
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$te = lflow($d, $T0, array('type'=>'energy', 'value'=>2), array('type'=>'time', 'value'=>90), 34);
run($d, $st, $te + 20); ck('energy limit reached, then a time limit set: email says energy limit (2 kWh)', got('/charging stopped \(Energy limit reached\)/') === 1 && strpos($OUT[0][2], '(2 kWh)') !== false && stripos($OUT[0][2], 'time limit') === false);
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$te = lflow($d, $T0, array('type'=>'energy', 'value'=>2), array('type'=>'energy', 'value'=>20), 170);   // 10.2 kWh measured: does not match 2
run($d, $st, $te + 20); ck('measured energy disagrees with the limit seen: cause given, no number', got('/Energy limit reached/') === 1 && strpos($OUT[0][2], 'kWh)') === false);
$st2 = $st; run($d, $st, $te + 100); ck('first stop cause kept (no second email, entry unchanged)', count($OUT) === 1 && $st2['stop'] == $st['stop']);

// same-second limit change at the stop: the cause type/number is not proven -> "Limit reached" only
function sflow($d, $t, $before, $atstop){ global $unitA; $lw = 1000000;
	sess_process($d, $unitA, rd('http', 2, array('lifetime_wh'=>$lw, 'limit'=>$before)), $t);
	for ($x = 1; $x <= 35; $x++) sess_process($d, $unitA, rd('http', 3, array('lifetime_wh'=>$lw += 60, 'limit'=>$before)), $t + 60 * $x);
	sess_process($d, $unitA, rd('http', 254, array('lifetime_wh'=>$lw, 'limit'=>$atstop, 'limit_sleep'=>true)), $t + 2160);   // stop reading already shows the new limit
	return $t + 2160;
}
foreach (array('energy 2 kWh -> time 35 min' => array(array('type'=>'energy', 'value'=>2), array('type'=>'time', 'value'=>35)),
		'time 35 min -> energy 2 kWh' => array(array('type'=>'time', 'value'=>35), array('type'=>'energy', 'value'=>2)),
		'energy 2 -> energy 3 kWh' => array(array('type'=>'energy', 'value'=>2), array('type'=>'energy', 'value'=>3))) as $label => $L) {
	fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
	$te = sflow($d, $T0, $L[0], $L[1]);
	ck("real flow: limit changed in the stop reading ($label): the collector logged it at the stop second", (int)$d->query("SELECT count(*) FROM events WHERE kind = 'limit' AND ts = $te")->fetchColumn() === 1);
	run($d, $st, $te + 30);
	ck("same-second limit change ($label): \"Limit reached\" only, no type or number", got('/charging stopped \(Limit reached\)$/') === 1 && !preg_match('/kWh\)|min\)|energy limit|time limit/i', $OUT[0][2]));
}
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$s = sess($d, 4, $T0); limev($d, $s, $T0 + 5, 'energy', 2); limev($d, $s, $T0 + 900, 'energy', 2.2);
seg($d, $s, 4, $T0 + 10, $T0 + 2000, 1990, 2.1, 'limit reached'); limev($d, $s, $T0 + 2100, 'time', 30); us($d, 4, $T0 + 2200, 0, 1, 254);
$d->exec("INSERT INTO samples (unit_id, session_id, ts, state, src) VALUES (4, $s, " . ($T0 + 2000) . ", 254, 'push')");   // stop dated back to a push
run($d, $st, $T0 + 2200); ck('stop dated back to a push, limit changed 100 s later (could be the closing reading): generic', got('/charging stopped \(Limit reached\)$/') === 1);
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$s = sess($d, 4, $T0); limev($d, $s, $T0 + 5, 'energy', 2); seg($d, $s, 4, $T0 + 10, $T0 + 2000, 1990, 2.1, 'limit reached'); limev($d, $s, $T0 + 2100, 'time', 30); us($d, 4, $T0 + 2200, 0, 1, 254);
run($d, $st, $T0 + 2200); ck('stop seen by a poll at its own time, limit changed 100 s later: the earlier energy limit is proven', got('/Energy limit reached/') === 1 && strpos($OUT[0][2], '(2 kWh)') !== false);
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$s = sess($d, 4, $T0); limev($d, $s, $T0 + 5, 'time', 30); limev($d, $s, $T0 + 900, 'energy', 2);
seg($d, $s, 4, $T0 + 10, $T0 + 2000, 1990, 2.05, 'limit reached'); us($d, 4, $T0 + 2100, 0, 1, 254);
run($d, $st, $T0 + 2100); ck('several limits before the stop, none at it: the last one (energy 2 kWh, matches the energy)', got('/Energy limit reached/') === 1 && strpos($OUT[0][2], '(2 kWh)') !== false);

// counter went DOWN after the stop (reset / re-baseline): continuity not proven, at confirmation, resume and unplug
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
cflow($d, $T0, array('until'=>1260));
for ($x = 1320; $x <= 1740; $x += 60) poll($d, rd('http', 2, array('lifetime_wh'=>200, 'uptime'=>10000 + $x)), $T0 + $x);
ck('real flow: collector re-baselined the counter to 200 Wh', (float)$d->query('SELECT lifetime_wh FROM unit_state')->fetchColumn() === 200.0);
run($d, $st, $T0 + 1760); ck('counter re-baselined lower after a vehicle stop: no email (confirmation)', count($OUT) === 0);
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$s = sess($d, 4, $T0); seg($d, $s, 4, $T0 + 10, $T0 + 3000, 2990, 4, 'car paused/full'); seg($d, $s, 4, $T0 + 3600, $T0 + 4000, 400, 0.3, 'schedule', 0, 1000, 1300);
us($d, 4, $T0 + 4100, 0, 1, 254, null, 1300); run($d, $st, $T0 + 4100);
ck('counter lower at the next charging start: vehicle stop not proven, the later schedule stop is reported', got('/Vehicle stopped/') === 0 && got('/charging stopped \(Schedule\)/') === 1);
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$s = sess($d, 4, $T0, 0, $T0 + 4000, array('lifetime_end_wh'=>100)); seg($d, $s, 4, $T0 + 10, $T0 + 3000, 2990, 4, 'car paused/full');
run($d, $st, $T0 + 4060); ck('counter lower at unplug: no vehicle email, summary only', got('/charging stopped/') === 0 && got('/session done/') === 1);

/* ===== durable state writer: every failure throws, old file intact, nothing sent after a failed save ===== */
$sp = $tmp . '/state.json'; file_put_contents($sp, '{"good":1}');
$inj = array(
	'write returns 0' => array('fwrite' => function($h, $x){ return 0; }),
	'write returns false' => array('fwrite' => function($h, $x){ return false; }),
	'flush fails' => array('fflush' => function($h){ return false; }),
	'fsync fails' => array('fsync' => function($h){ return false; }),
	'close fails' => array('fclose' => function($h){ fclose($h); return false; }),
	'rename fails' => array('rename' => function($a, $b){ return false; }),
	'cannot create temp' => array('fopen' => function($p, $m){ return false; }),
);
foreach ($inj as $label => $io) {
	$thrown = false; try { ns_write_atomic($sp, '{"new":2}', $io); } catch (RuntimeException $x) { $thrown = true; }
	ck("state writer: $label -> error, old file intact, no temp left", $thrown && file_get_contents($sp) === '{"good":1}' && !glob("$sp.tmp*"));
}
ns_write_atomic($sp, str_repeat('x', 100000), array('fwrite' => function($h, $x){ return fwrite($h, substr($x, 0, 777)); }));
ck('state writer: short writes are completed', file_get_contents($sp) === str_repeat('x', 100000));
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$s = sess($d, 4, $T0); seg($d, $s, 4, $T0 + 10, $T0 + 3000, 2990, 2, 'schedule'); us($d, 4, $T0 + 3010, 0, 1, 254);
$save = function(){ throw new RuntimeException('disk full'); }; $thrown = false;
try { run($d, $st, $T0 + 3010); } catch (RuntimeException $x) { $thrown = true; }
ck('queue cannot be saved: run stops before any email', $thrown && count($OUT) === 0);
$save = function() use (&$SAVES){ $SAVES++; };

/* ===== settings timing, notifications off, rollback cursor, migration ===== */
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$users[0]['n_stops_since'] = $T0 + 3005;
$s = sess($d, 4, $T0); seg($d, $s, 4, $T0 + 10, $T0 + 3000, 2990, 2, 'schedule'); us($d, 4, $T0 + 3010, 0, 1, 254);
run($d, $st, $T0 + 3010); ck('turned on after the stop: no old email', count($OUT) === 0);
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$s = sess($d, 4, $T0); seg($d, $s, 4, $T0 + 10, $T0 + 3000, 2990, 2, 'schedule'); us($d, 4, $T0 + 3010, 0, 1, 254);
run($d, $st, $T0 + 3010, false); run($d, $st, $T0 + 3100, true); ck('notifications off at the time: nothing later', count($OUT) === 0);
fresh(); $d = mkdb(); $st = array(); run($d, $st, $T0 - 10);
$users = array_map(function($u){ unset($u['n_stops'], $u['n_stops_since']); return $u; }, $users);
$s = sess($d, 4, $T0, 0, $T0 + 900); seg($d, $s, 4, $T0 + 10, $T0 + 800, 790, 1, 'schedule');
run($d, $st, $T0 + 960); ck('before the migration: summaries go, no stop emails', got('/session done/') === 1 && got('/charging stopped/') === 0);
ck('old cursor kept for rollback', (int)$st['session_id'] === $s);
$c = new PDO('sqlite::memory:'); $c->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$c->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, role TEXT, scope_all INTEGER, n_errors INTEGER DEFAULT 0, n_reboots INTEGER DEFAULT 0, n_sessions INTEGER DEFAULT 0, n_reminders INTEGER DEFAULT 0)");
$c->exec("INSERT INTO users (email, role, scope_all, n_errors, n_reboots, n_sessions, n_reminders) VALUES ('a@x.test', 'admin', 1, 1, 1, 1, 1)");
ck('migration: column missing before', !oev_users_have_stops($c));
ck('migration adds n_stops, n_stops_since and n_sessions_since', oev_users_stop_cols($c) === array('n_stops', 'n_stops_since', 'n_sessions_since'));
ck('migration again: nothing to do', oev_users_stop_cols($c) === array());
$r = $c->query('SELECT * FROM users')->fetch(PDO::FETCH_ASSOC);
ck('existing user: Charging stopped off, other prefs kept', (int)$r['n_stops'] === 0 && $r['n_stops_since'] === null && (int)$r['n_sessions'] === 1 && (int)$r['n_errors'] === 1 && (int)$r['n_reboots'] === 1 && (int)$r['n_reminders'] === 1);
$c->exec("INSERT INTO users (email, role, scope_all) VALUES ('b@x.test', 'viewer', 1)");
ck('new user: Charging stopped off by default', (int)$c->query("SELECT n_stops FROM users WHERE email = 'b@x.test'")->fetchColumn() === 0);

exec('rm -rf ' . escapeshellarg($tmp));
echo $f ? "$f of $n failed\n" : "all $n passed\n"; exit($f ? 1 : 0);
