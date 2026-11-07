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
$run = function($s, $args) use ($php, $mover){ exec(escapeshellarg($php) . ' ' . escapeshellarg($mover) . " $args --min-code-age=0 --site=" . escapeshellarg($s) . ' 2>&1', $o, $rc); return array($rc, implode(' | ', $o)); };
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
ck('reader refuses missing DB and creates nothing (only the lock file)', $t && array_map('basename', glob("$s/data/*")) === array('cfg_db.lock') && !file_exists("$s/openevse.db"));
$t = false; try { new PDO('sqlite:' . "$s/x.db", null, null, array(PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READWRITE)); } catch (PDOException $e) { $t = true; }
ck('READWRITE without CREATE cannot make a file (race-proof guard)', $t && !file_exists("$s/x.db"));
$mk("$s/openevse.db", 'root');
ck('legacy root used when only root exists', oev_cfg_db_path($s) === "$s/openevse.db");
file_put_contents("$s/private/cfg_db_path", "alt/x.db\n"); ck('relative override', oev_cfg_db_path($s) === "$s/alt/x.db"); unlink("$s/private/cfg_db_path");
$d = oev_cfg_db_open(array(), false, $s); $d->exec("REPLACE INTO settings VALUES('x','rw')"); $d = null;
ck('normal open can write', $val("$s/openevse.db") === 'rw');
touch("$s/data/cfg_db_maint");
$d = oev_cfg_db_open(array(), false, $s); $t = false; try { $d->exec("REPLACE INTO settings VALUES('x','blocked')"); } catch (PDOException $e) { $t = true; } $d = null;
ck('maintenance: new connection is read-only, write FAILS loudly', $t && $val("$s/openevse.db") === 'rw');
$t = false; try { oev_cfg_db_open(array(), true, $s); } catch (RuntimeException $e) { $t = true; }
ck('maintenance: setup create refused', $t);
unlink("$s/data/cfg_db_maint");

echo "-- preflight refusals\n";
$s = $site(); $mk("$s/openevse.db");
list($rc, $o) = $run($s, '--move'); ck('no coordinator heartbeat -> refused (exit 4)', $rc === 4 && !file_exists("$s/data/openevse.db"));
mkdir("$s/loadbalancer/run", 0755, true); file_put_contents("$s/loadbalancer/run/heartbeat.json", json_encode(array('time' => time() - 600, 'cfg_resolver' => 1)));
list($rc) = $run($s, '--move'); ck('stale heartbeat -> refused', $rc === 4);
file_put_contents("$s/loadbalancer/run/heartbeat.json", json_encode(array('time' => time())));
list($rc) = $run($s, '--move'); ck('old coordinator (no cfg_resolver) -> refused', $rc === 4);
touch("$s/openevse.db-journal"); list($rc, $o) = $run($s, '--move --no-coordinator-check'); ck('-journal present -> refused', $rc === 3 && strpos($o, '-journal') !== false); unlink("$s/openevse.db-journal");
touch("$s/data/cfg_db_maint"); list($rc) = $run($s, '--move --no-coordinator-check'); ck('flag already present -> refused', $rc === 1); unlink("$s/data/cfg_db_maint");
$w = new PDO('sqlite:' . "$s/openevse.db"); $w->exec('PRAGMA journal_mode=WAL'); $w->exec("REPLACE INTO settings VALUES('x','w')"); $w = null;   // WAL with no sidecars left behind
ck('WAL fixture has no -wal/-shm at rest', !file_exists("$s/openevse.db-wal") && !file_exists("$s/openevse.db-shm"));
list($rc, $o) = $run($s, '--move --no-coordinator-check'); ck('WAL detected from SQLite (not from sidecars) -> refused', $rc === 1 && strpos($o, 'WAL') !== false && !file_exists("$s/data/openevse.db"));

