<?php
// php tests/fresh_setup_test.php — run the real collector/setup_auth.php on an empty copy of the app (twice,
// from another working directory): both SQLite DBs must be made only in data/. A legacy copy (DBs in the site
// folder) must keep using them and get no new DBs. Synthetic: throwaway key, no real data.
$f = 0; function ck($n, $ok){ global $f; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . "\n"; }
$php = PHP_BINARY; $app = dirname(__DIR__);
$base = sys_get_temp_dir() . '/oev_fresh_' . getmypid();
$copy = function($dst) use ($app){
	foreach (explode("\0", trim(shell_exec('git -C ' . escapeshellarg($app) . ' ls-files -z'), "\0")) as $rel) {
		if ($rel === '' || preg_match('~(^|/)(tests|docs)/|\.db$~', $rel)) continue;
		@mkdir(dirname("$dst/$rel"), 0755, true); copy("$app/$rel", "$dst/$rel");
	}
	@mkdir("$dst/private/key", 0700, true); file_put_contents("$dst/private/key/secret.key", bin2hex(random_bytes(32)) . "\n");
};
$dbs = function($dir){ $o = array(); $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $p) if (preg_match('/\.(db|db-wal|db-shm|db-journal|sqlite3?)$/', $p->getFilename()) || is_link($p->getPathname()) && preg_match('/\.db/', $p->getFilename())) $o[] = substr($p->getPathname(), strlen($dir) + 1);
	sort($o); return $o; };
$setup = function($site) use ($php){ exec('cd / && printf "fixture-pass-123\n" | OEV_ADMIN_EMAIL=admin@example.invalid ' . escapeshellarg($php) . ' ' . escapeshellarg("$site/collector/setup_auth.php") . ' 2>&1', $o, $rc); return array($rc, implode(' | ', $o)); };
$tables = function($p){ $d = new PDO('sqlite:' . $p, null, null, array(PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY)); $t = $d->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN); $ic = $d->query('PRAGMA integrity_check')->fetchColumn(); return array($t, $ic); };

// fresh install (with and without an existing data/ folder)
foreach (array('no data/ folder' => false, 'empty data/ folder' => true) as $label => $mkdata) {
	$s = "$base/fresh" . (int)$mkdata; $copy($s); if ($mkdata) @mkdir("$s/data", 0750); else exec('rm -rf ' . escapeshellarg("$s/data"));
	list($rc, $o) = $setup($s);
	ck("fresh ($label): setup_auth exit 0", $rc === 0); if ($rc) echo "   output: $o\n";
	$found = $dbs($s); $outside = array_values(array_filter($found, function($x){ return strpos($x, 'data/') !== 0; }));
	ck("fresh ($label): no DB or sidecar outside data/", $outside === array());
	ck("fresh ($label): data/openevse.db and data/sessions.db made", is_file("$s/data/openevse.db") && is_file("$s/data/sessions.db"));
	list($t, $ic) = $tables("$s/data/openevse.db"); ck("fresh ($label): openevse.db schema + integrity", !array_diff(array('evses', 'load_groups', 'settings', 'users', 'auth_tokens', 'jobs'), $t) && $ic === 'ok');
	list($t, $ic) = $tables("$s/data/sessions.db"); ck("fresh ($label): sessions.db schema + integrity", in_array('meta', $t) && count($t) > 3 && $ic === 'ok');
	list($rc) = $setup($s); $outside = array_values(array_filter($dbs($s), function($x){ return strpos($x, 'data/') !== 0; }));
	ck("fresh ($label): second run ok, still nothing outside data/", $rc === 0 && $outside === array());
	if (!$mkdata) ck('fresh: data/ made 0750', (fileperms("$s/data") & 0777) === 0750);
}
// legacy install: DBs in the site folder stay in use; nothing new in data/
$s = "$base/legacy"; $copy($s); @mkdir("$s/data", 0750);
list($rc) = $setup($s); rename("$s/data/openevse.db", "$s/openevse.db"); rename("$s/data/sessions.db", "$s/sessions.db");
foreach (glob("$s/data/sessions.db-*") ?: array() as $x) rename($x, "$s/" . basename($x));
list($rc, $o) = $setup($s);
ck('legacy: setup_auth exit 0', $rc === 0);
ck('legacy: no DB created in data/ next to the real ones', !glob("$s/data/*.db*"));
ck('legacy: root DBs still used', is_file("$s/openevse.db") && is_file("$s/sessions.db"));
exec('rm -rf ' . escapeshellarg($base));
echo $f ? "$f FAILED\n" : "ALL PASS\n"; exit($f ? 1 : 0);
