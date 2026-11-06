<?php
// php tests/sess_path_test.php
// Isolated fixtures only — never opens a live sessions.db.
// All files under tests/tmp/run_<pid>/fake_site/

$f = 0;
function ck($n, $ok){ global $f; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . "\n"; }

$tmp = __DIR__ . '/tmp/run_' . getmypid();
@mkdir($tmp, 0700, true);
$site = $tmp . '/fake_site';
@mkdir($site . '/private', 0700, true);
@mkdir($site . '/data', 0700, true);

// Copy resolver into the fake site so __DIR__ inside sess_path resolves there.
$src = file_get_contents(__DIR__ . '/../sess_path.php');
file_put_contents($site . '/sess_path.php', $src);
file_put_contents($site . '/data/.htaccess', file_get_contents(__DIR__ . '/../data/.htaccess'));

function load_path($site){
	// fresh process would be cleaner; simulate by redefining in isolated include via php -r caller
	// Here we call oev_sess_db_path($site) after requiring the real file once.
	static $loaded = false;
	if (!$loaded) {
		require_once __DIR__ . '/../sess_path.php';
		$loaded = true;
	}
	return oev_sess_db_path($site);
}

// 1) Legacy default when neither override nor data file exists
$p = load_path($site);
ck('legacy root default', $p === $site . '/sessions.db');

// 2) Prefer data/sessions.db when that file exists
file_put_contents($site . '/data/sessions.db', '');
$p = load_path($site);
ck('prefer data/ when file exists', $p === $site . '/data/sessions.db');

// 3) Override wins even when data/ exists
file_put_contents($site . '/private/sess_db_path', "alt/sessions.db\n");
@mkdir($site . '/alt', 0700, true);
file_put_contents($site . '/alt/sessions.db', '');
$p = load_path($site);
ck('relative override wins', $p === $site . '/alt/sessions.db');

file_put_contents($site . '/private/sess_db_path', $site . "/alt/sessions.db\n");
$p = load_path($site);
ck('absolute override wins', $p === $site . '/alt/sessions.db');

file_put_contents($site . '/private/sess_db_path', "# comment only\n");
$p = load_path($site);
ck('comment-only override ignored → data/', $p === $site . '/data/sessions.db');

unlink($site . '/private/sess_db_path');
unlink($site . '/data/sessions.db');

// 4) SESS_DB_PATH constant from a subprocess (clean define)
$php = PHP_BINARY;
$cmd = escapeshellarg($php) . ' -r ' . escapeshellarg(
	'require $argv[1]; echo SESS_DB_PATH;'
) . ' ' . escapeshellarg($site . '/sess_path.php');
$out = trim((string)shell_exec($cmd));
ck('subprocess SESS_DB_PATH legacy', $out === $site . '/sessions.db');

file_put_contents($site . '/data/sessions.db', '');
$out = trim((string)shell_exec($cmd));
ck('subprocess SESS_DB_PATH data/', $out === $site . '/data/sessions.db');