echo "-- happy path\n";
$s = $site(); $mk("$s/openevse.db", 'orig'); chmod("$s/openevse.db", 0644);
mkdir("$s/loadbalancer/run", 0755, true); file_put_contents("$s/loadbalancer/run/heartbeat.json", json_encode(array('time' => time(), 'cfg_resolver' => 1)));
list($rc, $o) = $run($s, '--move ');
ck('--move ok with fresh coordinator heartbeat', $rc === 0);
ck('root openevse.db gone, no symlink', !file_exists("$s/openevse.db") && !is_link("$s/openevse.db"));
ck('data/openevse.db has the data, mode kept', $val("$s/data/openevse.db") === 'orig' && (fileperms("$s/data/openevse.db") & 0777) === 0644);
ck('old file + sha256 kept in data/', count(glob("$s/data/openevse.db.premove-*.sha256")) === 1 && count(glob("$s/data/openevse.db.premove-*")) === 2);
ck('flag removed, no temp files', !file_exists("$s/data/cfg_db_maint") && !glob("$s/data/.openevse*"));
exec(escapeshellarg($php) . ' -r ' . escapeshellarg('require $argv[1]; oev_cfg_db_open([], false, $argv[2])->exec("REPLACE INTO settings VALUES(\'x\',\'after\')");') . ' ' . escapeshellarg("$app/db_path.php") . ' ' . escapeshellarg($s));   // own process: its lock ends with it
ck('resolver -> data/; fresh open (another process) writes there', oev_cfg_db_path($s) === "$s/data/openevse.db" && $val("$s/data/openevse.db") === 'after');
list($rc) = $run($s, '--verify'); ck('--verify: old file unchanged', $rc === 0);
list($rc, $o) = $run($s, '--rollback ');
ck('--rollback keeps the write made after the move', $rc === 0 && $val("$s/openevse.db") === 'after' && !file_exists("$s/data/openevse.db") && oev_cfg_db_path($s) === "$s/openevse.db");

