<?php
// php tests/demo_login_test.php - the one-click demo login, end to end: a throwaway copy of the app (setup_auth,
// then collector/demo_user.php on) served by PHP's built-in web server. The demo can read the view pages but
// every write is refused server-side; a real admin login still works. Synthetic: throwaway key and DBs.
$f = 0; function ck($n, $ok){ global $f; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . "\n"; }
$php = PHP_BINARY; $app = dirname(__DIR__);
$s = sys_get_temp_dir() . '/oev_demo_' . getmypid();
foreach (explode("\0", trim(shell_exec('git -C ' . escapeshellarg($app) . ' ls-files -z'), "\0")) as $rel) {
	if ($rel === '' || preg_match('~(^|/)(tests|docs)/|\.db$~', $rel)) continue;
	@mkdir(dirname("$s/$rel"), 0755, true); copy("$app/$rel", "$s/$rel");
}
@mkdir("$s/private/key", 0700, true); file_put_contents("$s/private/key/secret.key", bin2hex(random_bytes(32)) . "\n");
exec('cd / && printf "fixture-pass-123\n" | OEV_ADMIN_EMAIL=admin@example.invalid ' . escapeshellarg($php) . ' ' . escapeshellarg("$s/collector/setup_auth.php") . ' 2>&1', $o, $rc);
ck('setup_auth exit 0', $rc === 0);
$cli = function($a) use ($php, $s){ exec(escapeshellarg($php) . ' ' . escapeshellarg("$s/collector/demo_user.php") . ' ' . $a . ' 2>&1', $o, $rc); return array($rc, implode("\n", $o)); };
list($rc, $out) = $cli('status'); ck('status before: off', $rc === 0 && strpos($out, 'demo login off') === 0);
list($rc, $out) = $cli('on'); ck('on: made + on', $rc === 0 && strpos($out, 'demo user made') !== false && strpos($out, 'demo login on') !== false);
list($rc, $out) = $cli('on'); ck("on again: reset, same user", $rc === 0 && strpos($out, 'demo user reset') !== false);
$db = new PDO('sqlite:' . "$s/data/openevse.db"); $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$du = $db->query("SELECT * FROM users WHERE email = 'demo@demo.invalid'")->fetch();
ck('demo row: viewer, all units, no emails', $du && $du['role'] === 'viewer' && (int)$du['scope_all'] === 1 && !(int)$du['n_errors'] && !(int)$du['n_reboots'] && !(int)$du['n_sessions'] && !(int)$du['n_reminders']);
$hash0 = $du['pass_hash'];

$port = 21000 + getmypid() % 30000;
$srv = proc_open(array($php, '-S', "127.0.0.1:$port", '-t', $s), array(array('file', '/dev/null', 'r'), array('file', '/dev/null', 'w'), array('file', '/dev/null', 'w')), $pp);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$base = "http://127.0.0.1:$port/";
// a tiny browser: one cookie jar per curl handle
$req = function($ch, $path, $post = null) use ($base){
	curl_setopt_array($ch, array(CURLOPT_URL => $base . $path, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 20));
	if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); } else curl_setopt($ch, CURLOPT_HTTPGET, true);
	$r = curl_exec($ch); $h = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	return array((int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), substr((string)$r, $h), substr((string)$r, 0, $h));
};
$cookie = function($ch, $name){ foreach (curl_getinfo($ch, CURLINFO_COOKIELIST) as $l) { $p = explode("\t", $l); if (($p[5] ?? '') === $name) return $p[6]; } return ''; };
$tokens = function() use ($db, $du){ return (int)$db->query('SELECT count(*) FROM auth_tokens WHERE user_id = ' . (int)$du['id'])->fetchColumn(); };

