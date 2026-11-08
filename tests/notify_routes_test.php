<?php
// php tests/notify_routes_test.php — the REAL pages over HTTP (php -S) on a throwaway copy of the app made by the real
// setup_auth.php: Settings > Users save (Session summary enable time), Settings > Units link, notify_prefs.php,
// notifications.php; then the real notifier library on that config DB with a stub mailer. No email is sent.
$f = 0; $n = 0; function ck($m, $ok){ global $f, $n; $n++; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $m . "\n"; }
$php = PHP_BINARY; $app = dirname(__DIR__); $s = sys_get_temp_dir() . '/oev_routes_' . getmypid();
$files = array_merge(explode("\0", trim(shell_exec('git -C ' . escapeshellarg($app) . ' ls-files -z'), "\0")), explode("\0", trim((string)shell_exec('git -C ' . escapeshellarg($app) . ' ls-files -z --others --exclude-standard'), "\0")));
foreach ($files as $rel) { if ($rel === '' || preg_match('~(^|/)(tests|docs)/|\.db$~', $rel) || !is_file("$app/$rel")) continue; @mkdir(dirname("$s/$rel"), 0755, true); copy("$app/$rel", "$s/$rel"); }
@mkdir("$s/private/key", 0700, true); file_put_contents("$s/private/key/secret.key", bin2hex(random_bytes(32)) . "\n");
exec('cd / && printf "fixture-pass-123\n" | OEV_ADMIN_EMAIL=admin@example.invalid ' . escapeshellarg($php) . ' ' . escapeshellarg("$s/collector/setup_auth.php") . ' 2>&1', $o, $rc);
ck('setup_auth ok, adds users.n_sessions_since', $rc === 0 && strpos(implode("\n", $o), 'users.n_sessions_since added') !== false);
$c = new PDO("sqlite:$s/data/openevse.db"); $c->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $c->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$c->exec("UPDATE users SET n_sessions = 0, n_sessions_since = NULL WHERE id = 1");
$c->exec("INSERT INTO load_groups (id, name) VALUES (1, 'G1'), (2, 'G2')");
$c->exec("INSERT INTO evses (id, name, evseurl, loadgroupid, enabled) VALUES (4, 'N Penn: 1F <b>', 'http://192.0.2.4/', 1, 1), (5, 'Other: 9X', 'http://192.0.2.5/', 2, 1)");
$c->exec("INSERT INTO users (id, email, pass_hash, role, scope_all, n_sessions) VALUES (2, 'viewer@example.invalid', 'x', 'viewer', 0, 1)"); $c->exec('INSERT INTO user_groups VALUES (2, 1)');
$tok = array(); foreach (array(1, 2) as $uid) { $sel = bin2hex(random_bytes(8)); $sec = bin2hex(random_bytes(24)); $c->prepare('INSERT INTO auth_tokens (selector, token_hash, user_id, created_at, last_used_at) VALUES (?,?,?,?,?)')->execute(array($sel, hash('sha256', $sec), $uid, time(), time())); $tok[$uid] = "$sel.$sec"; }
$port = 20000 + getmypid() % 20000;
$srv = proc_open(array($php, '-S', "127.0.0.1:$port", '-t', $s), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pp);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$CS = str_repeat('c', 32);
function http($path, $uid, $post = null, $csrf = true){
	global $port, $tok, $CS;
	$ck = array('oev_csrf=' . $GLOBALS['CS']); if ($uid) $ck[] = 'oev_auth=' . $tok[$uid];
	$h = array('Cookie: ' . implode('; ', $ck));
	$o = array('http' => array('method' => $post === null ? 'GET' : 'POST', 'header' => $h, 'ignore_errors' => true, 'follow_location' => 0));
	if ($post !== null) { if ($csrf) $post['csrf'] = $CS; $o['http']['content'] = http_build_query($post); $o['http']['header'][] = 'Content-Type: application/x-www-form-urlencoded'; }
	$b = @file_get_contents("http://127.0.0.1:$port/$path", false, stream_context_create($o));
	preg_match('~ (\d{3})~', $http_response_header[0] ?? '', $m); $loc = ''; foreach ($http_response_header ?? array() as $x) if (stripos($x, 'Location:') === 0) $loc = trim(substr($x, 9));
	return array((int)($m[1] ?? 0), (string)$b, $loc);
}
$U = function($id) use ($c){ return $c->query("SELECT * FROM users WHERE id = $id")->fetch(); };
$save = function($on, $extra = array()) { return http('settings.php?s=users', 1, array('a' => 'user_save', 'id' => 1, 'email' => 'admin@example.invalid', 'role' => 'admin', 'scope' => 'all', 'first_name' => 'Greg', 'last_name' => '', 'password' => '', 'n_errors' => 'on') + ($on ? array('n_sessions' => 'on') : array()) + $extra); };

