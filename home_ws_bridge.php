<?php
/******************************************************************************
 * home_ws_bridge.php - TEST (index.php?ws=1): live feed for the home page.
 * One Server-Sent Events stream per browser tab carries every requested unit:
 * the NAS opens each unit's own WebSocket (/ws, login kept on the server, as
 * ws_bridge.php) and multiplexes them with stream_select in this ONE PHP worker.
 * Limits:
 *  - the stream takes one of ws_bridge.php's OEV_WS_SLOTS (10) feed slots, shared
 *    NAS-wide with the unit pages; none free: 503 and the page stays on its 5 s poll
 *  - at most OEV_HWS_PER_UNIT home-page feeds per unit at once (lock files); a unit over
 *    that is reported 'busy' and that tab polls it every 5 s
 *  - at most OEV_HWS_MAX_UNITS units per stream; only units the page asked for
 *    (enabled, online, not test) that passed the /ws check (ws_lib.php)
 * Read only: nothing is sent to a unit except the handshake and pongs. Ends after
 * OEV_WS_SECONDS; the page opens the next one. Off switch: OEV_WS_ENABLED in ws_lib.php.
 ******************************************************************************/
define('OEV_WS_SECONDS', 25);
define('OEV_WS_SLOTS', 10);        // same slot files and count as ws_bridge.php
define('OEV_HWS_PER_UNIT', 2);     // home-page feeds per unit at once (a unit page adds its own)
define('OEV_HWS_MAX_UNITS', 40);   // units per stream
require_once __DIR__ . '/auth_lib.php'; oev_require('public', true);   // login gate (auth_lib.php)
include __DIR__ . '/include_v5.php';
require_once __DIR__ . '/index_wide_lib.php';   // idx_temp_html()
require_once __DIR__ . '/ws_lib.php';
header('Cache-Control: no-store');
if (!OEV_WS_ENABLED) { http_response_code(404); exit; }
$units = evse_units();
$ids = array();
foreach (explode(',', (string)($_GET['units'] ?? '')) as $x) { $x = (int)$x; if ($x > 0 && isset($units[$x]) && empty($units[$x]['_test']) && !in_array($x, $ids, true)) $ids[] = $x; }
$ids = array_slice($ids, 0, OEV_HWS_MAX_UNITS);
if (!$ids) { http_response_code(404); exit; }

$slot = null;
for ($i = 0; $i < OEV_WS_SLOTS && !$slot; $i++) { $f = fopen(oev_cache_dir() . '/ws_slot_' . $i . '.lock', 'c'); if ($f && flock($f, LOCK_EX | LOCK_NB)) $slot = $f; elseif ($f) fclose($f); }
if (!$slot) { http_response_code(503); exit; }

@set_time_limit(OEV_WS_SECONDS + 30); ignore_user_abort(false);
header('Content-Type: text/event-stream'); header('X-Accel-Buffering: no');
while (ob_get_level()) ob_end_flush();
// output_buffering is 4096 on this NAS: pad every event so it goes out at once (as ws_bridge.php)
function hws_send($ev, $data){ $m = "event: $ev\ndata: " . $data . "\n\n"; echo $m, ':', str_repeat(' ', max(0, 4096 - strlen($m))), "\n\n"; @flush(); }
function hws_view($j){   // same fields and formatting as ws_bridge.php wsb_view()
	$o = array();
	foreach (array('amp', 'pilot', 'state', 'vehicle', 'voltage', 'session_energy', 'elapsed', 'temp') as $k) if (array_key_exists($k, $j) && is_numeric($j[$k])) $o[$k] = $j[$k] + 0;
	if (isset($o['state'])) { $c = (int)$o['state']; $o['state_text'] = evse_state_text($c); $o['state_cls'] = in_array($c, array(1, 2, 3, 254, 255), true) ? 's' . $c : 'serr'; }
	if (isset($o['temp'])) { $t = $o['temp'] / 10; $o['temp_html'] = ($t > -60 && $t < 250) ? idx_temp_html($t) : null; }
	if (isset($o['session_energy'])) $o['energy_text'] = round($o['session_energy'] / 1000, 1) . ' kWh';
	return $o;
}
hws_send('hello', json_encode(array('slot'=>true)));
$socks = array(); $bufs = array(); $locks = array();
foreach ($ids as $id) {
	if (connection_aborted()) break;
	$u = $units[$id];
	$lk = null;
	for ($k = 0; $k < OEV_HWS_PER_UNIT && !$lk; $k++) { $f = fopen(oev_cache_dir() . '/ws_home_u' . $id . '_' . $k . '.lock', 'c'); if ($f && flock($f, LOCK_EX | LOCK_NB)) $lk = $f; elseif ($f) fclose($f); }
	if (!$lk) { hws_send('busy', json_encode(array('id'=>$id))); continue; }
	$ver = evse_detect($u)['version'] ?? '';
	if (!oev_ws_capable($u, $ver)) { flock($lk, LOCK_UN); fclose($lk); hws_send('fail', json_encode(array('id'=>$id, 'why'=>'no /ws'))); continue; }
	$s = oev_ws_open($u);
	if (is_string($s)) { oev_ws_cap_put($id, $ver, false, $s); flock($lk, LOCK_UN); fclose($lk); hws_send('fail', json_encode(array('id'=>$id, 'why'=>$s))); continue; }
	$socks[$id] = $s; $bufs[$id] = ''; $locks[$id] = $lk;
	hws_send('connected', json_encode(array('id'=>$id)));   // handshake done: the page shows the blue ring now
}
$t0 = microtime(true); $beat = $t0;
while ($socks && microtime(true) - $t0 < OEV_WS_SECONDS && !connection_aborted()) {
	$r = array_values($socks); $w = $x = null;
	if (!@stream_select($r, $w, $x, 1)) { if (microtime(true) - $beat > 10) { hws_send('ping', '1'); $beat = microtime(true); } continue; }
	foreach ($r as $s) {
		$id = array_search($s, $socks, true); if ($id === false) continue;
		$d = fread($s, 8192); $drop = ($d === false || ($d === '' && feof($s)));
		if (!$drop) {
			$bufs[$id] .= $d;
			while (($f = oev_ws_frame($bufs[$id]))) {
				if ($f[0] == 9) { fwrite($s, oev_ws_out(10, $f[1])); continue; }
				if ($f[0] == 8) { $drop = true; break; }
				if ($f[0] == 1) { $j = json_decode($f[1], true); if (is_array($j) && ($v = hws_view($j))) { $v['id'] = $id; hws_send('u', json_encode($v)); $beat = microtime(true); } }
			}
			if ($f === false) $drop = true;
		}
		if ($drop) { @fclose($s); unset($socks[$id]); flock($locks[$id], LOCK_UN); fclose($locks[$id]); unset($locks[$id]); hws_send('drop', json_encode(array('id'=>$id))); }
	}
}
foreach ($socks as $id => $s) { @fwrite($s, oev_ws_out(8, '')); fclose($s); }
foreach ($locks as $lk) { flock($lk, LOCK_UN); fclose($lk); }
hws_send('end', '1');
