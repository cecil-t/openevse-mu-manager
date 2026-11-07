<?php
/******************************************************************************
 * private/cfg_db_move.php - move the config DB (openevse.db) into data/, or back.
 * MAINTENANCE ONLY. Run as the web user (the file's owner), never from the web:
 *   sudo -n -u http php private/cfg_db_move.php --check | --move | --rollback | --verify
 *   options: --drain=SEC (default 30)  --settle=SEC (default 10)  --site=DIR (tests)
 *            --no-coordinator-check (only for a site with no load balancer)
 *
 * Writer contract (db_path.php): while private/cfg_db_maint exists every config DB
 * connection opens read-only, sign-in pauses, token touches and logout deletes are
 * skipped, and collector/jobs.php, notifier.php and setup_auth.php exit at start.
 * So a write during the window FAILS (an error on that page) instead of being lost.
 *
 * --move / --rollback:
 *  1. preflight: regular source file, no target, no -wal/-shm/-journal, journal_mode
 *     read from SQLite itself must not be WAL, run as the owner, and (move/rollback)
 *     the coordinator's heartbeat must say it resolves the path itself (cfg_resolver).
 *  2. create the maintenance flag; wait until no jobs/notifier/setup process runs,
 *     then --drain seconds for web requests that opened before the flag.
 *  3. sha256 of the source, copy with SQLite's VACUUM INTO (no file locks are held
 *     by this process during hashing, so SQLite's POSIX locks are never dropped),
 *     sha256 again (must match), quick_check + per-table row counts on the copy, fsync.
 *  4. publish: rename the copy to the target (atomic; the source still exists, so the
 *     path never disappears), then rename the source aside inside data/ (deny-all).
 *  5. wait --settle seconds; if the old file changed, a writer that opened before the
 *     flag wrote late: put the old file back, move the copy aside, exit 3. Nothing is
 *     lost because the copy could not be written (read-only window).
 *  6. remove the flag. The old file's sha256 is saved next to it; --verify re-checks
 *     it later (exit 3 if it changed).
 * No symlink is left behind: after --move the site folder has no openevse.db.
 ******************************************************************************/
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$o = array('drain' => 30, 'settle' => 10, 'site' => dirname(__DIR__), 'coord' => true); $mode = null;
foreach (array_slice($argv, 1) as $a) {
	if (preg_match('/^--(drain|settle)=(\d+(\.\d+)?)$/', $a, $m)) $o[$m[1]] = (float)$m[2];
	elseif (preg_match('/^--site=(.+)$/', $a, $m)) $o['site'] = rtrim($m[1], '/');
	elseif ($a === '--no-coordinator-check') $o['coord'] = false;
	elseif (in_array($a, array('--check', '--move', '--rollback', '--verify'), true)) $mode = $a;
	else { fwrite(STDERR, "unknown option $a\n"); exit(2); }
}
$mode = $mode ?: '--check';
$site = $o['site']; $root = "$site/openevse.db"; $data = "$site/data/openevse.db"; $flag = "$site/private/cfg_db_maint"; $ts = date('Ymd-His');
function out($m){ echo $m, "\n"; }
function fail($m, $c = 1){ fwrite(STDERR, "cfg_db_move: $m\n"); exit($c); }
function sha($f){ clearstatcache(true, $f); return is_file($f) ? hash_file('sha256', $f) : null; }
function ro($f){ return new PDO('sqlite:' . $f, null, null, array(PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10)); }
function counts($f){ $d = ro($f); $r = array(); foreach ($d->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) $r[$t] = (int)$d->query('SELECT count(*) FROM "' . str_replace('"', '""', $t) . '"')->fetchColumn(); $d = null; return $r; }
function writers_running($site){   // jobs/notifier/setup processes for this site (Linux /proc; same user can read cmdline)
	$n = 0; foreach (glob('/proc/[0-9]*/cmdline') ?: array() as $c) { $s = str_replace("\0", ' ', (string)@file_get_contents($c)); if (strpos($s, $site . '/collector/') !== false && preg_match('~/collector/(jobs|notifier|setup_auth)\.php~', $s)) $n++; } return $n;
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
foreach (array('-wal', '-shm', '-journal') as $x) if (file_exists($src . $x)) fail("source has a $x file; not quiet or in WAL mode");
if (function_exists('posix_geteuid') && posix_geteuid() !== fileowner($src)) fail('run as the file owner (the web user)');
if (file_exists($flag)) fail('maintenance flag already present (another run?): private/cfg_db_maint');
$d = ro($src); $jm = strtolower((string)$d->query('PRAGMA journal_mode')->fetchColumn()); $d = null;
if ($jm === 'wal') fail('journal_mode is WAL; not supported');
$st = stat($src);
out(sprintf('%s: %s (%d bytes, mode %o, uid %d, journal_mode %s) -> %s', $mode, substr($src, strlen($site) + 1), $st['size'], $st['mode'] & 0777, $st['uid'], $jm, substr($dst, strlen($site) + 1)));
if ($o['coord'] && $mode !== '--check') {
	$hb = @json_decode((string)@file_get_contents("$site/loadbalancer/run/heartbeat.json"), true);
	if (!is_array($hb) || empty($hb['cfg_resolver']) || time() - (int)($hb['time'] ?? 0) > 180) fail('coordinator is not running the db_path.php version (heartbeat has no fresh cfg_resolver); restart it first', 4);
}
if ($mode === '--check') exit(0);

// 2. quiesce
if (!@touch($flag)) fail('cannot create private/cfg_db_maint');
$tmp = dirname($dst) . '/.openevse.db.tmp-' . getmypid();
$abort = function($m, $c = 1) use ($flag, $tmp){ @unlink($tmp); @unlink($flag); fail($m . ' (aborted; nothing moved; maintenance flag removed)', $c); };
$until = microtime(true) + 120; while (writers_running($site)) { if (microtime(true) > $until) $abort('jobs/notifier still running after 120 s', 5); usleep(250000); }
usleep((int)($o['drain'] * 1e6));
// 3. copy
$sum = sha($src);
try { $d = ro($src); $d->exec("VACUUM INTO " . $d->quote($tmp)); $d = null; } catch (Exception $e) { $abort('VACUUM INTO failed: ' . $e->getMessage()); }
if (sha($src) !== $sum) $abort('source changed during the copy (a writer ignored the flag)', 3);
try { $d = ro($tmp); $qc = $d->query('PRAGMA quick_check')->fetchColumn(); $d = null; $same = counts($src) === counts($tmp); } catch (Exception $e) { $qc = $e->getMessage(); $same = false; }
if ($qc !== 'ok' || !$same) $abort('copy check failed: ' . $qc);
$h = fopen($tmp, 'r+'); $synced = $h && fsync($h); if ($h) fclose($h);
if (!$synced) $abort('fsync failed');
chmod($tmp, $st['mode'] & 0777);
// 4. publish (target appears before the source leaves: the path never disappears)
if (!rename($tmp, $dst)) $abort('publish rename failed');
if (!rename($src, $aside)) { rename($dst, $tmp); $abort('could not move the old file aside'); }
// 5. settle, then check nobody wrote to the old file
usleep((int)($o['settle'] * 1e6));
if (sha($aside) !== $sum) {
	$ok = rename($aside, $src) && rename($dst, "$site/data/openevse.db.failed-$ts");
	if (!$ok) fail('late write AND revert failed: maintenance flag left on; fix by hand', 6);
	@unlink($flag); fail('a writer that opened before the flag wrote to the old file; reverted, old file is current again, nothing lost', 3);
}
file_put_contents("$aside.sha256", $sum . "\n");
@unlink($flag);
out('done: ' . substr($dst, strlen($site) + 1) . ' is live; old file kept as data/' . basename($aside) . ' (re-check later with --verify)');
