<?php
// php tests/cfg_db_path_test.php — config DB resolver + private/cfg_db_move.php on synthetic fixtures
$f = 0; function ck($n, $ok){ global $f; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . "\n"; }
$php = PHP_BINARY; $app = dirname(__DIR__);
require_once $app . '/db_path.php';
$s = sys_get_temp_dir() . '/oev_cfgdb_' . getmypid(); mkdir("$s/data", 0750, true); mkdir("$s/private");
$mk = function($p, $v){ $d = new PDO('sqlite:' . $p); $d->exec('CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT)'); $d->prepare('REPLACE INTO settings VALUES (?,?)')->execute(array('x', $v)); };
$rd = function($site){ $d = oev_cfg_db_open(array(PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY), false, $site); return $d->query("SELECT v FROM settings WHERE k='x'")->fetchColumn(); };

// resolver
ck('nothing yet -> data/ (new install target)', oev_cfg_db_path($s) === "$s/data/openevse.db");
$thrown = false; try { oev_cfg_db_open(array(), false, $s); } catch (RuntimeException $e) { $thrown = true; }
ck('reader refuses missing DB (no empty file made)', $thrown && !file_exists("$s/data/openevse.db") && !file_exists("$s/openevse.db"));
$mk("$s/openevse.db", 'root');
ck('legacy root used when only root exists', oev_cfg_db_path($s) === "$s/openevse.db" && $rd($s) === 'root');
file_put_contents("$s/private/cfg_db_path", "# comment\n"); ck('comment override ignored', oev_cfg_db_path($s) === "$s/openevse.db");
file_put_contents("$s/private/cfg_db_path", "alt/x.db\n"); ck('relative override', oev_cfg_db_path($s) === "$s/alt/x.db"); unlink("$s/private/cfg_db_path");
ck('cwd does not matter', (chdir('/') || 1) && oev_cfg_db_path($s) === "$s/openevse.db");
$out = shell_exec(escapeshellarg($php) . ' -r ' . escapeshellarg('chdir("/tmp"); require $argv[1]; echo OEV_DB_FILE;') . ' ' . escapeshellarg("$app/db_path.php"));
ck('OEV_DB_FILE is site-relative from another cwd', $out === "$app/" . (is_file("$app/data/openevse.db") ? 'data/' : (is_file("$app/openevse.db") ? '' : 'data/')) . 'openevse.db');

// move script
$run = function($m) use ($php, $app, $s){ exec(escapeshellarg($php) . ' ' . escapeshellarg("$app/private/cfg_db_move.php") . " $m " . escapeshellarg($s) . ' 2>&1', $o, $rc); return array($rc, implode("\n", $o)); };
list($rc) = $run('--check'); ck('--check ok, changes nothing', $rc === 0 && !file_exists("$s/data/openevse.db"));
chmod("$s/openevse.db", 0644); $sum = hash_file('sha256', "$s/openevse.db");
list($rc, $o) = $run('--move');
ck('--move ok', $rc === 0);
ck('data/openevse.db is the same bytes', hash_file('sha256', "$s/data/openevse.db") === $sum);
ck('mode kept', (fileperms("$s/data/openevse.db") & 0777) === 0644);
ck('root is now a relative compat symlink', is_link("$s/openevse.db") && readlink("$s/openevse.db") === 'data/openevse.db');
ck('old file kept inside data/', count(glob("$s/data/openevse.db.premove-*")) === 1 && !glob("$s/openevse.db.*"));
ck('resolver now prefers data/', oev_cfg_db_path($s) === "$s/data/openevse.db" && $rd($s) === 'root');
ck('no temp files left', !glob("$s/data/.openevse*") && !glob("$s/.openevse*"));
$d = new PDO('sqlite:' . "$s/openevse.db"); ck('old path (link) reads the new file', $d->query("SELECT v FROM settings")->fetchColumn() === 'root'); $d = null;
list($rc) = $run('--move'); ck('second --move refused', $rc !== 0);
$mk("$s/data/openevse.db", 'after-move');   // a write after the cutover must survive a rollback
list($rc, $o) = $run('--rollback');
ck('--rollback ok', $rc === 0);
ck('root is a real file again with the newest data', is_file("$s/openevse.db") && !is_link("$s/openevse.db") && $rd($s) === 'after-move');
ck('data/openevse.db moved aside', !file_exists("$s/data/openevse.db") && count(glob("$s/data/openevse.db.rolledback-*")) === 1);
ck('resolver back to root', oev_cfg_db_path($s) === "$s/openevse.db");
$d = new PDO('sqlite:' . "$s/openevse.db"); $d->exec('PRAGMA journal_mode=WAL'); $d->exec("INSERT INTO settings VALUES ('w','1')");
list($rc) = $run('--move'); ck('WAL mode refused', $rc !== 0 && !file_exists("$s/data/openevse.db")); $d = null;
exec('rm -rf ' . escapeshellarg($s));
echo $f ? "$f FAILED\n" : "ALL PASS\n"; exit($f ? 1 : 0);
