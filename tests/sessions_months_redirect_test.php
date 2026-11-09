<?php
// php tests/sessions_months_redirect_test.php
// sessions.php?view=months must 302 to diagnostics before any HTML (even with tiny output_buffering).
$f = 0; $n = 0;
function ck($m, $ok){ global $f, $n; $n++; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $m . "\n"; }

$php = PHP_BINARY;
$app = dirname(__DIR__);
$s = sys_get_temp_dir() . '/oev_sredir_' . getmypid();
$files = array_merge(
	explode("\0", trim((string)shell_exec('git -C ' . escapeshellarg($app) . ' ls-files -z'), "\0")),
	explode("\0", trim((string)shell_exec('git -C ' . escapeshellarg($app) . ' ls-files -z --others --exclude-standard'), "\0"))
);
foreach ($files as $rel) {
	if ($rel === '' || preg_match('~(^|/)(tests|docs)/|\.db$~', $rel) || !is_file("$app/$rel")) continue;
	@mkdir(dirname("$s/$rel"), 0755, true);
	copy("$app/$rel", "$s/$rel");
}
@mkdir("$s/private/key", 0700, true);
file_put_contents("$s/private/key/secret.key", bin2hex(random_bytes(32)) . "\n");
$o = array();
exec('cd / && printf "fixture-pass-123\n" | OEV_ADMIN_EMAIL=admin@example.invalid ' . escapeshellarg($php) . ' ' . escapeshellarg("$s/collector/setup_auth.php") . ' 2>&1', $o, $rc);
ck('setup_auth ok', $rc === 0);

$c = new PDO("sqlite:$s/data/openevse.db");
$c->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$c->exec("INSERT INTO load_groups (id, name) VALUES (1, 'G1')");
$c->exec("INSERT INTO evses (id, name, evseurl, loadgroupid, enabled) VALUES (4, 'U4', 'http://192.0.2.4/', 1, 1)");
$sel = bin2hex(random_bytes(8)); $sec = bin2hex(random_bytes(24));
$c->prepare('INSERT INTO auth_tokens (selector, token_hash, user_id, created_at, last_used_at) VALUES (?,?,1,?,?)')
	->execute(array($sel, hash('sha256', $sec), time(), time()));
$tok = "$sel.$sec";

$port = 21000 + (getmypid() % 20000);
// Tiny buffer reproduces the reviewer failure mode if redirect runs after _head output.
$cmd = array($php, '-d', 'output_buffering=64', '-d', 'display_errors=0', '-S', "127.0.0.1:$port", '-t', $s);
$srv = proc_open($cmd, array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pp);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
ck('php -S up with output_buffering=64', @fsockopen('127.0.0.1', $port) !== false);

function hit($path, $tok){
	global $port;
	$ctx = stream_context_create(array('http' => array(
		'method' => 'GET',
		'header' => "Cookie: oev_auth=$tok\r\n",
		'ignore_errors' => true,
		'follow_location' => 0,
	)));
	$b = @file_get_contents("http://127.0.0.1:$port/$path", false, $ctx);
	$code = 0; $loc = '';
	preg_match('~ (\d{3})~', $http_response_header[0] ?? '', $m);
	$code = (int)($m[1] ?? 0);
	foreach ($http_response_header ?? array() as $x) {
		if (stripos($x, 'Location:') === 0) $loc = trim(substr($x, 9));
	}
	return array($code, $loc, (string)$b, $http_response_header ?? array());
}

list($code, $loc, $body) = hit('sessions.php?view=months', $tok);
ck('view=months → 302', $code === 302);
ck('Location diagnostics.php#monthly (no unit)', $loc === 'diagnostics.php#monthly');
ck('no HTML body leaked before redirect', $body === '' || (strpos($body, '<html') === false && strpos($body, '<style') === false));

list($code2, $loc2, $body2) = hit('sessions.php?view=months&unit=4', $tok);
ck('view=months&unit=4 → 302', $code2 === 302);
ck('Location preserves unit filter', $loc2 === 'diagnostics.php?unit=4#monthly');

list($code3, $loc3) = hit('sessions.php?view=months&unit=0', $tok);
ck('unit=0 omitted from Location', $code3 === 302 && $loc3 === 'diagnostics.php#monthly');

list($code4, $loc4) = hit('sessions.php?view=months&unit=abc', $tok);
ck('non-digit unit ignored', $code4 === 302 && $loc4 === 'diagnostics.php#monthly');

// Source-order guard (even if HTTP path somehow changes)
$src = file_get_contents("$app/sessions.php");
$iReq = strpos($src, "oev_require('view')");
$iView = strpos($src, "view'] == 'months'");
$iHead = strpos($src, "_head.php");
ck('source: months redirect between auth and _head', $iReq !== false && $iView !== false && $iHead !== false && $iReq < $iView && $iView < $iHead);

proc_terminate($srv); proc_close($srv);
// cleanup copy
$rm = function($d) use (&$rm) {
	if (!is_dir($d)) return;
	foreach (scandir($d) as $x) {
		if ($x === '.' || $x === '..') continue;
		$p = "$d/$x";
		if (is_dir($p)) $rm($p); else @unlink($p);
	}
	@rmdir($d);
};
$rm($s);

echo $f ? "$f of $n FAILED\n" : "all $n passed\n";
exit($f ? 1 : 0);
