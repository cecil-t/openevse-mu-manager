<?php
// php tests/notify_overrides_test.php
// Per-unit "Your notifications" (user_unit_notify): schema/migration, the resolver shared by the UI and the
// notifier, the real save path (oev_unit_notify_save via notify_prefs.php unp_handle), the overview page
// renderer (notifications.php nov_render), and the real notifier library with a stub mailer.
// In-memory SQLite and a throwaway copy of the app only; no email is sent; never opens a live DB.
$f = 0; $n = 0;
function ck($name, $ok){ global $f, $n; $n++; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; }
$tmp = __DIR__ . '/tmp/no_' . getmypid(); @mkdir($tmp . '/data', 0700, true); @mkdir($tmp . '/collector', 0700, true);
foreach (array('sessions_lib.php', 'sess_path.php', 'volts_lib.php', 'settings_lib.php', 'secret_lib.php', 'db_path.php', 'auth_lib.php', 'notify_prefs.php', 'notifications.php', 'collector/session_logic.php') as $x) copy(__DIR__ . '/../' . $x, "$tmp/$x");
require_once "$tmp/sessions_lib.php";
require_once "$tmp/settings_lib.php";
require_once "$tmp/auth_lib.php";
require_once "$tmp/collector/session_logic.php";
if (!function_exists('evse_state_text')) { function evse_state_text($c){ return "state $c"; } }
require_once __DIR__ . '/../collector/notify_sessions_lib.php';
define('UNP_LIB_ONLY', 1); define('NOV_LIB_ONLY', 1);
require_once "$tmp/notify_prefs.php";
require_once "$tmp/notifications.php";

