<?php
// php tests/auth_heal_test.php - 401 self-heal of the cached login method (include.php)
// Stubs curl-free: drives evse_auth_retry() with a fake WWW-Authenticate header.
$dir = sys_get_temp_dir() . '/oev_heal_' . getmypid(); @mkdir($dir); putenv("TMPDIR=$dir");
require_once __DIR__ . '/../include.php';
$f = 0; function ck($n, $ok){ global $f; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . "\n"; }
$url = 'http://192.0.2.7/'; $ch = curl_init(); $id = spl_object_id($ch);
evse_auth_set($url, 'digest');
ck('cached digest', evse_auth_mode($url) === 'digest' && is_file(evse_auth_file($url)));
$GLOBALS['__evse_wa'][$id] = 'Basic realm="x"';
ck('401 + Basic challenge on cached Digest -> retry', evse_auth_retry($ch, $url, 401, 'u', 'p') === true);
ck('cache file deleted', !is_file(evse_auth_file($url)) && !is_file(evse_digest_file($url)));
ck('mode back to basic', evse_auth_mode($url) === 'basic');
ck('no second retry (no loop)', evse_auth_retry($ch, $url, 401, 'u', 'p') === false);
evse_auth_set($url, 'digest');
ck('heal only once per process', evse_auth_retry($ch, $url, 401, 'u', 'p') === false);
$u2 = 'http://192.0.2.8/';
$GLOBALS['__evse_wa'][$id] = 'Digest realm="openevse", nonce="abc", qop="auth"';
ck('Basic -> Digest challenge still switches', evse_auth_retry($ch, $u2, 401, 'u', 'p') === true && evse_auth_mode($u2) === 'digest');
ck('200 never retries', evse_auth_retry($ch, $u2, 200, 'u', 'p') === false);
ck('no credentials never retries', evse_auth_retry($ch, $u2, 401, '', '') === false);
evse_auth_forget($u2);
ck('forget clears files', !is_file(evse_auth_file($u2)) && !is_file(evse_digest_file($u2)) && evse_auth_mode($u2) === 'basic');
array_map('unlink', glob("$dir/*")); @rmdir($dir);
echo $f ? "$f failed\n" : "ok\n"; exit($f ? 1 : 0);
