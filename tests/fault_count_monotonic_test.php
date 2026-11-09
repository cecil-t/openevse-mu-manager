<?php
// php tests/fault_count_monotonic_test.php
// Lifetime fault counters (GFCI / no-ground / stuck relay) through the REAL collector path (sess_process):
// monotonic, a 0 / missing / lower reading never lowers the count or logs a trip. In-memory SQLite only.
$f = 0; $n = 0;
function ck($name, $ok){ global $f, $n; $n++; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; }
$tmp = __DIR__ . '/tmp/fc_' . getmypid(); @mkdir($tmp . '/data', 0700, true); @mkdir($tmp . '/collector', 0700, true);
foreach (array('sessions_lib.php', 'sess_path.php', 'volts_lib.php', 'settings_lib.php', 'secret_lib.php', 'db_path.php', 'collector/session_logic.php') as $x) copy(__DIR__ . '/../' . $x, "$tmp/$x");
require_once "$tmp/sessions_lib.php";
require_once "$tmp/settings_lib.php";
require_once "$tmp/collector/session_logic.php";
if (!function_exists('evse_state_text')) { function evse_state_text($c){ return "state $c"; } }

function mkdb(){ $d = new PDO('sqlite::memory:'); $d->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $d->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); sess_schema($d); return $d; }
$unit = array('id'=>2, 'name'=>'N Penn: 3R 120v', 'breaker'=>16, 'evseurl'=>'x', 'volts_override'=>null, 'voltmeter'=>null);
// idle reading; $c = array(gfci, nognd, stuck), null = not in the reading
function rd($c, $uptime = null){ return array('ok'=>true, 'api'=>'http', 'state'=>1, 'connected'=>0, 'charging'=>0, 'amps'=>0,
	'pilot'=>16, 'cmax'=>null, 'temp_c'=>25, 'lifetime_wh'=>null, 'session_wh'=>null, 'volts'=>120, 'elapsed'=>null, 'vflags'=>null, 'limit_sleep'=>false, 'limit'=>false, 'timer_active'=>null,
	'override'=>null, 'our_claim'=>null, 'sched_disabled'=>null, 'gfci'=>$c[0], 'nognd'=>$c[1], 'stuck'=>$c[2], 'error'=>'', 'temp1_c'=>null, 'temp2_c'=>null, 'temp3_c'=>null, 'src'=>'poll', 'uptime'=>$uptime); }
function evs($d){ return $d->query("SELECT detail, from_val, to_val FROM events WHERE kind = 'fault_count' ORDER BY id")->fetchAll(); }
function counts($d){ return array_values($d->query('SELECT gfci_count, nognd_count, stuck_count FROM unit_state WHERE unit_id = 2')->fetch(PDO::FETCH_NUM)); }
function run($seq){ global $unit; $d = mkdb(); $t = 1790000000; foreach ($seq as $s) { sess_process($d, $unit, rd($s[0], $s[1] ?? null), $t); $t += 60; } return $d; }

// the 3R 120v pattern: 5/6 seen, then 0 after a WiFi restart, then 5/6 again (repeatedly)
$d = run(array(array(array(5, 6, 1)), array(array(0, 0, 0), 20), array(array(5, 6, 1), 80), array(array(0, 0, 0), 15), array(array(5, 6, 1), 200), array(array(0, 0, 0))));
ck('5 -> 0 -> 5 -> 0 -> 5 -> 0: no trip events at all (first reading is the starting count)', count(evs($d)) === 0);
ck('... and the stored counts never went down (5 / 6 / 1)', counts($d) === array(5, 6, 1));

$d = run(array(array(array(5, 6, 1)), array(array(0, 0, 0)), array(array(6, 6, 1))));
$e = evs($d);
ck('5 -> 0 -> 6: one event "+1 (now 6)" measured from the real 5, not from the false 0', count($e) === 1 && $e[0]['detail'] === 'GFCI trip count +1 (now 6)' && (int)$e[0]['from_val'] === 5 && (int)$e[0]['to_val'] === 6);
ck('... stored count 6', counts($d)[0] === 6);

$d = run(array(array(array(5, 6, 1)), array(array(6, 6, 1)), array(array(6, 6, 1)), array(array(5, 6, 1)), array(array(6, 7, 1))));
$e = evs($d);
ck('5 -> 6 -> 6 -> 5 (stale) -> 6: GFCI +1 once; no-ground 6 -> 7 +1', count($e) === 2 && $e[0]['detail'] === 'GFCI trip count +1 (now 6)' && $e[1]['detail'] === 'No-ground trip count +1 (now 7)');

$d = run(array(array(array(5, 6, 1)), array(array(null, null, null)), array(array(5, 6, 1))));
ck('missing counts in a reading are ignored (no event, counts kept)', count(evs($d)) === 0 && counts($d) === array(5, 6, 1));

$d = run(array(array(array(0, 0, 0), 30), array(array(5, 6, 1), 120)));
ck('first reading 0 within 5 min of a WiFi restart is not taken as the start: later 5/6/1 is the start, no trips claimed', count(evs($d)) === 0 && counts($d) === array(5, 6, 1));

$d = run(array(array(array(0, 0, 0), 3600), array(array(1, 0, 0), 3660)));
$e = evs($d);
ck('a settled 0 (uptime 1 h) is a real start: the first real trip 0 -> 1 is logged', count($e) === 1 && $e[0]['detail'] === 'GFCI trip count +1 (now 1)');

$d = run(array(array(array(0, 0, 0)), array(array(1, 0, 0))));
ck('2.x (no uptime): 0 at the start is taken as real, 0 -> 1 logged', count(evs($d)) === 1);

$d = run(array(array(array(5, 6, 1)), array(array(80, 6, 1))));
ck('an unbelievable jump (+75) re-baselines without claiming 75 trips', count(evs($d)) === 0 && counts($d)[0] === 80);

// pure helper
ck('helper: lower never lowers', sess_fault_count_next(5, 0) === array(5, 0) && sess_fault_count_next(5, 3) === array(5, 0));
ck('helper: null reading keeps stored (and null stays null)', sess_fault_count_next(5, null) === array(5, 0) && sess_fault_count_next(null, null) === array(null, 0));
ck('helper: rise logs the difference', sess_fault_count_next('5', 7) === array(7, 2));

exec('rm -rf ' . escapeshellarg($tmp));
echo $f ? "$f of $n FAILED\n" : "all $n passed\n";
exit($f ? 1 : 0);
