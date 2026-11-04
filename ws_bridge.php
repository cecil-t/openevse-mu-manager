<?php
/******************************************************************************
 * ws_bridge.php - TEST: live feed from a unit's own WebSocket (/ws), relayed to
 * the browser as Server-Sent Events. unit.php uses it only with ?ws=1.
 * The NAS opens the WebSocket (with the unit's login, which never leaves the
 * server), passes on each JSON update for up to OEV_WS_SECONDS, then ends; the
 * browser reconnects. Read only: nothing is ever sent to the unit except the
 * WebSocket handshake and replies to its pings.
 * Only display values are passed on (no settings, no network details).
 * Off switch: OEV_WS_ENABLED = false (unit.php then keeps its 5 s poll only).
 ******************************************************************************/
define('OEV_WS_ENABLED', true);
define('OEV_WS_SECONDS', 25);   // one PHP worker per open tab for this long, then a reconnect
define('OEV_WS_SLOTS', 4);      // at most this many feeds at once (the NAS has 20 PHP workers)
require_once __DIR__ . '/auth_lib.php'; oev_require('public', true);   // login gate (auth_lib.php)
include __DIR__ . '/include_v5.php';
header('Cache-Control: no-store');
if (!OEV_WS_ENABLED) { http_response_code(404); exit; }
$id = (int)($_GET['unit'] ?? 0); $units = evse_units();
if (!isset($units[$id])) { http_response_code(404); exit; }
$u = $units[$id];

// one of OEV_WS_SLOTS lock files (app cache folder); none free: 503, the page stays on its 5 s poll
$slot = null;
for ($i = 0; $i < OEV_WS_SLOTS && !$slot; $i++) { $f = fopen(oev_cache_dir() . '/ws_slot_' . $i . '.lock', 'c'); if ($f && flock($f, LOCK_EX | LOCK_NB)) $slot = $f; elseif ($f) fclose($f); }
if (!$slot) { http_response_code(503); exit; }

@set_time_limit(OEV_WS_SECONDS + 10); ignore_user_abort(false);
header('Content-Type: text/event-stream'); header('X-Accel-Buffering: no');
while (ob_get_level()) ob_end_flush();
function wsb_send($ev, $data){ echo "event: $ev\ndata: " . $data . "\n\n"; @flush(); }
function wsb_frame($op, $payload){   // client frames must be masked
	$m = random_bytes(4); $n = strlen($payload);
	$h = chr(0x80 | $op) . ($n < 126 ? chr(0x80 | $n) : chr(0x80 | 126) . pack('n', $n));
	$x = ''; for ($i = 0; $i < $n; $i++) $x .= $payload[$i] ^ $m[$i % 4];
	return $h . $m . $x;
}

$p = parse_url($u['evseurl']); $host = $p['host']; $port = (int)($p['port'] ?? 80);
$s = @stream_socket_client('tcp://' . $host . ':' . $port, $en, $es, 2);
if (!$s) { wsb_send('fail', '"connect"'); exit; }
stream_set_timeout($s, 3);
$hd = "GET /ws HTTP/1.1\r\nHost: $host\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: " . base64_encode(random_bytes(16)) . "\r\nSec-WebSocket-Version: 13\r\n";
if ($u['username'] != '' && $u['password'] != '')   // newer firmware wants the login on /ws too
	$hd .= (evse_auth_mode($u['evseurl']) === 'digest' ? evse_digest_header($u['evseurl'], $u['username'], $u['password'], 'GET', rtrim($u['evseurl'], '/') . '/ws') : 'Authorization: Basic ' . base64_encode($u['username'] . ':' . $u['password'])) . "\r\n";
fwrite($s, $hd . "\r\n");
$st = (string)fgets($s); while (($l = fgets($s)) !== false && $l !== "\r\n");
if (strpos($st, ' 101') === false) { wsb_send('fail', json_encode(trim($st) ?: 'no answer')); fclose($s); exit; }
wsb_send('open', '1');

$buf = ''; $t0 = microtime(true); $beat = $t0;
while (microtime(true) - $t0 < OEV_WS_SECONDS && !connection_aborted()) {
	$r = array($s); $w = $x = null;
	if (!@stream_select($r, $w, $x, 1)) { if (microtime(true) - $beat > 10) { wsb_send('ping', '1'); $beat = microtime(true); } continue; }
	$d = fread($s, 8192); if ($d === '' && feof($s)) break; if ($d === false) break;
	$buf .= $d;
	while (strlen($buf) >= 2) {   // unit -> us frames are not masked
		$b1 = ord($buf[0]); $len = ord($buf[1]) & 127; $o = 2;
		if ($len == 126) { if (strlen($buf) < 4) break; $len = unpack('n', substr($buf, 2, 2))[1]; $o = 4; }
		elseif ($len == 127) { if (strlen($buf) < 10) break; $len = unpack('J', substr($buf, 2, 8))[1]; $o = 10; }
		if ($len > 65536) break 2;
		if (strlen($buf) < $o + $len) break;
		$op = $b1 & 15; $pl = substr($buf, $o, $len); $buf = substr($buf, $o + $len);
		if ($op == 9) { fwrite($s, wsb_frame(10, $pl)); continue; }   // ping -> pong
		if ($op == 8) break 2;
		if ($op == 1) { $j = json_decode($pl, true); if (is_array($j)) { $j = array_intersect_key($j, array_flip(array('amp', 'power', 'voltage', 'pilot', 'state', 'vehicle', 'temp', 'session_energy', 'elapsed', 'srssi', 'time'))); if ($j) wsb_send('u', json_encode($j)); $beat = microtime(true); } }
	}
}
@fwrite($s, wsb_frame(8, '')); fclose($s);
wsb_send('end', '1');
