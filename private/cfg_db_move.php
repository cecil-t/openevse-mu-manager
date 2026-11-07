<?php
/******************************************************************************
 * private/cfg_db_move.php - move the config DB (openevse.db) into data/, or back.
 * MAINTENANCE ONLY. Run as the web user (the file's owner), never from the web:
 *   sudo -n -u http php private/cfg_db_move.php --check | --move | --rollback | --verify
 *   options: --lock-wait=SEC (default 120)  --min-code-age=SEC (default 0)  --site=DIR (tests)
 *            --no-coordinator-check (only for a site with no load balancer)
 *
 * Writer exclusion (db_path.php): every write-capable connection holds a SHARED
 * flock on data/cfg_db.lock from before it resolves the path until its process ends.
 * This script holds it EXCLUSIVE from before the copy until after publication, so no
 * cooperating writer can have the old file open for writing (not even inside an
 * uncommitted transaction) while it is copied and replaced. Not a timer.
 * While data/cfg_db_maint exists new connections open read-only (pages keep
 * serving; saves fail loudly); writers that started earlier are waited for.
 *
 * Enforced preconditions (refuses otherwise):
 *  - source is a regular file; no target; SQLite says not WAL; once the lock is held:
 *    no -wal/-shm/-journal file
 *  - run as the file owner; no maintenance flag already present
 *  - coordinator heartbeat is fresh and has cfg_resolver=1 (it re-resolves the path)
 *  - no jobs/notifier/setup process started before the current code was written
 *    (so no pre-contract CLI process can still be writing); optional --min-code-age
 *  - with the lock held: no other process has the database file open for writing (in
 *    /proc as the web user, retried until --lock-wait). Every later opener runs the
 *    new code (PHP opcache revalidates changed files within seconds).
 *  - EXCLUSIVE lock obtained within --lock-wait seconds (default 120), else abort
 *  - with the lock held, SQLite grants BEGIN IMMEDIATE at once (no hidden writer
 *    outside the contract holds a write transaction right now)
 * Documented, NOT enforceable here: web requests that began before the code deploy
 * have ended (PHP max_execution_time); nothing outside this app writes openevse.db.
 *
 *  copy: sha256, VACUUM INTO, sha256 again (must match), quick_check, per-table row
 *  counts, fsync. publish: rename copy -> target, rename source aside into data/.
 *  Then remove the flag, then release the lock. On any failure before publication
 *  nothing is moved and the flag is removed; if publication itself fails half way
 *  the flag is LEFT ON (exit 6) so nothing can write until fixed by hand.
 * No symlink is left behind: after --move the site folder has no openevse.db.
 ******************************************************************************/
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$o = array('lock-wait' => 120, 'min-code-age' => 0, 'site' => dirname(__DIR__), 'coord' => true); $mode = null;
foreach (array_slice($argv, 1) as $a) {
	if (preg_match('/^--(lock-wait|min-code-age)=(\d+(\.\d+)?)$/', $a, $m)) $o[$m[1]] = (float)$m[2];
	elseif (preg_match('/^--site=(.+)$/', $a, $m)) $o['site'] = rtrim($m[1], '/');
	elseif ($a === '--no-coordinator-check') $o['coord'] = false;
	elseif (in_array($a, array('--check', '--move', '--rollback', '--verify'), true)) $mode = $a;
	else { fwrite(STDERR, "unknown option $a\n"); exit(2); }
}
$mode = $mode ?: '--check';
$site = $o['site']; $root = "$site/openevse.db"; $data = "$site/data/openevse.db"; $flag = "$site/data/cfg_db_maint"; $ts = date('Ymd-His');
function out($m){ echo $m, "\n"; }
function fail($m, $c = 1){ fwrite(STDERR, "cfg_db_move: $m\n"); exit($c); }
function sha($f){ clearstatcache(true, $f); return is_file($f) ? hash_file('sha256', $f) : null; }
function ro($f){ return new PDO('sqlite:' . $f, null, null, array(PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10)); }
function counts($f){ $d = ro($f); $r = array(); foreach ($d->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) $r[$t] = (int)$d->query('SELECT count(*) FROM "' . str_replace('"', '""', $t) . '"')->fetchColumn(); $d = null; return $r; }
function holders($f){   // other processes with $f open for writing (same user's /proc fds; root-owned ones are not visible)
	$rp = realpath($f); $me = getmypid(); $n = array();
	foreach (glob('/proc/[0-9]*/fd/*') ?: array() as $l) { $pid = (int)explode('/', $l)[2]; if ($pid === $me) continue; if (@readlink($l) !== $rp) continue;
		$fi = (string)@file_get_contents(str_replace('/fd/', '/fdinfo/', $l)); $fl = preg_match('/^flags:\s*([0-7]+)/m', $fi, $m) ? octdec($m[1]) : 2;
		if (($fl & 3) !== 0) $n[$pid] = 1; }   // only handles that can write (O_WRONLY/O_RDWR) block; read-only ones cannot write
	return array_keys($n);
}
function old_writers($site, $since){   // jobs/notifier/setup processes for this site started before $since (Linux /proc)
	$n = 0; foreach (glob('/proc/[0-9]*/cmdline') ?: array() as $c) { $s = str_replace("\0", ' ', (string)@file_get_contents($c));
		if (strpos($s, $site . '/collector/') !== false && preg_match('~/collector/(jobs|notifier|setup_auth)\.php~', $s) && (int)@filemtime(dirname($c)) < $since) $n++; }
	return $n;
}

