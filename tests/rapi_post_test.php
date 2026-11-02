<?php
// php tests/rapi_post_test.php - /r pass-through sends POST (json=1&rapi=...), falls back to GET
// once when a unit rejects POST. Runs a mock module on 127.0.0.1 with PHP's built-in server.
$dir = sys_get_temp_dir() . '/oev_post_' . getmypid(); @mkdir($dir); putenv("TMPDIR=$dir");
require_once __DIR__ . '/../include.php';
require_once __DIR__ . '/../include_v5.php';
require_once __DIR__ . '/../collector/session_logic.php';
$f = 0; function ck($n, $ok){ global $f; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . "\n"; }
$log = "$dir/log"; $port = 18000 + getmypid() % 1000;
function mock($mode, $auth = false){ global $port, $log, $proc; if ($proc) proc_terminate($proc);
	@unlink($log); touch($log); $port++;
	$proc = proc_open(array(PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/rapi_post_mock.php'), array(array('pipe','r'), array('file','/dev/null','w'), array('file','/dev/null','w')), $p, null, array('MOCK_MODE'=>$mode, 'MOCK_LOG'=>$log, 'MOCK_AUTH'=>$auth ? '1' : ''));
	for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(50000);
	unset($GLOBALS['__evse_rapi_get_only'], $GLOBALS['__evse_rapi_memo'], $GLOBALS['__evse_rapi_budget']);
	unset($GLOBALS['__evse_auth_healed']);
	return $auth ? array('evseurl'=>"http://127.0.0.1:$port/", 'username'=>'u', 'password'=>'p') : array('evseurl'=>"http://127.0.0.1:$port/", 'username'=>'', 'password'=>'');
}
function methods(){ global $log; return trim(str_replace("\n", ' ', file_get_contents($log))); }
ck('post body format', evse_rapi_post_body('$GC :0A^3B') === 'json=1&rapi=%24GC%20%3A0A%5E3B');
foreach (array('post'=>'POST', 'both'=>'POST', 'get'=>'POST GET') as $mode => $want) {
	$u = mock($mode); $r = rapi($u, '$GC');
	ck("rapi() mode=$mode -> $want", $r === '6 48 20 48' && methods() === $want);
	$u2 = $u; $r = evse_par_run(array(1=>$u2), array(1=>evse_par_rapi('$GC')))[1] ?? null;
	ck("parallel mode=$mode", $r === '6 48 20 48' && methods() === ($mode === 'get' ? 'POST GET GET' : 'POST POST'));
}
foreach (array('post'=>'POST', 'get'=>'POST GET') as $mode => $want) { $u = mock($mode); ck("collector sess_rapi mode=$mode", sess_rapi($u, '$GC') === '6 48 20 48' && methods() === $want); }
$u = mock('get'); rapi($u, '$GC'); rapi($u, '$GC'); unset($GLOBALS['__evse_rapi_memo']); rapi($u, '$GC');
ck('GET-only remembered (one POST, then GETs)', methods() === 'POST GET GET');
$GLOBALS['__evse_rapi_get_only'][$u['evseurl']] = time() - EVSE_RAPI_GET_ONLY_S - 1; unset($GLOBALS['__evse_rapi_memo']); rapi($u, '$GC');
ck('POST tried again after an hour', methods() === 'POST GET GET POST GET');
ck('500 is not a POST rejection (no resend of a write)', !evse_rapi_post_rejected(500) && !evse_rapi_post_rejected(0) && !evse_rapi_post_rejected(401));
// 401 self-heal on the POST path and on the GET fallback: cached Digest, unit wants Basic
foreach (array('post'=>'POST POST', 'get'=>'POST POST GET') as $mode => $want) {
	$u = mock($mode, true); evse_auth_set($u['evseurl'], 'digest'); $r = rapi($u, '$GC');
	ck("401 self-heal rapi() mode=$mode -> $want", $r === '6 48 20 48' && methods() === $want && evse_auth_mode($u['evseurl']) === 'basic' && !is_file(evse_auth_file($u['evseurl'])));
	$u = mock($mode, true); evse_auth_set($u['evseurl'], 'digest');
	$r = evse_par_run(array(1=>$u), array(1=>evse_par_rapi('$GC')))[1] ?? null;
	ck("401 self-heal parallel mode=$mode", $r === '6 48 20 48' && evse_auth_mode($u['evseurl']) === 'basic');
	evse_auth_forget($u['evseurl']);
}
proc_terminate($proc); array_map('unlink', glob("$dir/*")); @rmdir($dir);
echo $f ? "$f failed\n" : "ok\n"; exit($f ? 1 : 0);
