<?php
/******************************************************************************
 * home_ws_bridge.php - live feed for the home page.
 * One Server-Sent Events stream per browser tab carries every requested unit:
 * every requested unit in this ONE PHP worker.
 * Limits:
 *  - the units see ONE connection from the NAS each, however many tabs are open
 *    (ws_lib.php oev_hub_run, shared with the unit pages)
 *  - the stream takes one of the OEV_WS_SLOTS (ws_lib.php) stream slots, shared NAS-wide
 *    with the unit pages (one PHP worker each); none free: 503, the page stays on its 5 s poll
 *  - at most OEV_HWS_MAX_UNITS units per stream; only units the page asked for
 *    (enabled, online, not test) that passed the /ws check (ws_lib.php)
 * Read only: nothing is sent to a unit except the handshake and pongs. Ends after
 * OEV_WS_SECONDS; the page opens the next one. Off switch: OEV_WS_ENABLED in ws_lib.php.
 ******************************************************************************/
define('OEV_WS_SECONDS', 25);
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
$feed = array(); foreach ($ids as $id) $feed[$id] = $units[$id];
// one shared connection per unit (ws_lib.php oev_hub_run), the same one the unit pages use
oev_hub_run($feed, OEV_WS_SECONDS, function($id, $ev, $d){
	if ($ev == 'connected') hws_send('connected', json_encode(array('id'=>$id)));   // the page shows the blue ring now
	elseif ($ev == 'u') { $v = hws_view($d); if ($v) { $v['id'] = $id; hws_send('u', json_encode($v)); } }
	elseif ($ev == 'fail') hws_send('fail', json_encode(array('id'=>$id, 'why'=>$d)));
	elseif ($ev == 'ping') hws_send('ping', '1');
});
hws_send('end', '1');