$T0 = 1790000000;
// config DB the way setup_auth.php makes it (the parts used here)
function cfgdb(){
	$c = new PDO('sqlite::memory:'); $c->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $c->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
	$c->exec("CREATE TABLE evses (id INTEGER PRIMARY KEY NOT NULL, name TEXT, loadgroupid INTEGER, breaker INTEGER DEFAULT 40);
		CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE COLLATE NOCASE, pass_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'viewer', scope_all INTEGER DEFAULT 0,
			n_errors INTEGER DEFAULT 0, n_reboots INTEGER DEFAULT 0, n_sessions INTEGER DEFAULT 0, n_reminders INTEGER DEFAULT 0);
		CREATE TABLE user_groups (user_id INTEGER NOT NULL, group_id INTEGER NOT NULL, PRIMARY KEY (user_id, group_id));");
	oev_users_stop_cols($c);
	$c->exec("INSERT INTO evses (id, name, loadgroupid) VALUES (4, 'N Penn: 1F', 1), (5, 'Other: 9X', 2)");
	// A: admin, account defaults OFF.  B: view-only, group 1 only, account defaults ON.
	$c->exec("INSERT INTO users (id, email, pass_hash, role, scope_all, n_sessions, n_stops, n_stops_since) VALUES
		(1, 'a@example.test', 'x', 'admin', 1, 0, 0, NULL), (2, 'b@example.test', 'x', 'viewer', 0, 1, 1, " . ($GLOBALS['T0'] - 99999) . ")");
	$c->exec('INSERT INTO user_groups VALUES (2, 1)');
	return $c;
}
function urows($c){ $u = $c->query('SELECT * FROM users ORDER BY id')->fetchAll(); foreach ($u as &$x) { $x['groups'] = array_map('intval', $c->query('SELECT group_id FROM user_groups WHERE user_id = ' . (int)$x['id'])->fetchAll(PDO::FETCH_COLUMN)); $x['ovr'] = oev_unit_notify_rows($c, (int)$x['id']); } unset($x); return $u; }
function me($c, $id){ foreach (urows($c) as $u) if ((int)$u['id'] === $id) return $u; return null; }
function vis($me){ return function($unit) use ($me){ if ($me['role'] === 'admin' || !empty($me['scope_all'])) return true; $g = (int)($unit['loadgroupid'] ?? 0); return $g && in_array($g, $me['groups'], true); }; }
function unitsfor($c, $me){ $o = array(); $v = vis($me); foreach ($c->query('SELECT id, name, loadgroupid FROM evses ORDER BY id') as $r) if ($v($r)) $o[(int)$r['id']] = $r; return $o; }
function post($c, $id, $p){ return unp_handle($c, me($c, $id), $p, vis(me($c, $id))); }

/* ===== schema / migration ===== */
$c = cfgdb();
ck('no table before the migration: rows empty, notifier falls back to account defaults', !oev_unit_notify_have($c) && oev_unit_notify_rows($c, 1) === array());
ck('before the migration the save endpoint refuses, nothing written', post($c, 1, array('unitid'=>'4', 'n_sessions'=>'on', 'n_stops'=>'on'))[0] === false);
ck('migration creates the table', oev_unit_notify_table($c) === true && oev_unit_notify_have($c));
ck('migration again: nothing to do', oev_unit_notify_table($c) === false);
ck('new table is empty: everyone uses their account default', (int)$c->query('SELECT count(*) FROM user_unit_notify')->fetchColumn() === 0);
$bad = 0; foreach (array("(1,4,'sessions','maybe',1,1)", "(1,4,'errors','on',1,1)") as $v) { try { $c->exec("INSERT INTO user_unit_notify (user_id, unit_id, kind, mode, since, updated_at) VALUES $v"); } catch (PDOException $x) { $bad++; } }
ck('schema rejects an unknown mode or kind', $bad === 2);
$c->exec("INSERT INTO user_unit_notify VALUES (1, 4, 'stops', 'on', 5, 5)"); $dup = false; try { $c->exec("INSERT INTO user_unit_notify VALUES (1, 4, 'stops', 'off', 5, 5)"); } catch (PDOException $x) { $dup = true; }
ck('one row per login + unit + kind', $dup); $c->exec('DELETE FROM user_unit_notify');
$prefs0 = json_encode($c->query('SELECT id, n_sessions, n_stops, n_stops_since FROM users')->fetchAll());

/* ===== resolver: account default x unit choice (9 per kind) ===== */
$acc = array('on' => array('n_sessions'=>1, 'n_stops'=>1, 'n_stops_since'=>100), 'off' => array('n_sessions'=>0, 'n_stops'=>0, 'n_stops_since'=>null), 'on, no start time' => array('n_sessions'=>1, 'n_stops'=>1, 'n_stops_since'=>null));
foreach (array('sessions', 'stops') as $k) foreach ($acc as $an => $a) foreach (array('inherit', 'on', 'off') as $m) {
	$row = $m === 'inherit' ? null : array('mode'=>$m, 'since'=>$m === 'on' ? 200 : null, 'updated_at'=>200);
	$e = oev_notify_effective($a, $row, $k);
	$wantOn = $m === 'on' ? true : ($m === 'off' ? false : $an !== 'off');
	$wantSince = $m === 'on' ? 200 : ($m === 'off' || $an === 'off' ? null : ($k === 'sessions' ? 0 : ($an === 'on' ? 100 : null)));
	$u = $a + array('email'=>'x@example.test', 'role'=>'admin', 'scope_all'=>1, 'ovr'=>array(4 => $row ? array($k => $row) : array()));
	$why = ns_why_not($u, array(4 => array('loadgroupid'=>1)), 'n_' . $k, 4, 300);
	ck("matrix $k: account $an, unit $m -> " . ($wantOn ? 'on' : 'off') . " (notifier agrees)", $e['on'] === $wantOn && $e['since'] === $wantSince && (($why === '') === ($wantOn && $wantSince !== null)));
}

/* ===== real save path (notify_prefs.php unp_handle -> oev_unit_notify_save) ===== */
$c = cfgdb(); oev_unit_notify_table($c);
$r = post($c, 1, array('unitid'=>'4', 'n_sessions'=>'on', 'n_stops'=>'on', 'user_id'=>'2', 'id'=>'2'));
$rows = $c->query('SELECT user_id, unit_id, kind, mode FROM user_unit_notify ORDER BY kind')->fetchAll();
ck('A (account Off) sets unit 4 On: saved for A only, posted user id ignored', $r[0] && count($rows) === 2 && $rows[0]['user_id'] == 1 && $rows[1]['user_id'] == 1 && $rows[0]['mode'] === 'on');
ck('account defaults untouched by a unit choice', json_encode($c->query('SELECT id, n_sessions, n_stops, n_stops_since FROM users')->fetchAll()) === $prefs0);
$cnt = function() use (&$c){ return (int)$c->query('SELECT count(*) FROM user_unit_notify')->fetchColumn(); };
$before = $cnt();
ck('invalid choice: refused, nothing changed', post($c, 1, array('unitid'=>'4', 'n_sessions'=>'off', 'n_stops'=>'yes'))[0] === false && $cnt() === $before && me($c, 1)['ovr'][4]['sessions']['mode'] === 'on');
ck('missing field: refused', post($c, 1, array('unitid'=>'4', 'n_sessions'=>'off'))[0] === false && me($c, 1)['ovr'][4]['sessions']['mode'] === 'on');
ck('array instead of a choice: refused', post($c, 1, array('unitid'=>'4', 'n_sessions'=>array('on'), 'n_stops'=>'on'))[0] === false);
ck('unknown unit id: refused', post($c, 1, array('unitid'=>'99', 'n_sessions'=>'on', 'n_stops'=>'on'))[0] === false && $cnt() === $before);
ck('non-numeric unit id: refused', post($c, 1, array('unitid'=>'4 OR 1', 'n_sessions'=>'on', 'n_stops'=>'on'))[0] === false);
ck('view-only B cannot set a unit outside their load groups', post($c, 2, array('unitid'=>'5', 'n_sessions'=>'on', 'n_stops'=>'on'))[0] === false && $cnt() === $before);
$r = post($c, 2, array('unitid'=>'4', 'n_sessions'=>'off', 'n_stops'=>'inherit'));
ck('view-only B can set their own email for a unit they see', $r[0] && me($c, 2)['ovr'][4]['sessions']['mode'] === 'off' && !isset(me($c, 2)['ovr'][4]['stops']));
ck("B's save did not change A's choices", me($c, 1)['ovr'][4]['sessions']['mode'] === 'on' && me($c, 1)['ovr'][4]['stops']['mode'] === 'on');
ck('unit settings untouched (evses unchanged)', json_encode($c->query('SELECT * FROM evses ORDER BY id')->fetchAll()) === json_encode(array(array('id'=>4, 'name'=>'N Penn: 1F', 'loadgroupid'=>1, 'breaker'=>40), array('id'=>5, 'name'=>'Other: 9X', 'loadgroupid'=>2, 'breaker'=>40))));
ck('a view-only link (public) cannot save', unp_handle($c, array('id'=>0, 'role'=>'public'), array('unitid'=>'4', 'n_sessions'=>'on', 'n_stops'=>'on'), function(){ return true; })[0] === false);
ck('nobody logged in cannot save', unp_handle($c, null, array('unitid'=>'4', 'n_sessions'=>'on', 'n_stops'=>'on'), function(){ return true; })[0] === false);
ck('same choice again: "No change", start time kept', ($t = me($c, 1)['ovr'][4]['stops']['since']) && post($c, 1, array('unitid'=>'4', 'n_sessions'=>'on', 'n_stops'=>'on'))[1] === 'No change.' && me($c, 1)['ovr'][4]['stops']['since'] == $t);
// CSRF (auth_lib, used by notify_prefs.php before anything else)
$_COOKIE[OEV_CSRF_COOKIE] = str_repeat('a', 32); $_POST['csrf'] = str_repeat('b', 32);
ck('CSRF: a token that does not match the cookie is refused', !oev_csrf_ok());
unset($_POST['csrf']); ck('CSRF: no token is refused', !oev_csrf_ok()); unset($_COOKIE[OEV_CSRF_COOKIE]);
$src = file_get_contents(__DIR__ . '/../notify_prefs.php');
ck('endpoint: view login required, POST only, CSRF checked before saving, user from the session', strpos($src, "oev_require('view')") !== false && strpos($src, 'oev_csrf_check();') < strpos($src, 'unp_handle(oev_db(), $me,') && strpos($src, "REQUEST_METHOD'] !== 'POST'") !== false && strpos($src, '$me[\'id\']') !== false && !preg_match('/\$_POST\[.user/', $src));

/* ===== start times (no backlog) ===== */
$c = cfgdb(); oev_unit_notify_table($c); $since = function($id, $u, $k) use (&$c){ return oev_notify_effective(me($c, $id), me($c, $id)['ovr'][$u][$k] ?? null, $k)['since']; };
$accB = $T0 - 99999;
oev_unit_notify_save($c, 2, 4, array('stops'=>'on'), $T0);
ck('account On -> unit On: still the account start time (stays on, no reset)', $since(2, 4, 'stops') === $accB);
oev_unit_notify_save($c, 2, 4, array('stops'=>'inherit'), $T0 + 10);
ck('unit On -> account default (still On): start kept', $since(2, 4, 'stops') === $accB);
oev_unit_notify_save($c, 2, 4, array('stops'=>'off'), $T0 + 20); oev_unit_notify_save($c, 2, 4, array('stops'=>'on'), $T0 + 30);
ck('Off then On again: starts at that save (nothing older)', $since(2, 4, 'stops') === $T0 + 30);
oev_unit_notify_save($c, 2, 4, array('stops'=>'off'), $T0 + 40); oev_unit_notify_save($c, 2, 4, array('stops'=>'inherit'), $T0 + 50);
ck('Off -> account default (On): starts at that save', $since(2, 4, 'stops') === $T0 + 50);
$c->exec('UPDATE users SET n_stops = 0, n_stops_since = NULL WHERE id = 2');
ck('account default turned Off: an inheriting unit is off', !oev_notify_effective(me($c, 2), me($c, 2)['ovr'][4]['stops'], 'stops')['on']);
$c->exec('UPDATE users SET n_stops = 1, n_stops_since = ' . ($T0 + 900) . ' WHERE id = 2');
ck('account default On again later: the inheriting unit starts then (not earlier)', $since(2, 4, 'stops') === $T0 + 900);
oev_unit_notify_save($c, 1, 5, array('stops'=>'off'), $T0);
$c->exec('UPDATE users SET n_stops = 1, n_stops_since = ' . ($T0 + 5) . ' WHERE id = 1');
ck('account turned On: a unit set Off stays off', !oev_notify_effective(me($c, 1), me($c, 1)['ovr'][5]['stops'], 'stops')['on']);

// Session summary account default now has its own start time (users.n_sessions_since)
$c = cfgdb(); oev_unit_notify_table($c);
ck('migration added users.n_sessions_since (NULL: no account was touched)', $c->query('SELECT count(*) FROM users WHERE n_sessions_since IS NOT NULL')->fetchColumn() == 0);
oev_unit_notify_save($c, 2, 4, array('sessions'=>'on'), $T0);          // explicit On, account On (legacy: no start time)
oev_unit_notify_save($c, 2, 5, array('sessions'=>'off'), $T0);
oev_unit_notify_save($c, 2, 5, array('sessions'=>'inherit'), $T0 + 10); // inherit from Off: starts then
ck('summary: unit On with account On (legacy) keeps no-limit start; inherit from Off starts at that save', $since(2, 4, 'sessions') === 0 && $since(2, 5, 'sessions') === $T0 + 10);
$c->exec('UPDATE users SET n_sessions = 0, n_sessions_since = NULL WHERE id = 2');
ck('summary: account Off -> inheriting unit off, explicit On unaffected', !oev_notify_effective(me($c, 2), me($c, 2)['ovr'][5]['sessions'], 'sessions')['on'] && $since(2, 4, 'sessions') === 0);
$c->exec('UPDATE users SET n_sessions = 1, n_sessions_since = ' . ($T0 + 500) . ' WHERE id = 2');
ck('summary: account On again later -> inheriting unit starts then; explicit On keeps its start', $since(2, 5, 'sessions') === $T0 + 500 && $since(2, 4, 'sessions') === 0);
$u = me($c, 2); $U2 = array(4 => array('loadgroupid'=>1), 5 => array('loadgroupid'=>1));
ck('summary: unplug before or in the same second the account came back On is not sent; the next second is', ns_why_not($u, $U2, 'n_sessions', 5, $T0 + 499) !== '' && ns_why_not($u, $U2, 'n_sessions', 5, $T0 + 500) !== '' && ns_why_not($u, $U2, 'n_sessions', 5, $T0 + 501) === '');
ck('summary: explicit On unit with the legacy no-limit start is unaffected by the account (any time)', ns_why_not($u, $U2, 'n_sessions', 4, 1) === '');
// strict boundary for every kind of start: account stops, unit On (both kinds), legacy 0
$bu = array('id'=>9, 'email'=>'z@example.test', 'role'=>'admin', 'scope_all'=>1, 'n_sessions'=>1, 'n_sessions_since'=>1000, 'n_stops'=>1, 'n_stops_since'=>1000, 'ovr'=>array(5 => array('sessions'=>array('mode'=>'on', 'since'=>2000, 'updated_at'=>2000), 'stops'=>array('mode'=>'on', 'since'=>2000, 'updated_at'=>2000))));
$bok = true;
foreach (array('n_sessions', 'n_stops') as $col) {
	$bok = $bok && ns_why_not($bu, $U2, $col, 4, 1000) !== '' && ns_why_not($bu, $U2, $col, 4, 1001) === '';   // account start
	$bok = $bok && ns_why_not($bu, $U2, $col, 5, 2000) !== '' && ns_why_not($bu, $U2, $col, 5, 2001) === '' && ns_why_not($bu, $U2, $col, 5, 1500) !== '';   // unit On start, independent of the account's
}
ck('boundary: same second as any new start is not sent, next second is (account and unit, both kinds)', $bok);
$lg = $bu; $lg['n_sessions_since'] = null; ck('boundary: legacy account summary (on, no time) still has no limit', ns_why_not($lg, $U2, 'n_sessions', 4, 5) === '');


function sdb(){ $d = new PDO('sqlite::memory:'); $d->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $d->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); sess_schema($d); return $d; }
function sess($d, $unit, $plug, $open, $unplug){ $d->prepare('INSERT INTO sessions (unit_id, unit_name, plug_in_at, open, unplug_at, kwh, charge_seconds, lifetime_end_wh) VALUES (?,?,?,?,?,1.0,600,5000)')->execute(array($unit, "U$unit", $plug, $open, $unplug)); return (int)$d->lastInsertId(); }
function seg($d, $sid, $unit, $start, $stop){ $d->prepare("INSERT INTO segments (session_id, unit_id, start_at, stop_at, seconds, kwh, stop_reason, open, lifetime_start_wh, lifetime_end_wh) VALUES (?,?,?,?,?,1.0,'schedule',0,4000,5000)")->execute(array($sid, $unit, $start, $stop, $stop - $start)); }
$OUT = array(); $FAIL = array();
$send = function($to, $subj, $body) use (&$OUT, &$FAIL){ if (!empty($FAIL[$to])) { $FAIL[$to]--; return 'smtp down'; } $OUT[] = array($to, $subj); return true; };
$save = function(){};
$U = array(4 => array('name'=>'N Penn: 1F', 'loadgroupid'=>1), 5 => array('name'=>'Other: 9X', 'loadgroupid'=>2));
function go($d, $c, &$st, $now, $on = true){ global $U, $send, $save; $us = urows($c); ns_baseline($st, $d, $now - 100000, $save); ns_stops($d, $us, $U, $st, $now, $on, $send, $save); ns_summaries($d, $us, $U, $st, $now, $on, $send, $save); }
function mails($to, $pat){ global $OUT; return count(array_filter($OUT, function($m) use ($to, $pat){ return $m[0] === $to && preg_match($pat, $m[1]); })); }
// one stop (schedule, still plugged in) and one summary on each unit, after everything was set
function events($d, $t){ foreach (array(4, 5) as $u) { $s = sess($d, $u, $t, 1, null); seg($d, $s, $u, $t + 10, $t + 1000); $x = sess($d, $u, $t - 5000, 0, $t + 1200); } }

