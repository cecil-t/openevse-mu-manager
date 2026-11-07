<?php
// php tests/cfg_db_path_test.php — config DB resolver, maintenance contract and private/cfg_db_move.php,
// on synthetic fixtures with real concurrent processes (no live data).
$f = 0; function ck($n, $ok){ global $f; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . "\n"; }
$php = PHP_BINARY; $app = dirname(__DIR__); $mover = "$app/private/cfg_db_move.php";
require_once $app . '/db_path.php';
$base = sys_get_temp_dir() . '/oev_cfgdb_' . getmypid(); @mkdir($base);
$n = 0;
$site = function() use ($base, &$n){ $s = "$base/s" . (++$n); mkdir("$s/data", 0750, true); mkdir("$s/private"); return $s; };
$mk = function($p, $v = 'orig', $padMB = 0){ $d = new PDO('sqlite:' . $p); $d->exec('CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT)'); $d->prepare('REPLACE INTO settings VALUES (?,?)')->execute(array('x', $v));
	if ($padMB) { $d->exec('CREATE TABLE pad (b BLOB)'); $d->beginTransaction(); for ($i = 0; $i < $padMB; $i++) $d->exec('INSERT INTO pad VALUES (randomblob(1048576))'); $d->commit(); } };
$val = function($p){ $d = new PDO('sqlite:' . $p, null, null, array(PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY)); return $d->query("SELECT v FROM settings WHERE k='x'")->fetchColumn(); };
$run = function($s, $args) use ($php, $mover){ exec(escapeshellarg($php) . ' ' . escapeshellarg($mover) . " $args --site=" . escapeshellarg($s) . ' 2>&1', $o, $rc); return array($rc, implode(' | ', $o)); };
// background "old handle" writer: opens the given path NOW (raw PDO, like a request that started before the flag),
// waits for a trigger file, then writes with a busy timeout. Prints its result.
$bg = function($path, $trigger, $v, $out) use ($php){
	$code = '[$_,$p,$t,$v]=$argv; $d=new PDO("sqlite:".$p,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>30]); $d->query("SELECT 1")->fetchAll();
		$e=microtime(true)+60; while(!glob($t) && microtime(true)<$e) usleep(5000); try { $d->exec("REPLACE INTO settings VALUES(\'x\',\'$v\')"); echo "wrote"; } catch (Exception $x) { echo "error: ".$x->getMessage(); }';
	exec(escapeshellarg($php) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg($path) . ' ' . escapeshellarg($trigger) . ' ' . escapeshellarg($v) . ' > ' . escapeshellarg($out) . ' 2>&1 &');
	usleep(300000);
};

echo "-- resolver / no-create\n";
$s = $site();
ck('nothing yet -> data/ (new install target)', oev_cfg_db_path($s) === "$s/data/openevse.db");
$t = false; try { oev_cfg_db_open(array(), false, $s); } catch (RuntimeException $e) { $t = true; }
ck('reader refuses missing DB and creates nothing', $t && !glob("$s/data/*") && !file_exists("$s/openevse.db"));
$t = false; try { new PDO('sqlite:' . "$s/x.db", null, null, array(PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READWRITE)); } catch (PDOException $e) { $t = true; }
ck('READWRITE without CREATE cannot make a file (race-proof guard)', $t && !file_exists("$s/x.db"));
$mk("$s/openevse.db", 'root');
ck('legacy root used when only root exists', oev_cfg_db_path($s) === "$s/openevse.db");
file_put_contents("$s/private/cfg_db_path", "alt/x.db\n"); ck('relative override', oev_cfg_db_path($s) === "$s/alt/x.db"); unlink("$s/private/cfg_db_path");
$d = oev_cfg_db_open(array(), false, $s); $d->exec("REPLACE INTO settings VALUES('x','rw')"); $d = null;
ck('normal open can write', $val("$s/openevse.db") === 'rw');
touch("$s/private/cfg_db_maint");
$d = oev_cfg_db_open(array(), false, $s); $t = false; try { $d->exec("REPLACE INTO settings VALUES('x','blocked')"); } catch (PDOException $e) { $t = true; } $d = null;
ck('maintenance: new connection is read-only, write FAILS loudly', $t && $val("$s/openevse.db") === 'rw');
$t = false; try { oev_cfg_db_open(array(), true, $s); } catch (RuntimeException $e) { $t = true; }
ck('maintenance: setup create refused', $t);
unlink("$s/private/cfg_db_maint");

