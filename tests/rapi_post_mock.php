<?php
// Mock WiFi module /r for tests/rapi_post_test.php. MODE env: post (POST only, GET -> 403,
// like newer firmware), get (POST -> 405, like old 2.x), both (5.1.5). Logs each method.
$mode = getenv('MOCK_MODE') ?: 'both'; $m = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/r') { http_response_code(404); exit; }
file_put_contents(getenv('MOCK_LOG'), $m . "\n", FILE_APPEND);
// MOCK_AUTH=1: Basic login u/p required (401 + Basic challenge otherwise), checked before the method
if (getenv('MOCK_AUTH') && (($_SERVER['PHP_AUTH_USER'] ?? '') !== 'u' || ($_SERVER['PHP_AUTH_PW'] ?? '') !== 'p')) { header('WWW-Authenticate: Basic realm="mock"'); http_response_code(401); exit; }
if ($m === 'POST' && $mode === 'get') { http_response_code(405); exit; }
if ($m === 'GET' && $mode === 'post') { http_response_code(403); echo 'use POST'; exit; }
$rapi = $m === 'POST' ? ($_POST['rapi'] ?? '') : ($_GET['rapi'] ?? '');
if (!preg_match('/^\$GC.*? :([0-9A-F]{2})/', $rapi, $mm)) { echo '{"cmd":"","ret":"$NK^21"}'; exit; }
$b = '$OK 6 48 20 48 :' . $mm[1]; $x = 0; foreach (str_split($b) as $c) $x ^= ord($c);
header('Content-Type: application/json'); echo json_encode(array('cmd'=>$rapi, 'ret'=>$b . '^' . sprintf('%02X', $x)));
