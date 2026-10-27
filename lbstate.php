<?php
// Live state for the pages' 5 s updates: the load balancer's heartbeat (no unit calls, no secrets),
// reduced to what the pages show. ?detail=1 adds the per-unit view of lbstatus.php as HTML.
require_once __DIR__ . '/auth_lib.php'; oev_require('public', true);   // login gate (auth_lib.php)
include __DIR__ . '/include_v5.php';
include __DIR__ . '/include_lb.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$hb = oev_filter_hb(evse_lb_heartbeat());   // only what this user may see
if (!is_array($hb)) { echo json_encode(array('ok'=>false, 'now'=>time())); exit; }
// ?unit=N: just that unit (unit.php)
if (isset($_GET['unit'])) {
	$id = (int)$_GET['unit']; $o = array('ok'=>true, 'now'=>time(), 'time'=>(int)$hb['time'], 'age'=>$hb['age'], 'fresh'=>$hb['fresh'], 'mode'=>$hb['mode'] ?? '', 'mqtt'=>!empty($hb['mqtt']), 'u'=>null);
	$all = (array)($hb['monitor'] ?? array()); foreach ((array)$hb['groups'] as $g) foreach ((array)$g['units'] as $u) $all[] = $u;
	foreach ($all as $u) if ((int)$u['id'] === $id) { $o['u'] = evse_lb_view($u); $o['u']['read'] = !empty($u['last_ok']) ? max(0, time() - (int)$u['last_ok']) : null; }
	echo json_encode($o); exit;
}
$o = array('ok'=>true, 'now'=>time(), 'time'=>(int)$hb['time'], 'age'=>$hb['age'], 'fresh'=>$hb['fresh'], 'mode'=>$hb['mode'] ?? '', 'mqtt'=>!empty($hb['mqtt']), 'groups'=>array());
foreach ((array)$hb['groups'] as $g) {
	$us = array(); foreach ((array)$g['units'] as $u) $us[] = evse_lb_view($u);
	$o['groups'][] = array('id'=>(int)$g['id'], 'limit'=>(int)$g['limit'], 'live_amps'=>(float)$g['live_amps'], 'active_total'=>$g['active_total'] ?? null,
		'pause_armed'=>!empty($g['pause_armed']), 'warn'=>array_values((array)$g['warn']), 'units'=>$us);
}
$o['monitor'] = array(); foreach ((array)($hb['monitor'] ?? array()) as $u) $o['monitor'][] = evse_lb_view($u);
if (!empty($_GET['detail']) && oev_is_public()) oev_deny(true, 'Not available with a view-only link.');
if (!empty($_GET['detail'])) { include __DIR__ . '/lbstatus_render.php'; $o['html'] = lbs_render($hb); if (!empty($_GET['log'])) $o['log'] = lbs_log_html(lbs_log_lines()); }
echo json_encode($o);