echo "-- preflight refusals\n";
$s = $site(); $mk("$s/openevse.db");
list($rc, $o) = $run($s, '--move'); ck('no coordinator heartbeat -> refused (exit 4)', $rc === 4 && !file_exists("$s/data/openevse.db"));
mkdir("$s/loadbalancer/run", 0755, true); file_put_contents("$s/loadbalancer/run/heartbeat.json", json_encode(array('time' => time() - 600, 'cfg_resolver' => 1)));
list($rc) = $run($s, '--move'); ck('stale heartbeat -> refused', $rc === 4);
file_put_contents("$s/loadbalancer/run/heartbeat.json", json_encode(array('time' => time())));
list($rc) = $run($s, '--move'); ck('old coordinator (no cfg_resolver) -> refused', $rc === 4);
touch("$s/openevse.db-journal"); list($rc, $o) = $run($s, '--move --no-coordinator-check'); ck('-journal present -> refused', $rc === 1 && strpos($o, '-journal') !== false); unlink("$s/openevse.db-journal");
touch("$s/private/cfg_db_maint"); list($rc) = $run($s, '--move --no-coordinator-check'); ck('flag already present -> refused', $rc === 1); unlink("$s/private/cfg_db_maint");
$w = new PDO('sqlite:' . "$s/openevse.db"); $w->exec('PRAGMA journal_mode=WAL'); $w->exec("REPLACE INTO settings VALUES('x','w')"); $w = null;   // WAL with no sidecars left behind
ck('WAL fixture has no -wal/-shm at rest', !file_exists("$s/openevse.db-wal") && !file_exists("$s/openevse.db-shm"));
list($rc, $o) = $run($s, '--move --no-coordinator-check'); ck('WAL detected from SQLite (not from sidecars) -> refused', $rc === 1 && strpos($o, 'WAL') !== false && !file_exists("$s/data/openevse.db"));

echo "-- happy path\n";
$s = $site(); $mk("$s/openevse.db", 'orig'); chmod("$s/openevse.db", 0644);
mkdir("$s/loadbalancer/run", 0755, true); file_put_contents("$s/loadbalancer/run/heartbeat.json", json_encode(array('time' => time(), 'cfg_resolver' => 1)));
list($rc, $o) = $run($s, '--move --drain=0.2 --settle=0.5');
ck('--move ok with fresh coordinator heartbeat', $rc === 0);
ck('root openevse.db gone, no symlink', !file_exists("$s/openevse.db") && !is_link("$s/openevse.db"));
ck('data/openevse.db has the data, mode kept', $val("$s/data/openevse.db") === 'orig' && (fileperms("$s/data/openevse.db") & 0777) === 0644);
ck('old file + sha256 kept in data/', count(glob("$s/data/openevse.db.premove-*.sha256")) === 1 && count(glob("$s/data/openevse.db.premove-*")) === 2);
ck('flag removed, no temp files', !file_exists("$s/private/cfg_db_maint") && !glob("$s/data/.openevse*"));
ck('resolver -> data/; fresh open writes there', oev_cfg_db_path($s) === "$s/data/openevse.db" && (oev_cfg_db_open(array(), false, $s)->exec("REPLACE INTO settings VALUES('x','after')") !== false) && $val("$s/data/openevse.db") === 'after');
list($rc) = $run($s, '--verify'); ck('--verify: old file unchanged', $rc === 0);
list($rc, $o) = $run($s, '--rollback --drain=0.2 --settle=0.5');
ck('--rollback keeps the write made after the move', $rc === 0 && $val("$s/openevse.db") === 'after' && !file_exists("$s/data/openevse.db") && oev_cfg_db_path($s) === "$s/openevse.db");