$c = cfgdb(); oev_unit_notify_table($c); $d = sdb(); $st = array(); ns_baseline($st, $d, $T0, $save); $OUT = array();
oev_unit_notify_save($c, 1, 4, array('sessions'=>'on', 'stops'=>'on'), $T0);   // A: account Off, unit 4 On
oev_unit_notify_save($c, 2, 4, array('sessions'=>'off', 'stops'=>'off'), $T0);  // B: account On, unit 4 Off (B cannot see unit 5)
events($d, $T0 + 100); go($d, $c, $st, $T0 + 2000);
ck('A: account Off + unit 4 On -> stop and summary for unit 4 only', mails('a@example.test', '/1F: charging stopped/') === 1 && mails('a@example.test', '/1F: session done/') === 1 && mails('a@example.test', '/9X/') === 0);
ck('B: account On + unit 4 Off -> nothing for unit 4; unit 5 not visible -> nothing', mails('b@example.test', '/./') === 0);
go($d, $c, $st, $T0 + 3000); ck('next run: nothing sent twice', count($OUT) === 2);

$c = cfgdb(); oev_unit_notify_table($c); $d = sdb(); $st = array(); ns_baseline($st, $d, $T0, $save); $OUT = array();
oev_unit_notify_save($c, 1, 4, array('sessions'=>'on', 'stops'=>'on'), $T0);
events($d, $T0 + 100); go($d, $c, $st, $T0 + 2000, false);
ck('site-wide switch off: unit On still sends nothing', count($OUT) === 0);

