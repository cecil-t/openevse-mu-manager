<?php
/******************************************************************************
 * ws_lib.php - WebSocket live feed helpers (ws_bridge.php, unit.php; ?ws=0 turns the feed off).
 * oev_ws_open(): the unit's /ws handshake (login sent from the server only).
 * oev_ws_capable(): does this unit have a working /ws? Probed once, kept in the
 * app's cache folder (run/cache/ws_cap_<id>.json); probed again after a day,
 * when the unit's WiFi firmware version changes, or after the feed fails.
 ******************************************************************************/
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
define('OEV_WS_ENABLED', true);         // kill switch for the live feed (unit.php then keeps its 5 s poll only)
define('OEV_WS_CAP_TTL', 86400);        // re-probe after a day
define('OEV_WS_FAIL_TTL', 3600);        // a failed unit is tried again after an hour

// socket after a 101 answer, or a short error text
function oev_ws_open($u, $timeout = 2){
	$p = parse_url($u['evseurl']); $host = $p['host'] ?? ''; $port = (int)($p['port'] ?? 80);
	if (($p['scheme'] ?? 'http') !== 'http' || $host === '') return 'not http';
	$s = @stream_socket_client('tcp://' . $host . ':' . $port, $en, $es, $timeout);
	if (!$s) return 'connect';
	stream_set_timeout($s, 3);
	$hd = "GET /ws HTTP/1.1\r\nHost: $host\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: " . base64_encode(random_bytes(16)) . "\r\nSec-WebSocket-Version: 13\r\n";
	if ($u['username'] != '' && $u['password'] != '')   // newer firmware wants the login on /ws too
		$hd .= (evse_auth_mode($u['evseurl']) === 'digest' ? evse_digest_header($u['evseurl'], $u['username'], $u['password'], 'GET', rtrim($u['evseurl'], '/') . '/ws') : 'Authorization: Basic ' . base64_encode($u['username'] . ':' . $u['password'])) . "\r\n";
	fwrite($s, $hd . "\r\n");
	$st = (string)fgets($s); while (($l = fgets($s)) !== false && $l !== "\r\n");
	if (strpos($st, ' 101') === false) { fclose($s); return trim($st) ?: 'no answer'; }
	return $s;
}
// next complete frame from $buf: [opcode, payload], null = need more, false = bad frame
function oev_ws_frame(&$buf){
	if (strlen($buf) < 2) return null;
	$b1 = ord($buf[0]); $len = ord($buf[1]) & 127; $o = 2;
	if ($len == 126) { if (strlen($buf) < 4) return null; $len = unpack('n', substr($buf, 2, 2))[1]; $o = 4; }
	elseif ($len == 127) { if (strlen($buf) < 10) return null; $len = unpack('J', substr($buf, 2, 8))[1]; $o = 10; }
	if ($len > 65536) return false;
	if (strlen($buf) < $o + $len) return null;
	$f = array($b1 & 15, substr($buf, $o, $len)); $buf = substr($buf, $o + $len);
	return $f;
}
function oev_ws_out($op, $payload){   // client frames must be masked
	$m = random_bytes(4); $n = strlen($payload);
	$h = chr(0x80 | $op) . ($n < 126 ? chr(0x80 | $n) : chr(0x80 | 126) . pack('n', $n));
	$x = ''; for ($i = 0; $i < $n; $i++) $x .= $payload[$i] ^ $m[$i % 4];
	return $h . $m . $x;
}
function oev_ws_cap_file($id){ return oev_cache_dir() . '/ws_cap_' . (int)$id . '.json'; }
function oev_ws_cap_put($id, $ver, $ok, $why){ @file_put_contents(oev_ws_cap_file($id), json_encode(array('ok'=>$ok, 'ver'=>(string)$ver, 'at'=>time(), 'why'=>$why)), LOCK_EX); }
// passes when the unit answers the handshake and sends a JSON status frame within 3 s
function oev_ws_probe($u, $ver){
	$s = oev_ws_open($u); $ok = false; $why = is_string($s) ? $s : '';
	if (!is_string($s)) {
		$buf = ''; $t = microtime(true);
		while (!$ok && microtime(true) - $t < 3) {
			$r = array($s); $w = $x = null;
			if (!@stream_select($r, $w, $x, 1)) continue;
			$d = fread($s, 8192); if ($d === false || ($d === '' && feof($s))) break;
			$buf .= $d;
			while (($f = oev_ws_frame($buf))) if ($f[0] == 1 && is_array(json_decode($f[1], true))) { $ok = true; break; }
		}
		@fwrite($s, oev_ws_out(8, '')); fclose($s);
		if (!$ok) $why = 'no status frame';
	}
	oev_ws_cap_put($u['id'], $ver, $ok, $why);
	return $ok;
}
function oev_ws_capable($u, $ver){
	if (!OEV_WS_ENABLED) return false;
	$c = @json_decode((string)@file_get_contents(oev_ws_cap_file($u['id'])), true);
	if (is_array($c) && $c['ver'] === (string)$ver && time() - (int)$c['at'] < ($c['ok'] ? OEV_WS_CAP_TTL : OEV_WS_FAIL_TTL)) return (bool)$c['ok'];
	return oev_ws_probe($u, $ver);
}