echo "-- concurrency (separate processes)\n";
// A: old handle opened before the flag, writes during the drain -> lands before the copy -> kept
$s = $site(); $mk("$s/openevse.db", 'orig');
$bg("$s/openevse.db", "$s/private/cfg_db_maint", 'A_drain', "$s/wA");
list($rc, $o) = $run($s, '--move --no-coordinator-check --drain=1.5 --settle=0.5'); usleep(200000);
ck('A: write during drain is in the moved DB', $rc === 0 && trim(file_get_contents("$s/wA")) === 'wrote' && $val("$s/data/openevse.db") === 'A_drain');
// B: long-delayed old handle writes AFTER publish -> SQLite itself refuses (SQLITE_READONLY_DBMOVED: the file
//    it opened was renamed), so the writer gets an error instead of a lost write; the move stands.
$s = $site(); $mk("$s/openevse.db", 'orig');
$bg("$s/openevse.db", "$s/data/openevse.db.premove-*", 'B_late', "$s/wB");
list($rc, $o) = $run($s, '--move --no-coordinator-check --drain=0.2 --settle=1.5'); usleep(200000);
$wb = trim(file_get_contents("$s/wB"));
ck('B: late old-handle write fails loudly (readonly / moved), not silently', strpos($wb, 'readonly') !== false);
ck('B: move stands, moved DB unchanged, old file unchanged (--verify)', $rc === 0 && $val("$s/data/openevse.db") === 'orig' && $run($s, '--verify')[0] === 0);
// B2: settle check (belt and braces): the old file is changed after publish by something that
//     reopened its new name (simulates any write path SQLite would not catch) -> revert, exit 3, nothing lost
$s = $site(); $mk("$s/openevse.db", 'orig');
$code = '$s=$argv[1]; $e=microtime(true)+20; while(!($g=glob("$s/data/openevse.db.premove-2*")) && microtime(true)<$e) usleep(2000); $d=new PDO("sqlite:".$g[0]); $d->exec("REPLACE INTO settings VALUES(\'x\',\'B2_late\')"); echo "wrote";';
exec(escapeshellarg($php) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg($s) . " > $s/wB2 2>&1 &"); usleep(300000);
list($rc, $o) = $run($s, '--move --no-coordinator-check --drain=0.2 --settle=1.5'); usleep(200000);
ck('B2: change to old file during settle -> exit 3', $rc === 3 && trim(file_get_contents("$s/wB2")) === 'wrote');
ck('B2: reverted - root is current and holds that write', is_file("$s/openevse.db") && $val("$s/openevse.db") === 'B2_late' && oev_cfg_db_path($s) === "$s/openevse.db");
ck('B2: copy moved aside, flag removed', !file_exists("$s/data/openevse.db") && count(glob("$s/data/openevse.db.failed-*")) === 1 && !file_exists("$s/private/cfg_db_maint"));
// C: old handle writes while VACUUM INTO is copying (big DB) -> waits on SQLite lock, then commits -> abort
$s = $site(); $mk("$s/openevse.db", 'orig', 300);
$bg("$s/openevse.db", "$s/data/.openevse.db.tmp-*", 'C_copy', "$s/wC");
list($rc, $o) = $run($s, '--move --no-coordinator-check --drain=0.2 --settle=0.5'); sleep(1);
ck('C: write during copy -> aborted (exit 3), nothing published', $rc === 3 && !file_exists("$s/data/openevse.db") && !glob("$s/data/.openevse*") && !file_exists("$s/private/cfg_db_maint"));
ck('C: the write is in the (still live) root DB', trim(file_get_contents("$s/wC")) === 'wrote' && $val("$s/openevse.db") === 'C_copy');
// D: a request that starts during the window (resolver + oev_cfg_db_open) cannot write
$s = $site(); $mk("$s/openevse.db", 'orig');
$code = 'require $argv[1]; $e=microtime(true)+20; while(!is_file($argv[2]."/private/cfg_db_maint") && microtime(true)<$e) usleep(5000); try { oev_cfg_db_open([], false, $argv[2])->exec("REPLACE INTO settings VALUES(\'x\',\'D_new\')"); echo "wrote"; } catch (Exception $x) { echo "refused"; }';
exec(escapeshellarg($php) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg("$app/db_path.php") . ' ' . escapeshellarg($s) . " > $s/wD 2>&1 &"); usleep(300000);
list($rc) = $run($s, '--move --no-coordinator-check --drain=1 --settle=0.5'); usleep(200000);
ck('D: new connection during window refused, move ok, value unchanged', $rc === 0 && trim(file_get_contents("$s/wD")) === 'refused' && $val("$s/data/openevse.db") === 'orig');
// E: the path never disappears while the move publishes (stat loop in another process)
$s = $site(); $mk("$s/openevse.db", 'orig', 50);
$code = '$s=$argv[1]; $m=0; $k=0; $e=microtime(true)+8; require $argv[2]; while(microtime(true)<$e){ clearstatcache(); if(!is_file(oev_cfg_db_path($s))) $m++; $k++; } echo "$m/$k";';
exec(escapeshellarg($php) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg($s) . ' ' . escapeshellarg("$app/db_path.php") . " > $s/wE 2>&1 &");
list($rc) = $run($s, '--move --no-coordinator-check --drain=0.5 --settle=0.5'); sleep(7);
list($miss, $tot) = explode('/', trim(file_get_contents("$s/wE")) . '/0');
ck("E: resolved path existed on every check ($miss missing of $tot)", $rc === 0 && $miss === '0' && (int)$tot > 1000);
// F: coordinator-style reload (re-resolve each time) follows the move
ck('F: re-resolving reader opens data/ after the move', oev_cfg_db_path($s) === "$s/data/openevse.db");

echo "-- fault injection\n";
$s = $site(); $mk("$s/openevse.db", 'orig');
chmod("$s/data", 0550); list($rc, $o) = $run($s, '--move --no-coordinator-check --drain=0 --settle=0'); chmod("$s/data", 0750);
ck('data/ not writable -> aborted, root intact, flag removed', $rc !== 0 && $val("$s/openevse.db") === 'orig' && !file_exists("$s/data/openevse.db") && !file_exists("$s/private/cfg_db_maint"));
list($rc) = $run($s, '--move --no-coordinator-check --drain=0 --settle=0'); $old = glob("$s/data/openevse.db.premove-2*[0-9]");
$w = new PDO('sqlite:' . $old[0]); $w->exec("REPLACE INTO settings VALUES('x','very_late')"); $w = null;
list($rc2, $o) = $run($s, '--verify'); ck('--verify flags a write to the old file after the window (exit 3)', $rc === 0 && $rc2 === 3 && strpos($o, 'CHANGED') !== false);

exec('chmod -R u+w ' . escapeshellarg($base) . '; rm -rf ' . escapeshellarg($base));
echo $f ? "$f FAILED\n" : "ALL PASS\n"; exit($f ? 1 : 0);