// pending retries: re-checked at every attempt
$c = cfgdb(); oev_unit_notify_table($c); $d = sdb(); $st = array(); ns_baseline($st, $d, $T0, $save); $OUT = array();
oev_unit_notify_save($c, 1, 4, array('sessions'=>'on', 'stops'=>'on'), $T0);
$FAIL = array('a@example.test' => 9, 'b@example.test' => 9);
events($d, $T0 + 100); go($d, $c, $st, $T0 + 2000);
ck('queued for A and B after failed sends', count($OUT) === 0 && in_array(1, $st['stop'][1]['to']) && in_array(2, $st['stop'][1]['to']));
$FAIL = array();
oev_unit_notify_save($c, 1, 4, array('sessions'=>'off', 'stops'=>'off'), $T0 + 2100);   // A: unit Off while pending
$c->exec('UPDATE users SET n_sessions = 0, n_stops = 0, n_stops_since = NULL WHERE id = 2'); // B inherits; account flipped Off
go($d, $c, $st, $T0 + 9000);
ck('unit turned Off / inherited account turned Off while queued: retries cancelled', count($OUT) === 0 && ($st['stop'][1]['drop'][1] ?? '') === 'turned off' && ($st['stop'][1]['drop'][2] ?? '') === 'turned off');
oev_unit_notify_save($c, 1, 4, array('sessions'=>'on', 'stops'=>'on'), $T0 + 9100); go($d, $c, $st, $T0 + 20000);
ck('turned On again: the cancelled old emails are not resent', count($OUT) === 0);