// notifier library on this config DB (stub mailer)
foreach (array('sessions_lib.php', 'settings_lib.php') as $x) require_once "$s/$x";
require_once "$s/collector/session_logic.php"; require_once "$s/collector/notify_sessions_lib.php";
$sdb = new PDO('sqlite::memory:'); $sdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $sdb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); sess_schema($sdb);
$OUT = array(); $FAIL = 0; $mail = function($to, $sub, $b) use (&$OUT, &$FAIL){ if ($FAIL > 0 && strpos($to, 'admin@') === 0) { $FAIL--; return 'smtp down'; } $OUT[] = "$to|$sub"; return true; };
$units = array(); foreach ($c->query('SELECT id, name, loadgroupid FROM evses') as $u) $units[(int)$u['id']] = $u;
$users = function() use ($c){ $o = $c->query('SELECT * FROM users')->fetchAll(); foreach ($o as &$u) { $u['groups'] = array_map('intval', $c->query('SELECT group_id FROM user_groups WHERE user_id = ' . (int)$u['id'])->fetchAll(PDO::FETCH_COLUMN)); $u['ovr'] = oev_unit_notify_rows($c, (int)$u['id']); } unset($u); return $o; };
$st = array(); $nop = function(){}; ns_baseline($st, $sdb, time() - 3600, $nop);
$sum = function($now) use ($sdb, $users, $units, &$st, $mail, $nop){ ns_summaries($sdb, $users(), $units, $st, $now, true, $mail, $nop); };
$unplug = function($unit, $at) use ($sdb){ $sdb->prepare('INSERT INTO sessions (unit_id, unit_name, plug_in_at, open, unplug_at, kwh, charge_seconds) VALUES (?,?,?,0,?,1.0,600)')->execute(array($unit, "U$unit", $at - 3000, $at)); };
$mine = function() use (&$OUT){ return count(array_filter($OUT, function($m){ return strpos($m, 'admin@example.invalid|') === 0; })); };

/* 1: account Session summary Off -> unplug -> turned On in Settings > Users -> no old summary */
$t0 = time(); $unplug(4, $t0 - 30);
list($code, , $loc) = $save(true);
ck('Settings > Users save (real route): redirect', $code === 303 && strpos($loc, 's=users') !== false);
$u = $U(1); ck('turning Session summary on stamps the time', (int)$u['n_sessions'] === 1 && (int)$u['n_sessions_since'] >= $t0);
$sum(time()); ck('the unplug before it was turned on: no summary', $mine() === 0);
sleep(1); $unplug(4, time()); $sum(time() + 5); ck('the next unplug: summary sent', $mine() === 1);
$since = (int)$U(1)['n_sessions_since']; sleep(1); $save(true, array('first_name' => 'G'));
ck('an unrelated save (still on) keeps the time', (int)$U(1)['n_sessions_since'] === $since);
$save(false); ck('turning it off clears the time', (int)$U(1)['n_sessions'] === 0 && $U(1)['n_sessions_since'] === null);
ck('legacy: on with no time (before this column) still means on with no limit', oev_notify_account(array('n_sessions' => 1, 'n_sessions_since' => null), 'sessions') === array('on' => true, 'since' => 0));
// queued retry, then turned off and on again: the old one is not sent
$save(true); $FAIL = 9; sleep(1); $tq = time(); $unplug(4, $tq); $sum($tq + 5); $q = $mine();
$save(false); $sum($tq + 4000); $save(true); $FAIL = 0; $sum($tq + 9000);
ck('queued summary, account turned off and on again: not sent', $mine() === $q);
$save(false); $save(true); $sl = (int)$U(1)['n_sessions_since']; $unplug(4, $sl); $sum($sl + 10);
ck('unplug in the same second it was turned on: not sent (could be before it)', $mine() === $q);
$unplug(4, $sl + 1); $sum($sl + 20); ck('unplug the next second: sent', $mine() === $q + 1);
$c->exec("UPDATE users SET n_sessions = 1, n_sessions_since = NULL WHERE id = 1"); $save(true, array('first_name' => 'Legacy'));
ck('legacy account (on, no time): an unchanged save keeps it as it was', (int)$U(1)['n_sessions'] === 1 && $U(1)['n_sessions_since'] === null);
$save(true);
// concurrency: a separate process reads the row continuously while the real page toggles it; it must never see on without a time
$c->exec("UPDATE users SET n_sessions = 0, n_sessions_since = NULL WHERE id = 1");
$rd = "$s/reader.php"; file_put_contents($rd, '<?php $d = new PDO("sqlite:' . "$s/data/openevse.db" . '"); $d->setAttribute(PDO::ATTR_TIMEOUT, 5); $bad = 0; $seen = 0; $end = microtime(true) + 6; '
	. 'while (microtime(true) < $end && !is_file("' . "$s/stop" . '")) { try { $r = $d->query("SELECT n_sessions, n_sessions_since FROM users WHERE id = 1")->fetch(PDO::FETCH_NUM); $seen++; if ((int)$r[0] === 1 && $r[1] === null) $bad++; } catch (Exception $x) {} } echo "$seen $bad";');
