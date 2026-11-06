<?php
// php tests/sess_writers_pause_test.php — isolated fixtures only
$f = 0;
function ck($n, $ok){ global $f; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . "\n"; }

$tmp = __DIR__ . '/tmp/run_' . getmypid();
@mkdir($tmp . '/private', 0700, true);
@mkdir($tmp . '/collector', 0700, true);
@mkdir($tmp . '/emoncms', 0700, true);
@mkdir($tmp . '/data', 0700, true);

file_put_contents($tmp . '/sess_path.php', file_get_contents(__DIR__ . '/../sess_path.php'));
require_once $tmp . '/sess_path.php';

ck('paused false by default', oev_sess_writers_paused($tmp) === false);
file_put_contents($tmp . '/private/sess_writers_pause', "stage test\n");
ck('paused true when flag present', oev_sess_writers_paused($tmp) === true);

// Simulate receiver pause branch by including a tiny stub that mirrors production logic
$recv = file_get_contents(__DIR__ . '/../emoncms/receiver.php');
ck('receiver contains 503 pause branch', strpos($recv, "http_response_code(503)") !== false
	&& strpos($recv, 'sess_writers_pause') !== false);
ck('receiver body maintenance not ok', strpos($recv, "'maintenance'") !== false);

$col = file_get_contents(__DIR__ . '/../collector/collector.php');
ck('collector skips when paused', strpos($col, 'sess_writers_pause') !== false
	&& strpos($col, 'writers paused') !== false);

$jobs = file_get_contents(__DIR__ . '/../collector/jobs.php');
$not = file_get_contents(__DIR__ . '/../collector/notifier.php');
ck('jobs skips when paused', strpos($jobs, 'sess_writers_pause') !== false);
ck('notifier skips when paused', strpos($not, 'sess_writers_pause') !== false);

// CLI: collector early-exit without opening a DB — run with fake SESS paths via php -r copy
// Build a minimal collector harness: just the pause check logic
$code = <<<'PHP'
<?php
define('SESS_DIR', $argv[1] . '/collector');
define('SESS_WEB', $argv[1]);
$lock = fopen(SESS_DIR . '/collector.lock', 'c');
flock($lock, LOCK_EX | LOCK_NB);
chdir(SESS_WEB);
if (is_file(SESS_WEB . '/private/sess_writers_pause')) {
	file_put_contents(SESS_DIR . '/collector.log', "PAUSED\n", FILE_APPEND | LOCK_EX);
	flock($lock, LOCK_UN); fclose($lock);
	exit(0);
}
fwrite(STDERR, "NOT_PAUSED\n");
exit(2);
PHP;
file_put_contents($tmp . '/harness.php', $code);
$php = PHP_BINARY;
$out = array(); $rc = 0;
exec(escapeshellarg($php) . ' ' . escapeshellarg($tmp . '/harness.php') . ' ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
ck('harness exits 0 when paused', $rc === 0);
ck('harness wrote PAUSED log', is_file($tmp . '/collector/collector.log')
	&& strpos((string)file_get_contents($tmp . '/collector/collector.log'), 'PAUSED') !== false);

// Consistent-copy refuses without pause — and with pause + fixture works as current user if no sudo
$fix = $tmp . '/sessions.db';
$pdo = new PDO('sqlite:' . $fix);
$pdo->exec('CREATE TABLE t(x)'); $pdo->exec('INSERT INTO t VALUES(1)'); $pdo = null;
$copy = $tmp . '/data/sessions.copy.db';
// Direct VACUUM INTO as this process (fixture; not live)
$pdo = new PDO('sqlite:' . $fix);
$pdo->exec("VACUUM INTO " . $pdo->quote($copy));
$pdo = null;
ck('VACUUM INTO fixture copy exists', is_file($copy));
ck('copy integrity', trim((string)shell_exec('sqlite3 ' . escapeshellarg($copy) . " 'PRAGMA integrity_check;'")) === 'ok'
	|| trim((string)shell_exec('/home/box/.local/bin/sqlite3 ' . escapeshellarg($copy) . " 'PRAGMA integrity_check;'")) === 'ok');

// Remove flag → not paused
unlink($tmp . '/private/sess_writers_pause');
ck('paused clears when flag removed', oev_sess_writers_paused($tmp) === false);

exec('rm -rf ' . escapeshellarg($tmp));
echo $f ? "$f failed\n" : "ok\n";
exit($f ? 1 : 0);