// access removed after the email was queued
$c = cfgdb(); oev_unit_notify_table($c); $d = sdb(); $st = array(); ns_baseline($st, $d, $T0, $save); $OUT = array();
$FAIL = array('b@example.test' => 9); events($d, $T0 + 100); go($d, $c, $st, $T0 + 2000);
$FAIL = array(); $c->exec('DELETE FROM user_groups WHERE user_id = 2'); go($d, $c, $st, $T0 + 9000);
ck('access removed while queued: dropped, nothing sent to B', mails('b@example.test', '/./') === 0 && ($st['stop'][1]['drop'][2] ?? '') === 'no access to the unit');

// no backlog: unit set On after the stop / unplug
$c = cfgdb(); oev_unit_notify_table($c); $d = sdb(); $st = array(); ns_baseline($st, $d, $T0, $save); $OUT = array();
events($d, $T0 + 100);
oev_unit_notify_save($c, 1, 4, array('sessions'=>'on', 'stops'=>'on'), $T0 + 1500);   // after the stop (1110) and the unplug (1300)
go($d, $c, $st, $T0 + 2000);
ck('A sets unit On after the stop and unplug (noticed late): nothing old is sent', mails('a@example.test', '/./') === 0);
$s = sess($d, 4, $T0 + 3000, 1, null); seg($d, $s, 4, $T0 + 3010, $T0 + 4000); sess($d, 4, $T0 + 2900, 0, $T0 + 4100);
go($d, $c, $st, $T0 + 5000);
ck('the next stop and unplug after that are sent', mails('a@example.test', '/charging stopped/') === 1 && mails('a@example.test', '/session done/') === 1);

