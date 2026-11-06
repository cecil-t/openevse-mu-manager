<?php
/******************************************************************************
 * Fake Emoncms input API for the OpenEVSE WiFi 2.x modules.
 * The unit's Emoncms server is set to  <site web address>emoncms  (Settings > Preferences) and it
 * GETs every 30 s:  <site>/emoncms/input/post.json?node=N&json={...}&apikey=K
 * Accepts GET or POST, input/post or input/post.json, json= / fulljson= / data=.
 * Replies "ok" (the only reply the firmware counts as success).
 * Keys: sha256 per node in sessions.db emon_nodes (set by collector/jobs.php, Settings > Units > Emoncms).
 * Processing: ../collector/push_logic.php (web access denied by collector/.htaccess)
 ******************************************************************************/
define('SESS_DIR', dirname(__DIR__) . '/collector');
header('Content-Type: text/plain');
header('Cache-Control: no-store');
$q = array();
parse_str((string)$_SERVER['QUERY_STRING'], $q);
if (!empty($_POST)) $q = array_merge($q, $_POST);
$raw = null;
foreach (array('fulljson', 'json', 'data') as $k) if (isset($q[$k]) && $q[$k] !== '') { $raw = (string)$q[$k]; break; }
$key = isset($q['apikey']) ? (string)$q['apikey'] : (isset($q['devicekey']) ? (string)$q['devicekey'] : '');
if ($key === '' && isset($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/^Bearer\s+(\S+)/', $_SERVER['HTTP_AUTHORIZATION'], $m)) $key = $m[1];
$node = isset($q['node']) ? substr(preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$q['node']), 0, 32) : '';
unset($q['apikey'], $q['devicekey']);
// Maintenance freeze: private/sess_writers_pause (cutover). Do not open sessions.db.
// 503 + non-"ok" body: units that treat only "ok" as success should retry on their next
// 30 s timer (not a durable offline queue). Stage-tested in fixtures; not enabled in prod.
$site = dirname(SESS_DIR);
if (is_file($site . '/private/sess_writers_pause')) {
	@file_put_contents(SESS_DIR . '/push.log', date('Y-m-d H:i:s') . " INFO writers paused; 503\n", FILE_APPEND | LOCK_EX);
	http_response_code(503);
	header('Retry-After: 60');
	$res = 'maintenance';
	header('Content-Length: ' . strlen($res));
	header('Connection: close');
	echo $res;
	exit;
}
try {
	require SESS_DIR . '/push_logic.php';
	$res = push_receive($node, $key, $raw, $_SERVER['REMOTE_ADDR'], time());
} catch (Throwable $e) {
	@file_put_contents(SESS_DIR . '/push.log', date('Y-m-d H:i:s') . ' ERROR receiver: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n", FILE_APPEND | LOCK_EX);
	$res = 'ok';   // never make the unit retry/back off because of our bug
}
unset($key);
// Content-Length so the web server doesn't send it chunked: v5's HTTP client doesn't
// de-chunk, so "ok" never matched and every post counted as a failure
if (empty($GLOBALS['PUSH_ALREADY_SENT'])) { header('Content-Length: ' . strlen($res)); header('Connection: close'); echo $res; }