$rp = proc_open(array($php, $rd), array(1 => array("pipe", "w")), $rpp); usleep(400000);   // reader running before the saves
for ($i = 0; $i < 30; $i++) $save($i % 2 === 0);
touch("$s/stop"); $res = explode(' ', stream_get_contents($rpp[1])); proc_close($rp);
ck("concurrent reader during 30 real saves never sees Session summary on without its time ({$res[0]} reads)", (int)$res[0] > 50 && (int)$res[1] === 0);
$save(true);

/* 2: Settings > Units: a clearly labelled link to each unit's Your notifications, outside the unit's Save form */
list($code, $b) = http('settings.php?s=units', 1);
$p = strpos($b, 'href="unit.php?unitid=4#yn"');
ck('Settings > Units has "Your notifications" for unit 4', $code === 200 && $p !== false && strpos($b, 'Your notifications for N Penn: 1F &lt;b&gt;') !== false);
$fo = strrpos(substr($b, 0, $p), '<form'); $fc = strrpos(substr($b, 0, $p), '</form>');
ck('the link is outside the unit form, name HTML-escaped', $fc !== false && $fc > $fo && strpos($b, 'Your notifications for N Penn: 1F <b>') === false);
ck('Settings > Units links unit 5 too', strpos($b, 'href="unit.php?unitid=5#yn"') !== false);

/* 3: notify_prefs.php and notifications.php over HTTP */
list($code) = http('notify_prefs.php', 2, array('unitid' => '4', 'n_sessions' => 'off', 'n_stops' => 'on'), false);
ck('notify_prefs: no CSRF token -> 400, nothing saved', $code === 400 && !$c->query('SELECT count(*) FROM user_unit_notify')->fetchColumn());
list($code, , $loc) = http('notify_prefs.php', 2, array('unitid' => '4', 'n_sessions' => 'off', 'n_stops' => 'on', 'user_id' => '1', 'id' => '1'));
$r = $c->query('SELECT user_id, unit_id, kind, mode FROM user_unit_notify ORDER BY kind')->fetchAll();
ck('notify_prefs (view-only login): saved for that login only, back to the unit page', $code === 303 && strpos($loc, 'unit.php?unitid=4') === 0 && count($r) === 2 && $r[0]['user_id'] == 2 && $r[1]['user_id'] == 2);
list($code) = http('notify_prefs.php', 2, array('unitid' => '5', 'n_sessions' => 'on', 'n_stops' => 'on'));
ck('notify_prefs: a unit outside the login\'s groups is refused', (int)$c->query('SELECT count(*) FROM user_unit_notify WHERE unit_id = 5')->fetchColumn() === 0);
list($code, , $loc) = http('notify_prefs.php', 0, array('unitid' => '4', 'n_sessions' => 'on', 'n_stops' => 'on'));
ck('notify_prefs: not logged in -> login page', $code === 303 && strpos($loc, 'login.php') === 0);
list($code, $b) = http('notifications.php', 2);
ck('overview (view-only): own choices, only unit 4, nobody else\'s address', $code === 200 && strpos($b, 'unitid=4#yn') !== false && strpos($b, 'unitid=5') === false && strpos($b, 'admin@example.invalid') === false && strpos($b, 'Set for this unit') !== false);
list($code, , $loc) = http('notifications.php', 0); ck('overview: not logged in -> login page', $code === 303 && strpos($loc, 'login.php') === 0);
list($code, $b) = http('settings.php?s=units', 2); ck('Settings stays admin-only for the view-only login', $code === 403);

proc_terminate($srv); proc_close($srv); exec('rm -rf ' . escapeshellarg($s));
echo $f ? "$f of $n FAILED\n" : "all $n passed\n"; exit($f ? 1 : 0);