// continuity proof must be strict: account Off, unit On since T-100, unit -> account default at T, account On at the same T
foreach (array('sessions' => 'n_sessions', 'stops' => 'n_stops') as $k => $col) {
	$c = cfgdb(); oev_unit_notify_table($c); $c->exec('UPDATE users SET n_sessions = 0, n_sessions_since = NULL, n_stops = 0, n_stops_since = NULL WHERE id = 2');
	oev_unit_notify_save($c, 2, 4, array($k=>'on'), $T0 - 100);
	oev_unit_notify_save($c, 2, 4, array($k=>'inherit'), $T0);
	ck("$k: unit -> account default while the account is Off stores no start to carry", me($c, 2)['ovr'][4][$k]['since'] === null && !oev_notify_effective(me($c, 2), me($c, 2)['ovr'][4][$k], $k)['on']);
	$c->exec("UPDATE users SET $col = 1, {$col}_since = $T0 WHERE id = 2");
	ck("$k: account On in the same second: starts at the account's time, not the old unit start", $since(2, 4, $k) === $T0);
	// even if a row did carry an earlier start (older code / hand edit), the same second is not proof
	$c->exec("UPDATE user_unit_notify SET since = " . ($T0 - 100) . " WHERE user_id = 2 AND unit_id = 4 AND kind = '$k'");
	ck("$k: carried start with equal timestamps is ignored", $since(2, 4, $k) === $T0);
	$u = me($c, 2); $U2 = array(4 => array('loadgroupid'=>1));
	ck("$k: event at T or T-50 refused (creation and every retry use this check), T+1 allowed", ns_why_not($u, $U2, $col, 4, $T0) !== '' && ns_why_not($u, $U2, $col, 4, $T0 - 50) !== '' && ns_why_not($u, $U2, $col, 4, $T0 + 1) === '');
	$c->exec("UPDATE users SET {$col}_since = " . ($T0 - 1) . " WHERE id = 2");
	ck("$k: account On strictly before the switch: the earlier unit start is carried (stable continuity)", $since(2, 4, $k) === $T0 - 100);
}
// the real notifier, both kinds: a queued (failing) email from before the same-second re-enable is not sent; a new event is
foreach (array('sessions' => 'n_sessions', 'stops' => 'n_stops') as $k => $col) {
	$c = cfgdb(); oev_unit_notify_table($c); $c->exec('UPDATE users SET n_sessions = 0, n_sessions_since = NULL, n_stops = 0, n_stops_since = NULL WHERE id = 2');
	$d = sdb(); $st = array(); ns_baseline($st, $d, $T0, $save); $OUT = array();
	oev_unit_notify_save($c, 2, 4, array($k=>'on'), $T0 + 50);
	$FAIL = array('b@example.test' => 99);
	$s1 = sess($d, 4, $T0 + 100, 1, null); seg($d, $s1, 4, $T0 + 110, $T0 + 300); sess($d, 4, $T0 - 900, 0, $T0 + 300);
	go($d, $c, $st, $T0 + 1000);
	ck("$k: queued for B while the unit was On (send failing)", count($OUT) === 0);
	$TT = $T0 + 2000; oev_unit_notify_save($c, 2, 4, array($k=>'inherit'), $TT); $c->exec("UPDATE users SET $col = 1, {$col}_since = $TT WHERE id = 2");
	$FAIL = array(); go($d, $c, $st, $TT + 100);
	ck("$k: unit -> default + account On in the same second: the queued old email is not sent on retry", count($OUT) === 0);
	$s2 = sess($d, 4, $TT + 200, 1, null); seg($d, $s2, 4, $TT + 210, $TT + 400); sess($d, 4, $TT + 100, 0, $TT + 400);
	go($d, $c, $st, $TT + 1500);
	ck("$k: a new event after that is sent", mails('b@example.test', $k === 'stops' ? '/charging stopped/' : '/session done/') >= 1);
}