if ($mode === '--verify') {
	$n = 0; foreach (array_merge(glob("$site/data/openevse.db.premove-*.sha256") ?: array(), glob("$site/data/openevse.db.rolledback-*.sha256") ?: array()) as $s) { $f = substr($s, 0, -7); $ok = is_file($f) && trim((string)file_get_contents($s)) === sha($f); out(($ok ? 'unchanged ' : 'CHANGED ') . basename($f)); if (!$ok) $n++; }
	exit($n ? 3 : 0);
}
$src = $mode === '--rollback' ? $data : $root; $dst = $mode === '--rollback' ? $root : $data;
$aside = $mode === '--rollback' ? "$site/data/openevse.db.rolledback-$ts" : "$site/data/openevse.db.premove-$ts";
// 1. preflight
if (!is_dir("$site/data")) fail('data/ folder missing');
if (!is_file($src) || is_link($src)) fail('source is not a regular file: ' . substr($src, strlen($site) + 1));
if (file_exists($dst) || is_link($dst)) fail('target already exists: ' . substr($dst, strlen($site) + 1));
if (function_exists('posix_geteuid') && posix_geteuid() !== fileowner($src)) fail('run as the file owner (the web user)');
if (file_exists($flag)) fail('maintenance flag already present (another run?): data/cfg_db_maint');
$d = ro($src); $jm = strtolower((string)$d->query('PRAGMA journal_mode')->fetchColumn()); $d = null;
if ($jm === 'wal') fail('journal_mode is WAL; not supported');
$st = stat($src);
out(sprintf('%s: %s (%d bytes, mode %o, uid %d, journal_mode %s) -> %s', $mode, substr($src, strlen($site) + 1), $st['size'], $st['mode'] & 0777, $st['uid'], $jm, substr($dst, strlen($site) + 1)));
if ($o['coord'] && $mode !== '--check') {
	$hb = @json_decode((string)@file_get_contents("$site/loadbalancer/run/heartbeat.json"), true);
	if (!is_array($hb) || empty($hb['cfg_resolver']) || time() - (int)($hb['time'] ?? 0) > 180) fail('coordinator is not running the db_path.php version (heartbeat has no fresh cfg_resolver); restart it first', 4);
}
$code = 0; foreach (array('db_path.php', 'auth_lib.php', 'settings_lib.php', 'connection.php', 'include_v5.php', 'collector/jobs.php', 'collector/notifier.php', 'collector/setup_auth.php') as $cf) $code = max($code, (int)@filemtime("$site/$cf"));
if ($mode !== '--check') {
	if (time() - $code < $o['min-code-age']) fail('lock-contract code is only ' . (time() - $code) . ' s old; wait until --min-code-age', 4);
	if (old_writers($site, $code)) fail('a jobs/notifier/setup process started before the current code; wait for it', 4);
}
if ($mode === '--check') exit(0);

