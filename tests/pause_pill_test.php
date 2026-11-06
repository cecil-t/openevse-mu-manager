<?php
// php tests/pause_pill_test.php — initial Paused pill from heartbeat lb_paused (UI only)
$f = 0; function ck($n, $ok){ global $f; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . "\n"; }
require_once __DIR__ . '/../include_v5.php';
require_once __DIR__ . '/../include_lb.php';
require_once __DIR__ . '/../index_wide_lib.php';
if (!function_exists('oev_is_public')) { function oev_is_public(){ return false; } }
if (!function_exists('oev_is_admin')) { function oev_is_admin(){ return false; } }

$hb_paused = array('id'=>4,'name'=>'1F','ok'=>true,'state'=>254,'plugged'=>false,'amps'=>0,'setting'=>6,'saved'=>40,'lb_paused'=>true,'pause'=>true,'why'=>'paused (idle)','mqtt_seen'=>time(),'last_ok'=>time(),'charging'=>false,'sleepfree'=>false,'cap'=>null);
$hb_manual = array('id'=>3,'name'=>'3F','ok'=>true,'state'=>254,'plugged'=>true,'amps'=>0,'setting'=>6,'saved'=>40,'lb_paused'=>false,'pause'=>false,'why'=>'','mqtt_seen'=>time(),'last_ok'=>time(),'charging'=>false,'sleepfree'=>true,'cap'=>null);
$hb_charge = array('id'=>1,'name'=>'3R','ok'=>true,'state'=>3,'plugged'=>true,'amps'=>38.5,'setting'=>40,'saved'=>48,'lb_paused'=>false,'pause'=>false,'why'=>'','mqtt_seen'=>time(),'last_ok'=>time(),'charging'=>true,'sleepfree'=>false,'cap'=>null);

$vp = evse_lb_view($hb_paused);
$vm = evse_lb_view($hb_manual);
$vc = evse_lb_view($hb_charge);
ck('idle pause view pill', $vp['pill'] === 'Paused' && $vp['paused'] === true && $vp['code'] === 254);
ck('manual sleep view pill', $vm['pill'] === 'Sleeping' && $vm['paused'] === false && $vm['code'] === 254);
ck('charging view pill', $vc['pill'] === 'Charging' && $vc['paused'] === false);

$unit = array('id'=>4,'name'=>'1F','evseurl'=>'http://example.test/','breaker'=>40);
$e_sleep = array('statecode'=>254,'state'=>'Sleeping','connected'=>0,'pilot'=>6,'amps'=>0,'api'=>'http','lifetimeKWH'=>0);
$w = idx_wide_unit($unit, $e_sleep, $hb_paused, $vp, 1, array(), null, 240, 'unit.php?unitid=4');
ck('wide idle pause pill+flag', $w['pill'] === 'Paused' && !empty($w['paused']) && $w['ptip'] === evse_pill_title(254, true));

$unit3 = array('id'=>3,'name'=>'3F','evseurl'=>'http://example.test/','breaker'=>40);
$e_man = array('statecode'=>254,'state'=>'Sleeping','connected'=>1,'pilot'=>6,'amps'=>0,'api'=>'http','lifetimeKWH'=>0);
$wm = idx_wide_unit($unit3, $e_man, $hb_manual, $vm, 1, array(), null, 240, 'unit.php?unitid=3');
ck('wide manual sleep not paused', $wm['pill'] === 'Sleeping' && empty($wm['paused']));

// unit page initial overlay rule (same as unit.php)
$lv = $vp; $e = $e_sleep;
$st_txt = ($lv && !empty($lv['paused'])) ? 'Paused' : $e['state'];
ck('unit initial idle pause', $st_txt === 'Paused');
$lv = $vm; $e = $e_man;
$st_txt = ($lv && !empty($lv['paused'])) ? 'Paused' : $e['state'];
ck('unit initial manual sleep', $st_txt === 'Sleeping');
// degraded: no fresh lv
$lv = null; $e = $e_sleep;
$st_txt = ($lv && !empty($lv['paused'])) ? 'Paused' : $e['state'];
ck('unit fallback without hb', $st_txt === 'Sleeping');

// home phone overlay rule (index.php)
$lv = $vp; $e = $e_sleep;
$stx = ($lv && $lv['paused']) ? 'Paused' : $e['state'];
ck('home phone idle pause', $stx === 'Paused');
$lv = $vm;
$stx = ($lv && $lv['paused']) ? 'Paused' : $e_man['state'];
ck('home phone manual sleep', $stx === 'Sleeping');

exit($f ? 1 : 0);