echo "-- concurrency (separate processes, lock contract)\n";
// writer through the app contract: opens BEFORE the move starts, BEGIN + UPDATE, holds COMMIT for $hold s
$coop = function($site, $v, $hold, $out, $env = '') use ($php, $app){
	$code = 'require $argv[1]; $s=$argv[2]; $d=oev_cfg_db_open([PDO::ATTR_TIMEOUT=>30],false,$s); $d->beginTransaction(); $d->exec("REPLACE INTO settings VALUES(\'x\',\'".$argv[3]."\')");
		usleep((int)($argv[4]*1e6)); try { $d->commit(); printf("committed %.3f", microtime(true)); } catch (Exception $x) { echo "commit error: ".$x->getMessage(); }';
	exec($env . escapeshellarg($php) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg("$app/db_path.php") . ' ' . escapeshellarg($site) . ' ' . escapeshellarg($v) . " $hold > " . escapeshellarg($out) . ' 2>&1 &');
	usleep(400000);   // it is inside its transaction before the migration starts
};
$timed = function($s, $args) use ($run){ $t0 = microtime(true); $r = $run($s, $args); $r[] = microtime(true); return $r; };
// R1 (reviewer's exact race): uncommitted write held across what used to be drain+settle
foreach (array('--move', '--rollback') as $m) {
	$s = $site(); if ($m === '--move') $mk("$s/openevse.db", 'orig'); else { $mk("$s/data/openevse.db", 'orig'); }
	$coop($s, "R1$m", 3, "$s/wR");
	list($rc, $o, $tEnd) = $timed($s, "$m --no-coordinator-check --lock-wait=30");
	usleep(200000); $w = trim(file_get_contents("$s/wR")); $tc = (float)substr($w, 10);
	$live = $m === '--move' ? "$s/data/openevse.db" : "$s/openevse.db";
	ck("R1 $m: migration waited for the open transaction (finished after its COMMIT)", $rc === 0 && strpos($w, 'committed') === 0 && $tEnd > $tc);
	ck("R1 $m: the delayed write is in the live DB", is_file($live) && $val($live) === "R1$m" && oev_cfg_db_path($s) === $live);
	$old = glob("$s/data/openevse.db.*-2*[0-9]");
	ck("R1 $m: archived old file also has it (copied after commit) and --verify ok", $old && $val($old[0]) === "R1$m" && $run($s, '--verify')[0] === 0);
}
// R2: transaction held longer than --lock-wait -> abort, nothing published, write preserved in the original
$s = $site(); $mk("$s/openevse.db", 'orig');
$coop($s, 'R2', 3, "$s/wR2");
list($rc, $o) = $run($s, '--move --no-coordinator-check --lock-wait=1'); sleep(3);
ck('R2: lock wait exceeded -> exit 5, nothing published, flag removed', $rc === 5 && !file_exists("$s/data/openevse.db") && !file_exists("$s/data/cfg_db_maint") && !glob("$s/data/.openevse*"));
ck('R2: the writer committed into the still-live original', strpos(trim(file_get_contents("$s/wR2")), 'committed') === 0 && $val("$s/openevse.db") === 'R2' && oev_cfg_db_path($s) === "$s/openevse.db");
// R3: a writer outside the contract (raw PDO) inside a write transaction -> detected, abort, its write kept
$s = $site(); $mk("$s/openevse.db", 'orig');
$code = '$d=new PDO("sqlite:".$argv[1],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $d->beginTransaction(); $d->exec("REPLACE INTO settings VALUES(\'x\',\'R3\')"); sleep(3); $d->commit(); echo "committed";';
exec(escapeshellarg($php) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg("$s/openevse.db") . " > $s/wR3 2>&1 &"); usleep(400000);
list($rc, $o) = $run($s, '--move --no-coordinator-check --lock-wait=5'); sleep(3);
$live = oev_cfg_db_path($s);
ck('R3: non-contract writer in a transaction: move waited for it to close (or refused) - flag removed', in_array($rc, array(0, 3, 5), true) && !file_exists("$s/data/cfg_db_maint") && ($rc === 0) === ($live === "$s/data/openevse.db"));
ck("R3: its write is in the live DB (rc=$rc)", trim(file_get_contents("$s/wR3")) === 'committed' && $val($live) === 'R3');
// R4: a contract writer that arrives while the migration holds the lock waits, then writes to the NEW file
$s = $site(); $mk("$s/openevse.db", 'orig');
$lk = fopen("$s/data/cfg_db.lock", 'c'); flock($lk, LOCK_EX);   // stand-in for the migration's lock (no flag yet: worst case)
$coop($s, 'R4', 0, "$s/wR4");
$blocked = trim((string)@file_get_contents("$s/wR4")) === '';
rename("$s/openevse.db", "$s/data/openevse.db"); flock($lk, LOCK_UN); fclose($lk); sleep(1);
ck('R4: writer blocked while lock held, then resolved the new path after release', $blocked && strpos(trim(file_get_contents("$s/wR4")), 'committed') === 0 && $val("$s/data/openevse.db") === 'R4');
// R5: bounded wait - CLI gets an exception (web: 503) instead of hanging
$s = $site(); $mk("$s/openevse.db", 'orig');
$lk = fopen("$s/data/cfg_db.lock", 'c'); flock($lk, LOCK_EX);
$coop($s, 'R5', 0, "$s/wR5", 'OEV_CFG_LOCK_WAIT=0.5 '); sleep(1);
flock($lk, LOCK_UN); fclose($lk);
ck('R5: lock wait timeout -> error, no write', strpos(file_get_contents("$s/wR5"), 'maintenance in progress') !== false && $val("$s/openevse.db") === 'orig');
// R6: during maintenance a new connection is read-only and takes no lock (cannot block the migration)
$s = $site(); $mk("$s/openevse.db", 'orig'); touch("$s/data/cfg_db_maint");
$d = oev_cfg_db_open(array(), false, $s); $lk = fopen("$s/data/cfg_db.lock", 'c');
ck('R6: maintenance open takes no lock', flock($lk, LOCK_EX | LOCK_NB)); fclose($lk); $d = null; unlink("$s/data/cfg_db_maint");
// R7: pre-contract code too new -> refused before anything happens
$s = $site(); $mk("$s/openevse.db", 'orig'); touch("$s/db_path.php");
exec(escapeshellarg($php) . ' ' . escapeshellarg($mover) . ' --move --no-coordinator-check --min-code-age=900 --site=' . escapeshellarg($s) . ' 2>&1', $oo, $rc); ck('R7: code younger than --min-code-age -> refused (exit 4)', $rc === 4 && !file_exists("$s/data/cfg_db_maint"));
// E: the path never disappears while the move publishes (stat loop in another process)
$s = $site(); $mk("$s/openevse.db", 'orig', 50);
$code = '$s=$argv[1]; $m=0; $k=0; $e=microtime(true)+8; require $argv[2]; while(microtime(true)<$e){ clearstatcache(); if(!is_file(oev_cfg_db_path($s))) $m++; $k++; } echo "$m/$k";';
exec(escapeshellarg($php) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg($s) . ' ' . escapeshellarg("$app/db_path.php") . " > $s/wE 2>&1 &");
list($rc) = $run($s, '--move --no-coordinator-check'); sleep(9);
list($miss, $tot) = explode('/', trim(file_get_contents("$s/wE")) . '/0');
ck("E: resolved path existed on every check ($miss missing of $tot)", $rc === 0 && $miss === '0' && (int)$tot > 1000);
// F: coordinator-style reload (re-resolve each time) follows the move
ck('F: re-resolving reader opens data/ after the move', oev_cfg_db_path($s) === "$s/data/openevse.db");