// 2. maintenance flag (new connections read-only), then the EXCLUSIVE writer lock
if (!@touch($flag)) fail('cannot create data/cfg_db_maint');
$tmp = dirname($dst) . '/.openevse.db.tmp-' . getmypid();
$lk = @fopen("$site/data/cfg_db.lock", 'c');
$abort = function($m, $c = 1) use ($flag, $tmp, &$lk){ @unlink($tmp); @unlink($flag); if ($lk) { flock($lk, LOCK_UN); fclose($lk); } fail($m . ' (aborted; nothing moved; maintenance flag removed)', $c); };
if (!$lk) $abort('cannot open data/cfg_db.lock');
$until = microtime(true) + $o['lock-wait'];
while (!flock($lk, LOCK_EX | LOCK_NB)) { if (microtime(true) > $until) $abort('writers still hold the config DB after ' . $o['lock-wait'] . ' s', 5); usleep(100000); }
while ($hp = holders($src)) { if (microtime(true) > $until) $abort('processes still have the database open: pids ' . implode(',', $hp), 5); usleep(100000); }
// 3. copy under the lock
foreach (array('-wal', '-shm', '-journal') as $x) if (file_exists($src . $x)) $abort("source has a $x file with all contract writers gone (WAL mode or a writer outside the contract)", 3);
try {
	$w = new PDO('sqlite:' . $src, null, null, array(PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READWRITE, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 0));
	$w->exec('BEGIN IMMEDIATE'); $w->exec('ROLLBACK'); $w = null;
} catch (Exception $e) { $w = null; $abort('a writer outside the lock contract holds the database: ' . $e->getMessage(), 3); }
$sum = sha($src);
try { $d = ro($src); $d->exec("VACUUM INTO " . $d->quote($tmp)); $d = null; } catch (Exception $e) { $d = null; $abort('VACUUM INTO failed: ' . $e->getMessage()); }
if (sha($src) !== $sum) $abort('source changed during the copy (a writer outside the lock contract)', 3);
try { $d = ro($tmp); $qc = $d->query('PRAGMA quick_check')->fetchColumn(); $d = null; $same = counts($src) === counts($tmp); } catch (Exception $e) { $d = null; $qc = $e->getMessage(); $same = false; }
if ($qc !== 'ok' || !$same) $abort('copy check failed: ' . $qc);
$h = fopen($tmp, 'r+'); $synced = $h && fsync($h); if ($h) fclose($h);
if (!$synced) $abort('fsync failed');
chmod($tmp, $st['mode'] & 0777);
// 4. publish (target appears before the source leaves: the path never disappears)
if (!rename($tmp, $dst)) $abort('publish rename failed');
if (!rename($src, $aside)) {
	if (rename($dst, $tmp)) $abort('could not move the old file aside; publication undone');
	fail('publication half done and could not be undone: maintenance flag and lock left; fix by hand', 6);   // flag stays (lock ends with this process)
}
if (sha($aside) !== $sum) fail('old file changed during publication: maintenance flag left on; compare by hand', 6);
file_put_contents("$aside.sha256", $sum . "\n");
@unlink($flag); flock($lk, LOCK_UN); fclose($lk);
out('done: ' . substr($dst, strlen($site) + 1) . ' is live; old file kept as data/' . basename($aside) . ' (re-check later with --verify)');