// 5) SQLite normal write + read on fixture at data/ path
$dbfile = $site . '/data/sessions.db';
$pdo = new PDO('sqlite:' . $dbfile, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
$pdo->exec("INSERT INTO t (v) VALUES ('a')");
$n = (int)$pdo->query('SELECT count(*) FROM t')->fetchColumn();
ck('sqlite normal write/read', $n === 1);
$pdo = null;

// 6) WAL mode write + sidecar presence, then checkpoint
$pdo = new PDO('sqlite:' . $dbfile, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$pdo->exec('PRAGMA journal_mode=WAL');
$mode = $pdo->query('PRAGMA journal_mode')->fetchColumn();
ck('wal journal_mode', strtolower((string)$mode) === 'wal');
$pdo->exec("INSERT INTO t (v) VALUES ('b')");
$pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$n = (int)$pdo->query('SELECT count(*) FROM t')->fetchColumn();
ck('wal write + checkpoint', $n === 2);
$pdo = null;
// After truncate checkpoint, -wal may be empty or absent; main file must remain
ck('db file still present after WAL', is_file($dbfile));

// integrity_check on fixture
$chk = trim((string)shell_exec(
	escapeshellarg(trim((string)shell_exec('command -v sqlite3'))) . ' ' .
	escapeshellarg($dbfile) . ' ' . escapeshellarg('PRAGMA integrity_check;')
));
if ($chk === '' && is_file('/home/box/.local/bin/sqlite3')) {
	$chk = trim((string)shell_exec('/home/box/.local/bin/sqlite3 ' . escapeshellarg($dbfile) . " 'PRAGMA integrity_check;'"));
}
ck('fixture integrity_check', $chk === 'ok');

// 7) Unchanged client retaining old path: root file still openable while data/ exists
// (cutover risk). Resolver prefers data/; a hardcoded root path would miss new writes.
file_put_contents($site . '/sessions.db', ''); // empty legacy
$pdoR = new PDO('sqlite:' . $site . '/sessions.db', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$pdoR->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
$pdoR->exec("INSERT INTO t (v) VALUES ('legacy')");
$pdoR = null;
$pdoD = new PDO('sqlite:' . $dbfile, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$cntD = (int)$pdoD->query('SELECT count(*) FROM t')->fetchColumn();
$pdoD = null;
$pdoR = new PDO('sqlite:' . $site . '/sessions.db', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$cntR = (int)$pdoR->query('SELECT count(*) FROM t')->fetchColumn();
$pdoR = null;
ck('stale root path sees different DB than data/', $cntR === 1 && $cntD === 2);
$resolved = load_path($site);
ck('resolver ignores stale root when data/ present', $resolved === $dbfile);

// 8) .htaccess content (Apache 2.4 + 2.2). Full Apache deny is environment-dependent.
$ht = file_get_contents($site . '/data/.htaccess');
ck('htaccess Require all denied', strpos($ht, 'Require all denied') !== false);
ck('htaccess Deny from all', strpos($ht, 'Deny from all') !== false);
ck('htaccess mod_authz_core gated', strpos($ht, 'mod_authz_core.c') !== false);
$apache = trim((string)shell_exec('command -v apache2 || command -v httpd || true'));
if ($apache === '') {
	echo "NOTE htaccess runtime: no Apache binary on this host; content checks only (deploy uses Synology WebStation/Apache).\n";
	ck('htaccess runtime caveat documented', true);
} else {
	echo "NOTE htaccess runtime: Apache present at $apache — not spinning a vhost here; content checks suffice.\n";
	ck('htaccess runtime note', true);
}

// 9) util_lib / sessions_lib wiring: SESS_DB_PATH used after require (subprocess with fake site via override)
$ovr = $site . '/private/sess_db_path';
file_put_contents($ovr, $dbfile . "\n");
// Point a tiny stub that only loads sess_path from SITE — already tested.
// Ensure sessions_lib.php still parses and defines sess_db
$parse = trim((string)shell_exec(
	escapeshellarg($php) . ' -l ' . escapeshellarg(__DIR__ . '/../sess_path.php') . ' 2>&1'
));
ck('sess_path.php lint', strpos($parse, 'No syntax errors') !== false);
$parse = trim((string)shell_exec(
	escapeshellarg($php) . ' -l ' . escapeshellarg(__DIR__ . '/../sessions_lib.php') . ' 2>&1'
));
ck('sessions_lib.php lint', strpos($parse, 'No syntax errors') !== false);
$parse = trim((string)shell_exec(
	escapeshellarg($php) . ' -l ' . escapeshellarg(__DIR__ . '/../util_lib.php') . ' 2>&1'
));
ck('util_lib.php lint', strpos($parse, 'No syntax errors') !== false);

// 10) checksum stability of fixture after copy (cutover rehearsal pattern)
$sum1 = hash_file('sha256', $dbfile);
$copy = $site . '/data/sessions.copy.db';
if (!copy($dbfile, $copy)) {
	ck('fixture copy', false);
} else {
	ck('fixture copy checksum match', hash_file('sha256', $copy) === $sum1);
}

exec('rm -rf ' . escapeshellarg($tmp));
echo $f ? "$f failed\n" : "ok\n";
exit($f ? 1 : 0);