$v = curl_init();
list($c) = $req($v, 'settings.php'); ck('logged out: settings.php sends to login', $c === 303);
list($c, , $hd) = $req($v, 'demo.php'); ck('demo.php: 303 to home + login cookie', $c === 303 && $cookie($v, 'oev_auth') !== '' && stripos($hd, 'X-Robots-Tag: noindex') !== false);
ck('one demo login row', $tokens() === 1);
list($c) = $req($v, 'demo.php'); ck('demo.php again while logged in: no new login', $c === 303 && $tokens() === 1);
foreach (array('index.php', 'sessions.php', 'diagnostics.php', 'help.php') as $p) { list($c, $b) = $req($v, $p); ck("demo reads $p (200)", $c === 200 && strpos($b, 'Demo Visitor') !== false); }
list($c) = $req($v, 'report7.php?json=1'); ck('demo reads report7.php?json=1 (200)', $c === 200);
list($c, $b) = $req($v, 'index.php'); ck('avatar menu: no email, no Account Preferences', strpos($b, 'demo@demo.invalid') === false && strpos($b, 'Account Preferences') === false && strpos($b, 'Demo: view only') !== false);
list($c, $b) = $req($v, 'account.php'); ck('account.php: demo page, no forms to change things', $c === 200 && strpos($b, 'Demo login') !== false && strpos($b, 'Change password') === false && strpos($b, 'name="email"') === false && strpos($b, 'logout_all') === false);
foreach (array('settings.php', 'unitlist.php', 'action.php', 'settings.php?s=system') as $p) { list($c) = $req($v, $p); ck("demo refused $p (403)", $c === 403); }
$csrf = $cookie($v, 'oev_csrf'); ck('csrf cookie present', strlen($csrf) === 32);
$posts = array(
	array('account.php', array('a' => 'password', 'current' => 'x', 'new' => 'abcdefgh1', 'new2' => 'abcdefgh1')),
	array('account.php', array('a' => 'name', 'first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@example.invalid')),
	array('account.php', array('a' => 'notify_defaults', 'n_errors' => 'on')),
	array('account.php', array('a' => 'rem_add', 'unit' => '1', 'at' => '21:00')),
	array('account.php', array('a' => 'logout_all')),
	array('notify_prefs.php', array('unitid' => '1')),
	array('settings.php', array('a' => 'x')),
	array('action.php', array('a' => 'x')),
	array('index.php', array('a' => 'x')),
);
foreach ($posts as $q) { list($c, $b) = $req($v, $q[0], $q[1] + array('csrf' => $csrf)); ck("demo POST {$q[0]} a=" . ($q[1]['a'] ?? '-') . ' refused (403)', $c === 403); }
$du2 = $db->query('SELECT * FROM users WHERE id = ' . (int)$du['id'])->fetch();
ck('demo row unchanged after the POSTs', $du2['email'] === 'demo@demo.invalid' && $du2['pass_hash'] === $hash0 && !(int)$du2['n_errors'] && $du2['first_name'] === 'Demo');
ck('demo still logged in (logout_all refused)', $tokens() === 1);
list($c, $b) = $req($v, 'login.php'); ck('login.php shows the form to a demo visitor', $c === 200 && strpos($b, 'name="password"') !== false);

// a real admin still works, and demo.php doesn't replace an admin login
$a = curl_init(); $req($a, 'login.php');
list($c) = $req($a, 'login.php', array('email' => 'admin@example.invalid', 'password' => 'fixture-pass-123', 'csrf' => $cookie($a, 'oev_csrf'), 'next' => '/settings.php'));
ck('admin logs in', $c === 303);
list($c) = $req($a, 'settings.php'); ck('admin opens settings.php (200)', $c === 200);
$ac = $cookie($a, 'oev_auth'); $req($a, 'demo.php'); ck('demo.php keeps an admin login', $cookie($a, 'oev_auth') === $ac);
list($c) = $req($a, 'settings.php'); ck('still admin after demo.php', $c === 200);
$db->exec("UPDATE users SET role = 'admin' WHERE id = " . (int)$du['id']);
list($c) = $req($v, 'settings.php'); ck('demo row turned admin by mistake: still view only', $c === 403);
$db->exec("UPDATE users SET role = 'viewer' WHERE id = " . (int)$du['id']);

list($c) = $req($v, 'account.php', array('a' => 'logout', 'csrf' => $csrf)); ck('demo can log out on this device', $c === 303 && $tokens() === 0);
$req($v, 'demo.php'); ck('demo.php logs in again', $tokens() === 1);
list($rc, $out) = $cli('off'); ck("off: demo logins removed", $rc === 0 && $tokens() === 0);
$w = curl_init(); list($c) = $req($w, 'demo.php'); ck('off: demo.php 404', $c === 404 && $cookie($w, 'oev_auth') === '');
list($c) = $req($v, 'index.php'); ck('off: old demo cookie no longer works', $c === 303);

proc_terminate($srv); proc_close($srv);
exec('rm -rf ' . escapeshellarg($s));
echo $f ? "$f failed\n" : "ok\n"; exit($f ? 1 : 0);