// R8: a process outside the contract holding the file open (no transaction) -> move waits for it, aborts if it stays
$s = $site(); $mk("$s/openevse.db", 'orig');
$code = '$h=fopen($argv[1],"r+"); sleep(3); fclose($h); echo "closed";';
exec(escapeshellarg($php) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg("$s/openevse.db") . " > $s/wR8 2>&1 &"); usleep(300000);
list($rc) = $run($s, '--move --no-coordinator-check --lock-wait=1'); sleep(3);
ck('R8: writable file handle elsewhere -> exit 5, nothing published', $rc === 5 && !file_exists("$s/data/openevse.db") && !file_exists("$s/data/cfg_db_maint"));
exec(escapeshellarg($php) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg("$s/openevse.db") . " > $s/wR8 2>&1 &"); usleep(300000);
list($rc) = $run($s, '--move --no-coordinator-check --lock-wait=10');
ck('R8: ... and proceeds once it closed', $rc === 0 && trim(file_get_contents("$s/wR8")) === 'closed' && $val("$s/data/openevse.db") === 'orig');
// R9: a read-only handle elsewhere (like the coordinator's) does not block; it cannot write
$s = $site(); $mk("$s/openevse.db", 'orig');
$code = '$d=new PDO("sqlite:".$argv[1],null,null,[PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]); $d->query("select 1")->fetchAll(); $h=fopen($argv[1],"r"); sleep(3); echo "done";';
exec(escapeshellarg($php) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg("$s/openevse.db") . " > $s/wR9 2>&1 &"); usleep(300000);
list($rc) = $run($s, '--move --no-coordinator-check --lock-wait=1');
ck('R9: read-only holder does not block the move', $rc === 0 && $val("$s/data/openevse.db") === 'orig'); sleep(3);
echo "-- fault injection\n";
$s = $site(); $mk("$s/openevse.db", 'orig'); chmod("$s/private", 0555);   // as on the NAS: private/ not writable by the web user
list($rc, $o) = $run($s, '--move --no-coordinator-check'); chmod("$s/private", 0755);
ck('works with a read-only private/ (flag lives in data/)', $rc === 0 && $val("$s/data/openevse.db") === 'orig' && !file_exists("$s/data/cfg_db_maint"));

$s = $site(); $mk("$s/openevse.db", 'orig');
chmod("$s/data", 0550); list($rc, $o) = $run($s, '--move --no-coordinator-check '); chmod("$s/data", 0750);
ck('data/ not writable -> aborted, root intact, flag removed', $rc !== 0 && $val("$s/openevse.db") === 'orig' && !file_exists("$s/data/openevse.db") && !file_exists("$s/data/cfg_db_maint"));
list($rc) = $run($s, '--move --no-coordinator-check '); $old = glob("$s/data/openevse.db.premove-2*[0-9]");
$w = new PDO('sqlite:' . $old[0]); $w->exec("REPLACE INTO settings VALUES('x','very_late')"); $w = null;
list($rc2, $o) = $run($s, '--verify'); ck('--verify flags a write to the old file after the window (exit 3)', $rc === 0 && $rc2 === 3 && strpos($o, 'CHANGED') !== false);

exec('chmod -R u+w ' . escapeshellarg($base) . '; rm -rf ' . escapeshellarg($base));
echo $f ? "$f FAILED\n" : "ALL PASS\n"; exit($f ? 1 : 0);
