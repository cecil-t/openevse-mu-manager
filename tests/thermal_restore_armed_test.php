<?php
// php tests/thermal_restore_armed_test.php
// Thermal restore is only logged if a throttle was armed (65 C crossing or a
// thermal derate). Restore does not increment sessions.faults. In-memory SQLite only.
$f = 0; $n = 0;
function ck($name, $ok){ global $f, $n; $n++; if (!$ok) { $f++; echo "FAIL $name\n"; } else echo "PASS $name\n"; }
$tmp = __DIR__ . '/tmp/th_' . getmypid(); @mkdir($tmp . '/data', 0700, true); @mkdir($tmp . '/collector', 0700, true);
foreach (array('sessions_lib.php', 'sess_path.php', 'volts_lib.php', 'settings_lib.php', 'secret_lib.php', 'db_path.php', 'collector/session_logic.php') as $x) copy(__DIR__ . '/../' . $x, "$tmp/$x");
require_once "$tmp/sessions_lib.php";
require_once "$tmp/settings_lib.php";
require_once "$tmp/collector/session_logic.php";
if (!function_exists('evse_state_text')) { function evse_state_text($c){ return "state $c"; } }
if (!function_exists('slog')) { function slog($lvl, $msg){ $GLOBALS['SESS_LOG'][] = array(time(), $lvl, $msg); } }

function mkdb(){ $d = new PDO('sqlite::memory:'); $d->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $d->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); sess_schema($d); return $d; }
$unit = array('id'=>2, 'name'=>'N Penn: 3R', 'breaker'=>48, 'evseurl'=>'x', 'volts_override'=>null, 'voltmeter'=>null);

function rd($temp, $extra = array()){
	return array_merge(array(
		'ok'=>true, 'api'=>'http', 'state'=>3, 'connected'=>1, 'charging'=>1, 'amps'=>32,
		'pilot'=>48, 'cmax'=>48, 'temp_c'=>$temp, 'lifetime_wh'=>10000, 'session_wh'=>100,
		'volts'=>240, 'elapsed'=>60, 'vflags'=>null, 'limit_sleep'=>false, 'limit'=>false, 'timer_active'=>null,
		'override'=>null, 'our_claim'=>null, 'sched_disabled'=>null, 'gfci'=>0, 'nognd'=>0, 'stuck'=>0,
		'error'=>'', 'temp1_c'=>null, 'temp2_c'=>null, 'temp3_c'=>null, 'src'=>'poll', 'uptime'=>3600,
	), $extra);
}
function evs($d){ return $d->query("SELECT kind, reason, detail, from_val, to_val FROM events WHERE kind = 'thermal' ORDER BY id")->fetchAll(); }
function faults($d){ return (int)$d->query('SELECT coalesce(faults,0) FROM sessions ORDER BY id DESC LIMIT 1')->fetchColumn(); }
function armed($d){ return (int)$d->query('SELECT coalesce(thermal_armed,0) FROM unit_state WHERE unit_id = 2')->fetchColumn(); }
function run($temps){
	global $unit;
	$d = mkdb(); $t = 1790000000;
	foreach ($temps as $step) {
		list($temp, $extra) = is_array($step) && array_key_exists('temp', $step) ? array($step['temp'], $step) : array($step, array());
		unset($extra['temp']);
		sess_process($d, $unit, rd($temp, $extra), $t);
		$t += 60;
	}
	return $d;
}

// schema
$d = mkdb();
$cols = array();
foreach ($d->query("PRAGMA table_info(unit_state)") as $c) $cols[$c['name']] = 1;
ck('schema v8 adds unit_state.thermal_armed', isset($cols['thermal_armed']) && (int)$d->query("SELECT v FROM meta WHERE k = 'schema_version'")->fetchColumn() === 8);
ck('sess_cols_ok is true on a fresh v8 schema', sess_cols_ok($d) === true);

// the 3R session 25 pattern: peak 63.5 C, then a drop across 62 with no prior 65 C throttle
$d = run(array(40, 61, 63.5, 62.4, 61.5));
ck('drop across 62 C with no prior throttle: no thermal events', evs($d) === array());
ck('... faults stay 0', faults($d) === 0);
ck('... thermal_armed stays 0', armed($d) === 0);

// real throttle then restore
$d = run(array(40, 64.9, 65.0, 63.0, 62.0));
$e = evs($d);
ck('64.9 -> 65.0 logs a throttle event', count($e) === 2 && $e[0]['reason'] === 'thermal' && strpos($e[0]['detail'], 'throttle') !== false);
ck('... then 62.0 logs a restore', $e[1]['reason'] === 'thermal restore' && strpos($e[1]['detail'], 'restore') !== false);
ck('... faults incremented only for the throttle (not the restore)', faults($d) === 1);
ck('... flag armed at 65, cleared on restore', armed($d) === 0);

// after a restore, a later 62 C crossing is not a restore
$d = run(array(40, 65.0, 62.0, 63.0, 61.5));
$e = evs($d);
ck('after restore, a later drop across 62 C is ignored', count($e) === 2 && $e[0]['reason'] === 'thermal' && $e[1]['reason'] === 'thermal restore');
ck('... faults still 1', faults($d) === 1);

// thermal derate arms the flag even if 65 C was never crossed
$d = run(array(
	array('temp'=>40, 'pilot'=>48),
	array('temp'=>63, 'pilot'=>48),
	array('temp'=>63, 'pilot'=>40),   // derate: pilot down while temp >= 60
	array('temp'=>61.5, 'pilot'=>40),
));
$e = evs($d);
ck('pilot drop at 63 C arms via thermal derate (no 65 C crossing)', count($e) >= 1 && $e[0]['reason'] === 'thermal' && strpos($e[0]['detail'], 'derate') !== false);
ck('... then a drop across 62 C logs restore', count($e) === 2 && $e[1]['reason'] === 'thermal restore');
ck('... faults incremented only for the derate', faults($d) === 1);
ck('... flag cleared after that restore', armed($d) === 0);

// a 63 C peak with no derate still does not arm
$d = run(array(
	array('temp'=>40, 'pilot'=>48),
	array('temp'=>63, 'pilot'=>48),
	array('temp'=>61.5, 'pilot'=>48),
));
ck('63 C peak, unchanged pilot: no events, not armed', evs($d) === array() && armed($d) === 0 && faults($d) === 0);

// sess_event itself: restore must not bump faults; throttle must
$d = mkdb();
$d->exec("INSERT INTO unit_state (unit_id, unit_name) VALUES (2, '3R')");
$d->exec("INSERT INTO sessions (id, unit_id, unit_name, plug_in_at, open, faults) VALUES (1, 2, '3R', 1790000000, 1, 0)");
sess_event($d, $unit, 1, null, 1790000000, 'thermal', 'temperature back below restore threshold (62 C)', 63, 61.5, 61.5, 'thermal restore');
ck('sess_event: thermal restore does not increment faults', faults($d) === 0);
sess_event($d, $unit, 1, null, 1790000060, 'thermal', 'temperature reached throttle threshold (65 C)', 64, 65, 65, 'thermal');
ck('sess_event: thermal throttle does increment faults', faults($d) === 1);

exec('rm -rf ' . escapeshellarg($tmp));
echo $f ? "$f of $n FAILED\n" : "all $n passed\n";
exit($f ? 1 : 0);
