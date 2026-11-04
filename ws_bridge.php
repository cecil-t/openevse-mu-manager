<?php
/******************************************************************************
 * ws_bridge.php - live feed from a unit's own WebSocket (/ws), relayed to
 * the browser as Server-Sent Events. unit.php uses it by default (?ws=0 turns it off) and only
 * for units that passed the /ws check (ws_lib.php oev_ws_capable()).
 * The NAS opens the WebSocket (with the unit's login, which never leaves the
 * server), passes on each update for up to OEV_WS_SECONDS, then ends; the
 * browser reconnects. Read only: nothing is ever sent to the unit except the
 * WebSocket handshake and replies to its pings. Only display values are passed
 * on (no settings, no network details), with the page's own formatting.
 * Off switch: OEV_WS_ENABLED in ws_lib.php.
 ******************************************************************************/
define('OEV_WS_SECONDS', 25);   // one PHP worker per open tab for this long, then a reconnect
define('OEV_WS_SLOTS', 10);     // at most this many feeds at once (PHP-FPM allows 50 workers)
require_once __DIR__ . '/auth_lib.php'; oev_require('public', true);   // login gate (auth_lib.php)
include __DIR__ . '/include_v5.php';
require_once __DIR__ . '/index_wide_lib.php';   // idx_temp_html()
require_once __DIR__ . '/ws_lib.php';
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
// PHP's output_buffering (4096 on this NAS, set per site) holds output until 4 KB: pad each event with an SSE comment so it goes out at once
function wsb_send($ev, $data){ $m = "event: $ev\ndata: " . $data . "\n\n"; echo $m, ':', str_repeat(' ', max(0, 4096 - strlen($m))), "\n\n"; @flush(); }
// one update -> what the page shows (same functions as unit.php)
function wsb_view($j){
	$o = array();
	foreach (array('amp', 'pilot', 'state', 'vehicle', 'voltage', 'session_energy', 'elapsed', 'srssi', 'temp') as $k) if (array_key_exists($k, $j) && is_numeric($j[$k])) $o[$k] = $j[$k] + 0;
	if (isset($o['state'])) { $c = (int)$o['state']; $o['state_text'] = evse_state_text($c); $o['state_cls'] = in_array($c, array(1, 2, 3, 254, 255), true) ? 's' . $c : 'serr'; }
	if (isset($o['temp'])) { $t = $o['temp'] / 10; $o['temp_html'] = ($t > -60 && $t < 250) ? idx_temp_html($t, true) : null; }   // tenths of a degree C
	if (isset($o['session_energy'])) $o['energy_text'] = round($o['session_energy'] / 1000, 1) . ' kWh';
	if (isset($o['elapsed'])) $o['elapsed_text'] = $o['elapsed'] > 0 ? evse_elapsed_text($o['elapsed']) : "\u{2014}";
	return $o;
}

$s = oev_ws_open($u);
if (is_string($s)) { oev_ws_cap_put($id, evse_detect($u)['version'] ?? '', false, $s); wsb_send('fail', json_encode($s)); exit; }   // the page drops back to its 5 s poll
wsb_send('connected', '1');   // handshake done (the page shows its blue ring now)
$buf = ''; $t0 = microtime(true); $beat = $t0;
while (microtime(true) - $t0 < OEV_WS_SECONDS && !connection_aborted()) {
	$r = array($s); $w = $x = null;
	if (!@stream_select($r, $w, $x, 1)) { if (microtime(true) - $beat > 10) { wsb_send('ping', '1'); $beat = microtime(true); } continue; }
	$d = fread($s, 8192); if ($d === false || ($d === '' && feof($s))) break;
	$buf .= $d;
	while (($f = oev_ws_frame($buf))) {   // unit -> us frames are not masked
		if ($f[0] == 9) { fwrite($s, oev_ws_out(10, $f[1])); continue; }   // ping -> pong
		if ($f[0] == 8) break 2;
		if ($f[0] == 1) { $j = json_decode($f[1], true); if (is_array($j) && ($v = wsb_view($j))) { wsb_send('u', json_encode($v)); $beat = microtime(true); } }
	}
	if ($f === false) break;
}
@fwrite($s, oev_ws_out(8, '')); fclose($s);
wsb_send('end', '1');