$up = file_get_contents(__DIR__ . '/../unit.php');
ck('unit page: the Your notifications box is capped to the screen (beats popFit inline width) and stacks select / Now', strpos($up, '#setyn .slidebox{box-sizing:border-box;width:calc(100vw - 24px)!important;max-width:340px!important') !== false && strpos($up, '.yn select{display:block;width:100%') !== false && strpos($up, '.ynnow{display:block') !== false && strpos($up, 'ynnow{font-size:85%;color:#666;white-space:nowrap}') === false);

/* ===== cleanup ===== */
$c = cfgdb(); oev_unit_notify_table($c); oev_unit_notify_save($c, 1, 4, array('stops'=>'on'), $T0); oev_unit_notify_save($c, 1, 5, array('stops'=>'on'), $T0); oev_unit_notify_save($c, 2, 4, array('stops'=>'off'), $T0);
oev_unit_notify_forget($c, null, 5); ck('unit removed: its choices go, others stay', (int)$c->query('SELECT count(*) FROM user_unit_notify WHERE unit_id = 5')->fetchColumn() === 0 && (int)$c->query('SELECT count(*) FROM user_unit_notify')->fetchColumn() === 2);
oev_unit_notify_forget($c, 1, null); ck('login removed: their choices go, others stay', (int)$c->query('SELECT count(*) FROM user_unit_notify WHERE user_id = 1')->fetchColumn() === 0 && (int)$c->query('SELECT count(*) FROM user_unit_notify')->fetchColumn() === 1);
$ss = file_get_contents(__DIR__ . '/../settings.php');
ck('settings.php removes choices with a login and with a unit', strpos($ss, 'oev_unit_notify_forget($db, $id, null)') !== false && strpos($ss, 'oev_unit_notify_forget($db, null, $id)') !== false);

