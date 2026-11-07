<?php
/******************************************************************************
 * private/cfg_db_move.php - move the config DB (openevse.db) into data/, or back.
 * MAINTENANCE ONLY: run as the web user (the file's owner), never from the web:
 *   sudo -n -u http php private/cfg_db_move.php --check | --move | --rollback
 *
 * --move: openevse.db -> data/openevse.db while holding SQLite's write lock
 *   (BEGIN IMMEDIATE: readers keep working, writers wait), copy + quick_check,
 *   atomic rename into data/, hard-link the old file to data/openevse.db.premove-<ts> (deny-all folder),
 *   then atomically replace openevse.db with a relative symlink to
 *   data/openevse.db so anything still on the old path (a coordinator started
 *   before this code) reads the new file. Writers waiting on the lock are
 *   checked afterwards: if the old file changed, it says so (exit 3).
 * --rollback: data/openevse.db (current data, not a stale snapshot) -> openevse.db
 *   the same way; data/openevse.db is renamed to data/openevse.db.rolledback-<ts>.
 * Rollback-journal mode only (no -wal): refuses WAL or a hot -journal.
 ******************************************************************************/
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$site = isset($argv[2]) ? rtrim($argv[2], '/') : dirname(__DIR__);   // 2nd arg: test fixtures only
$mode = $argv[1] ?? '--check';
$root = "$site/openevse.db"; $data = "$site/data/openevse.db"; $ts = date('Ymd-His');
function out($m){ echo $m, "\n"; }
function fail($m, $c = 1){ fwrite(STDERR, "cfg_db_move: $m\n"); exit($c); }
function fp($f){ return is_file($f) ? hash_file('sha256', $f) : null; }

$src = $mode === '--rollback' ? $data : $root; $dst = $mode === '--rollback' ? $root : $data;
if (!in_array($mode, array('--check', '--move', '--rollback'), true)) fail('use --check, --move or --rollback');
if (!is_dir("$site/data")) fail('data/ folder missing');
if (!is_file($src) || ($mode !== '--rollback' && is_link($src))) fail('source is not a regular file: ' . basename($src));
if ($mode === '--move' && file_exists($data)) fail('data/openevse.db already exists');
if ($mode === '--rollback' && file_exists($root) && !is_link($root)) fail('openevse.db is a real file, not the compat link');
foreach (array('-wal', '-journal') as $x) if (file_exists($src . $x) && filesize($src . $x) > 0) fail("source has a non-empty $x sidecar");
if (function_exists('posix_geteuid') && posix_geteuid() !== fileowner($src)) fail('run as the file owner (the web user)');
$st = stat($src);
out(sprintf('%s: %s (%d bytes, mode %o, uid %d gid %d) -> %s', $mode, basename(dirname($src)) . '/' . basename($src), $st['size'], $st['mode'] & 0777, $st['uid'], $st['gid'], substr($dst, strlen($site) + 1)));
if ($mode === '--check') exit(0);

$db = new PDO('sqlite:' . $src, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15));
$jm = strtolower((string)$db->query('PRAGMA journal_mode')->fetchColumn());
if ($jm === 'wal') fail('database is in WAL mode; not supported by this script');
$db->exec('BEGIN IMMEDIATE');   // no other writer from here; readers continue
$tmp = dirname($dst) . '/.openevse.db.tmp-' . getmypid();
if (!copy($src, $tmp)) { $db->exec('ROLLBACK'); fail('copy failed'); }
chmod($tmp, $st['mode'] & 0777);
$chk = new PDO('sqlite:' . $tmp, null, null, array(PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY));
$ok = $chk->query('PRAGMA quick_check')->fetchColumn(); $chk = null;
$sum = fp($src);
if ($ok !== 'ok' || fp($tmp) !== $sum) { @unlink($tmp); $db->exec('ROLLBACK'); fail('copy check failed'); }
if ($mode === '--move') {
	rename($tmp, $data);                               // new home appears atomically; resolver prefers it
	link($root, "$site/data/openevse.db.premove-$ts");      // keep the old file (rollback / forensics)
	@unlink("$site/.openevse.db.lnk"); symlink('data/openevse.db', "$site/.openevse.db.lnk");
	rename("$site/.openevse.db.lnk", $root);           // old path -> compat link, atomically
	$old = "$site/data/openevse.db.premove-$ts";
} else {
	rename($tmp, $root);                               // replaces the compat link atomically
	rename($data, "$site/data/openevse.db.rolledback-$ts"); // resolver falls back to openevse.db
	$old = "$site/data/openevse.db.rolledback-$ts";
}
$db->exec('ROLLBACK'); $db = null;                     // nothing was written; releases the lock
usleep(1500000);
if (fp($old) !== $sum) { out('WARNING: a writer changed the old file after the copy: ' . basename($old)); exit(3); }
out('done; old file kept as ' . basename($old) . '; sha256 ' . substr($sum, 0, 16));
