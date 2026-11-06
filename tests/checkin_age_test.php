<?php
// php tests/checkin_age_test.php — Last check-in uses MQTT or HTTP last_ok (MQTT-off units)
$f = 0; function ck($n, $ok){ global $f; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . "\n"; }
require_once __DIR__ . '/../include_v5.php';
require_once __DIR__ . '/../include_lb.php';
require_once __DIR__ . '/../index_wide_lib.php';
if (!function_exists('oev_is_public')) { function oev_is_public(){ return false; } }
if (!function_exists('oev_is_admin')) { function oev_is_admin(){ return false; } }

$now = time();
// MQTT-off monitor unit: mqtt_seen ~27h old, HTTP last_ok fresh (the 3R 120v case)
$u2 = array('id'=>2,'name'=>'N Penn: 3R 120v','ok'=>true,'state'=>1,'plugged'=>false,'amps'=>0,'setting'=>12,'saved'=>12,
	'mqtt_seen'=>$now - 98632, 'last_ok'=>$now - 5, 'charging'=>false,'monitor'=>true);
$v = evse_lb_view($u2);
ck('mqtt-off checkin age uses last_ok', $v['checkin'] !== null && $v['checkin'] <= 10);
ck('mqtt-off not stale', $v['stale'] === false);

// both stale
$bad = $u2; $bad['last_ok'] = $now - 200; $bad['mqtt_seen'] = $now - 200;
$vb = evse_lb_view($bad);
ck('both old => stale', $vb['stale'] === true && $vb['checkin'] >= 200);

// MQTT fresh, last_ok old
$mq = $u2; $mq['mqtt_seen'] = $now - 3; $mq['last_ok'] = $now - 600;
$vm = evse_lb_view($mq);
ck('mqtt fresh keeps live', $vm['stale'] === false && $vm['checkin'] <= 5);

// never seen
$n = array('id'=>9,'ok'=>false,'state'=>null);
$vn = evse_lb_view($n);
ck('never seen null checkin+stale', $vn['checkin'] === null && $vn['stale'] === true);

// wide initial paint: logger last_ok_at alone is enough when no heartbeat unit
$unit = array('id'=>2,'name'=>'N Penn: 3R 120v','evseurl'=>'http://192.168.1.158/','breaker'=>16);
$e = array('statecode'=>1,'state'=>'Ready','connected'=>0,'pilot'=>12,'amps'=>0,'api'=>'http','lifetimeKWH'=>0,'sched'=>false,'lim'=>null);
$us = array('last_ok_at'=>$now - 8, 'last_poll_at'=>$now - 8, 'temp_max_c'=>25.5, 'srssi'=>-56, 'wifi_uptime'=>100000, 'level'=>1, 'volts'=>120);
$w = idx_wide_unit($unit, $e, null, null, 0, array(), $us, 120, 'unit.php?unitid=2');
ck('wide uses logger last_ok_at', !empty($w['d']['checkin']) && strpos($w['d']['checkin'], 'h ago') === false && (strpos($w['d']['checkin'], 's ago') !== false || strpos($w['d']['checkin'], 'min ago') !== false));
ck('wide seen from logger', $w['seen'] === $us['last_ok_at']);

// wide with mqtt-stale heartbeat still shows fresh from last_ok
$w2 = idx_wide_unit($unit, $e, $u2, $v, 0, array(), $us, 120, 'unit.php?unitid=2');
ck('wide mqtt-off shows seconds not hours', strpos($w2['d']['checkin'], 'h ago') === false);
ck('wide mqtt-off green dot', strpos($w2['d']['checkin'], 'lbd g') !== false);

exit($f ? 1 : 0);