/* ===== overview page (notifications.php) ===== */
$c = cfgdb(); oev_unit_notify_table($c);
oev_unit_notify_save($c, 1, 4, array('sessions'=>'on'), $T0); oev_unit_notify_save($c, 2, 4, array('stops'=>'off'), $T0);
$A = me($c, 1); $hA = nov_render($A, unitsfor($c, $A), oev_unit_notify_view($c, $A, unitsfor($c, $A)), true, true);
ck('overview A: both units, links to each unit box', strpos($hA, 'unit.php?unitid=4#yn') !== false && strpos($hA, 'unit.php?unitid=5#yn') !== false);
ck('overview A: account Off, unit 4 Session summary On (set for this unit), others Off by default', preg_match('~Session summary <small>Set for this unit</small></span><span class="st on">On~', $hA) && substr_count($hA, 'Account default (Off)') === 3);
ck('overview A: no other login\'s address or choices', strpos($hA, 'b@example.test') === false);
$B = me($c, 2); $hB = nov_render($B, unitsfor($c, $B), oev_unit_notify_view($c, $B, unitsfor($c, $B)), true, true);
ck('overview B (view-only): only unit 4; Charging stopped Off for it, summary On by default', strpos($hB, 'Other: 9X') === false && strpos($hB, 'unitid=5') === false && preg_match('~Charging stopped <small>Set for this unit</small></span><span class="st off">Off~', $hB) && preg_match('~Session summary <small>Account default \(On\)</small></span><span class="st on">On~', $hB));
ck('overview B: no admin link to Settings', strpos($hB, 'settings.php') === false);
$hM = nov_render($A, unitsfor($c, $A), oev_unit_notify_view($c, $A, unitsfor($c, $A)), false, true);
ck('overview: site-wide switch off is shown above the choices', strpos($hM, 'turned off for the whole site') !== false);
$c->exec('DELETE FROM user_groups WHERE user_id = 2'); $B = me($c, 2);
$h0 = nov_render($B, unitsfor($c, $B), array(), true, true);
ck('overview: a login that sees no chargers gets a clear message, no units', strpos($h0, 'no chargers') !== false && strpos($h0, 'unitid=') === false);
$view = oev_unit_notify_view($c, $A, unitsfor($c, $A)); $okv = true;
foreach (unitsfor($c, $A) as $id => $x) foreach (array('sessions', 'stops') as $k) { $e = oev_notify_effective($A, $A['ovr'][$id][$k] ?? null, $k); if ($view[$id][$k]['on'] !== $e['on']) $okv = false; }
ck('overview uses the notifier resolver (same answer for every unit/kind)', $okv);
$ns = file_get_contents(__DIR__ . '/../notifications.php');
ck('overview route: view login required (public links turned away), own row only', strpos($ns, "oev_require('view')") !== false && strpos($ns, "WHERE id = ?'); \$st->execute(array((int)\$me['id']))") !== false && strpos($ns, 'oev_unit_visible') !== false);

exec("rm -rf " . escapeshellarg($tmp)); @rmdir("$tmp/collector"); @rmdir("$tmp/data"); @rmdir($tmp);
echo $f ? "$f of $n FAILED\n" : "all $n passed\n"; exit($f ? 1 : 0);
