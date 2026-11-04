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

/* ---- Shared connection ("hub"): ONE WebSocket per unit from the NAS, shared by every bridge request.
 * No daemon. Per unit, in the app's cache folder:
 *   ws_own_<id>.lock   flock held by the request that owns the unit's /ws connection. The kernel drops it
 *                      when that PHP process ends or dies, so a lock can never go stale.
 *   ws_evt_<id>.log    the owner appends one JSON line per event: c = connected, u = values, f = failed,
 *                      d = connection dropped. Every request (owner included) tails it and relays it to
 *                      its own browser. Cut back to empty past OEV_HUB_LOG_MAX; readers notice and restart.
 *   ws_st_<id>.json    the latest value of every field since the owner connected, so a request that
 *                      starts while another owns the unit shows everything at once.
 * Every request tries to take the lock about once a second; when the owner's stream ends (or it dies),
 * one of the remaining readers takes over within ~1 s and connects again. The unit never sees more than
 * one connection from the NAS, however many tabs are open. */
if (!defined('OEV_WS_SLOTS')) define('OEV_WS_SLOTS', 20);   // bridge streams at once, NAS-wide (one PHP worker each; the pool has 50)
define('OEV_HUB_LOG_MAX', 262144);
function oev_hub_file($id, $kind){ return oev_cache_dir() . '/ws_' . $kind . '_' . (int)$id . ($kind == 'own' ? '.lock' : ($kind == 'st' ? '.json' : '.log')); }
function oev_hub_raw($j){ $o = array(); foreach (array('amp', 'pilot', 'state', 'vehicle', 'voltage', 'session_energy', 'elapsed', 'srssi', 'temp') as $k) if (array_key_exists($k, $j) && is_numeric($j[$k])) $o[$k] = $j[$k] + 0; return $o; }
function oev_hub_put_state($id, $st){ $f = oev_hub_file($id, 'st'); $t = $f . '.' . getmypid() . '.tmp'; if (@file_put_contents($t, json_encode($st)) !== false) @rename($t, $f); }
function oev_hub_log($h, $rec){ if (fstat($h)['size'] > OEV_HUB_LOG_MAX) ftruncate($h, 0); fwrite($h, json_encode($rec) . "\n"); fflush($h); }
// one bridge stream for $units (id => unit) for $seconds. $emit($id, $event, $data): $event = connected / u (raw
// display values, the bridge formats them) / fail (text) / ping. Returns when time is up or the browser left.
function oev_hub_run($units, $seconds, $emit){
	$H = array();
	foreach ($units as $id => $u) {
		$lf = oev_hub_file($id, 'evt'); $rh = @fopen($lf, 'c+');
		if (!$rh) { $emit($id, 'fail', 'cache'); continue; }
		$H[$id] = array('u'=>$u, 'rh'=>$rh, 'off'=>fstat($rh)['size'], 'rem'=>'', 'lk'=>null, 'wh'=>null, 'sock'=>null, 'buf'=>'', 'st'=>array(), 'try'=>0, 'failed'=>false);
	}
	$take = function($id) use (&$H){   // try to become the owner; true = we now hold the unit's connection
		$x = &$H[$id]; $x['try'] = microtime(true);
		$lk = @fopen(oev_hub_file($id, 'own'), 'c'); if (!$lk) return false;
		if (!flock($lk, LOCK_EX | LOCK_NB)) { fclose($lk); return false; }
		$x['lk'] = $lk; $x['wh'] = fopen(oev_hub_file($id, 'evt'), 'a');
		$ver = evse_detect($x['u'])['version'] ?? '';
		$s = oev_ws_capable($x['u'], $ver) ? oev_ws_open($x['u']) : 'no /ws';
		if (is_string($s)) {
			if ($s !== 'no /ws') oev_ws_cap_put($id, $ver, false, $s);
			oev_hub_log($x['wh'], array('t'=>'f', 'why'=>$s));
			fclose($x['wh']); $x['wh'] = null; flock($lk, LOCK_UN); fclose($lk); $x['lk'] = null;
			return false;
		}
		$x['sock'] = $s; $x['buf'] = ''; $x['st'] = array('conn'=>time());
		oev_hub_put_state($id, $x['st']);
		oev_hub_log($x['wh'], array('t'=>'c'));
		return true;
	};
	$release = function($id, $note) use (&$H){
		$x = &$H[$id];
		if ($x['sock']) { @fwrite($x['sock'], oev_ws_out(8, '')); @fclose($x['sock']); $x['sock'] = null; }
		if ($x['wh']) { if ($note) oev_hub_log($x['wh'], array('t'=>$note)); fclose($x['wh']); $x['wh'] = null; }
		if ($x['lk']) { flock($x['lk'], LOCK_UN); fclose($x['lk']); $x['lk'] = null; }
	};
	// start: own it, or show what the current owner already has
	foreach (array_keys($H) as $id) {
		if ($take($id)) continue;
		$st = @json_decode((string)@file_get_contents(oev_hub_file($id, 'st')), true);
		$lk = @fopen(oev_hub_file($id, 'own'), 'c'); $alive = $lk && !flock($lk, LOCK_EX | LOCK_NB); if ($lk) { if (!$alive) flock($lk, LOCK_UN); fclose($lk); }
		if ($alive && is_array($st) && !empty($st['conn'])) { $emit($id, 'connected', ''); unset($st['conn']); if ($st) $emit($id, 'u', $st); }
	}
	$t0 = microtime(true); $beat = $t0;
	while (microtime(true) - $t0 < $seconds && !connection_aborted()) {
		foreach ($H as $id => $x) if (!$x['lk'] && !$x['failed'] && microtime(true) - $x['try'] >= 1) $take($id);
		$r = array(); foreach ($H as $id => $x) if ($x['sock']) $r[$id] = $x['sock'];
		if ($r) { $rr = array_values($r); $w = $e = null; $n = @stream_select($rr, $w, $e, 0, 250000); } else { usleep(250000); $rr = array(); }
		foreach ($rr as $s) {
			$id = array_search($s, $r, true); if ($id === false) continue; $x = &$H[$id];
			$d = fread($s, 8192); $drop = ($d === false || ($d === '' && feof($s)));
			if (!$drop) {
				$x['buf'] .= $d;
				while (($f = oev_ws_frame($x['buf']))) {
					if ($f[0] == 9) { fwrite($s, oev_ws_out(10, $f[1])); continue; }
					if ($f[0] == 8) { $drop = true; break; }
					if ($f[0] == 1) { $j = json_decode($f[1], true); if (is_array($j) && ($v = oev_hub_raw($j))) { oev_hub_log($x['wh'], array('t'=>'u', 'd'=>$v)); $x['st'] = array_merge($x['st'], $v); oev_hub_put_state($id, $x['st']); } }
				}
				if ($f === false) $drop = true;
			}
			if ($drop) $release($id, 'd');
			unset($x);
		}
		// relay what the owner (maybe this request) logged
		foreach ($H as $id => $x) {
			clearstatcache(false, oev_hub_file($id, 'evt'));
			$sz = fstat($x['rh'])['size'];
			if ($sz < $x['off']) { $H[$id]['off'] = 0; $H[$id]['rem'] = ''; }   // cut back by the owner
			if ($sz <= $H[$id]['off']) continue;
			fseek($x['rh'], $H[$id]['off']); $data = fread($x['rh'], $sz - $H[$id]['off']); $H[$id]['off'] += strlen($data);
			$lines = explode("\n", $H[$id]['rem'] . $data); $H[$id]['rem'] = array_pop($lines);
			foreach ($lines as $l) {
				$m = json_decode($l, true); if (!is_array($m)) continue;
				if ($m['t'] == 'c') $emit($id, 'connected', '');
				elseif ($m['t'] == 'u') $emit($id, 'u', $m['d']);
				elseif ($m['t'] == 'f') { $H[$id]['failed'] = true; $emit($id, 'fail', (string)$m['why']); }
				$beat = microtime(true);
			}
		}
		if (microtime(true) - $beat > 10) { $emit(0, 'ping', ''); $beat = microtime(true); }
	}
	foreach (array_keys($H) as $id) { $release($id, ''); fclose($H[$id]['rh']); }
}
