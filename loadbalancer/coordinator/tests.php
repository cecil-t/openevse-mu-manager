<?php
// php tests.php [-v]  - tests for the split, headroom and a second-by-second simulation (no units touched)
require __DIR__ . '/lb_core.php';
require __DIR__ . '/lb_sim.php';
$fail = 0; $V = in_array('-v', $argv);
function u($id, $plugged, $since = null, $rating = 48, $setting = 48, $prio = null, $cap = null){ return ['id'=>$id,'rating'=>$rating,'plugged'=>$plugged,'plug_since'=>$since,'prio'=>$prio,'setting'=>$setting,'cap'=>$cap]; }
function order_ok($w, $settle){ $lastDown = -999; foreach ($w as [$t, $id, $f, $to, $k]) { if ($k !== 'full' && $to < $f) $lastDown = $t; elseif ($t - $lastDown < $settle) return false; } return true; }
function act($s){ $x = 0; foreach ($s['final_live'] as $id => $v) if (!in_array($id, $s['final_paused'])) $x += $v; return $x; }
function check($name, $ok, $detail = ''){ global $fail; if (!$ok) $fail++; printf("%s %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail !== '' ? '  ' . $detail : ''); }
function t($name, $limit, $units, $expect, $expectSaved = null){
	$p = lb_plan($limit, $units);
	$got = []; foreach ($p['units'] as $id => $x) $got[$id] = $x['pause'] ? 'P' . $x['target'] : ($x['target'] === null ? 'keep' : $x['target']);
	$sv = []; $rt = []; foreach ($p['units'] as $id => $x) $sv[$id] = $x['saved']; foreach ($units as $u) $rt[$u['id']] = max(6, $u['rating']);
	$ok = ($got == $expect) && ($p['total'] <= $limit || $p['warn']) && $sv == $rt;
	check(sprintf('%-50s total %3d A', $name, $p['total']), $ok, json_encode($got) . ($p['warn'] ? '  warn: ' . $p['warn'][0] : ''));
}
echo "== split (all temporary; saved = full rating; idle units: leftover up to fair share, min 6 A)\n";
t('nothing plugged in: 20 A each (80/4)', 80, [u(1,false),u(3,false),u(4,false),u(5,false)], [1=>20,3=>20,4=>20,5=>20]);
t('one car: 48, idle share the 14 A left', 80, [u(1,true,100),u(3,false),u(4,false),u(5,false)], [1=>48,3=>10,4=>11,5=>11]);
t('two cars: 34/34, idle 6', 80, [u(1,true,100),u(3,true,200),u(4,false),u(5,false)], [1=>34,3=>34,4=>6,5=>6]);
t('three cars', 80, [u(1,true,100),u(3,true,200),u(4,false),u(5,true,300)], [1=>24,3=>25,4=>6,5=>25]);
t('four cars: 20 each', 80, [u(1,true,1),u(3,true,2),u(4,true,3),u(5,true,4)], [1=>20,3=>20,4=>20,5=>20]);
t('one unreadable (saved 48)', 80, [u(1,true,1),u(3,null,null,48,48),u(4,false),u(5,false)], [1=>20,3=>'keep',4=>6,5=>6]);
t('16 A unit + 2 cars on 80 A, 3 units', 80, [u(1,true,1),u(2,true,2,16),u(3,false)], [1=>48,2=>16,3=>16]);
t('idle fair share capped at rating (16 A unit)', 80, [u(1,false),u(2,false,null,16),u(3,false)], [1=>26,2=>16,3=>26]);
t('14 units, 13 cars: all at 6 A (warn)', 80, array_merge(array_map(function($i){ return u($i,true,$i); }, range(1,13)), [u(14,false)]), array_combine(range(1,14), array_fill(0,14,6)));
t('15 units, 14 cars: last plugged in pauses', 80, array_merge(array_map(function($i){ return u($i,true,$i); }, range(1,14)), [u(15,false)]), array_combine(range(1,15), array_merge(array_fill(0,13,6), ['P6', 6])));
t('priority field beats plug-in order (17 A)', 17, [u(1,true,1,48,48,5),u(2,true,2,48,48,1),u(3,true,0)], [1=>6,2=>6,3=>'P6']);
echo "== headroom split (cap = draw + margin; freed amps go to the others)\n";
t('2 cars, u1 balancing (cap 7): u3 full 48, idle get rest', 80, [u(1,true,1,48,34,null,7),u(3,true,2),u(4,false),u(5,false)], [1=>7,3=>48,4=>12,5=>13]);
t('4 cars, one capped at 10: others 23/23/24', 80, [u(1,true,1,48,20,null,10),u(3,true,2),u(4,true,3),u(5,true,4)], [1=>10,3=>23,4=>23,5=>24]);
$R4 = [1=>48,3=>48,4=>48,5=>48];
$o = lb_write_order(lb_plan(80, [u(1,true,1,48,34,null,7),u(3,true,2),u(4,false),u(5,false)]), [1=>34,3=>34,4=>6,5=>6], $R4, $R4, [1=>true,3=>true]);
check('write order: cut first, then raises (all temporary)', $o == [[1,34,7,'temp'],[3,34,48,'temp'],[4,6,12,'temp'],[5,6,13,'temp']], json_encode($o));
$o = lb_write_order(lb_plan(80, [u(1,true,1,48,48),u(3,true,2,48,6),u(4,false,null,48,48)]), [1=>48,3=>6,4=>48], [1=>48,3=>48,4=>48], [1=>48,3=>48,4=>48]);
check('write order lowers first', $o == [[1,48,37,'temp'],[4,48,6,'temp'],[3,6,37,'temp']], json_encode($o));
// going to "saved = full rating" from example saved shares (unit 1 34, unit 3 27, unit 4 6, unit 5 6): shares first, saved raised with the raises
$o = lb_write_order(lb_plan(80, [u(1,true,1,48,34),u(3,true,2,48,27),u(4,false,null,24,6),u(5,false,null,48,6)]), [1=>34,3=>27,4=>6,5=>6], [1=>34,3=>27,4=>6,5=>6], [1=>48,3=>48,4=>24,5=>48], [1=>true,3=>true]);
check('to full saved: idle units saved at once, charging ones only when their share needs it; lowers first', $o == [[3,27,34,'full'],[4,6,6,'full'],[5,6,6,'full']], json_encode($o));
$o = lb_write_order(lb_plan(80, [u(1,true,1,48,34),u(3,true,2,48,34),u(4,false,null,24,6),u(5,false,null,48,6)]), [1=>34,3=>34,4=>6,5=>6], [1=>34,3=>48,4=>24,5=>48], [1=>48,3=>48,4=>24,5=>48], [1=>true,3=>true]);
check('charging unit whose share fits under its saved value: saved left for later (no 0.1 s pilot jump)', $o == [], json_encode($o));
echo "== headroom state machine\n";
$h = []; $ev = null; $evs = [];
for ($s = 0; $s <= 600; $s += 5) { [$h, $ev] = lb_hr_step($h, ['charging'=>true, 'amps'=>($s < 60 ? 10 : 30), 'alloc'=>34], $s); if ($ev) $evs[] = $ev; }
check('grace: slow ramp in first 3 min is not cut; steady 30 of 34 A never cut (<4 A gap)', !$evs, json_encode($evs));
$h = []; $evs = [];
for ($s = 0; $s <= 900; $s += 5) { [$h, $ev] = lb_hr_step($h, ['charging'=>true, 'amps'=>3.2, 'alloc'=>34], $s); if ($ev) $evs[] = [$s, $ev]; }
check('cell balancing 3.2 A of 34: one cut to 8 A at 3 min (grace), no more (8-8<2)', count($evs) == 1 && $evs[0][1][2] == 8 && $evs[0][0] == 180, json_encode($evs));
$h = []; $evs = [];
for ($s = 0; $s <= 900; $s += 5) { [$h, $ev] = lb_hr_step($h, ['charging'=>true, 'amps'=>($s % 120 < 60 ? 26 : 34), 'alloc'=>34], $s); if ($ev) $evs[] = [$s, $ev]; }
check('hysteresis: car swinging 26/34 A every minute is never cut', !$evs, json_encode($evs));

echo "== schedules (asleep = idle even with a car plugged in), whitelist, pause steps\n";
check('effective plugged: asleep 254 / disabled 255 = idle; paused by us = what is really plugged in', lb_effective_plugged(true, 254, false) === false && lb_effective_plugged(true, 255, false) === false && lb_effective_plugged(true, 255, true) === true && lb_effective_plugged(false, 255, true) === false && lb_effective_plugged(true, 3, false) === true && lb_effective_plugged(null, 1, false) === null);
t('unit 1 asleep with car (idle) + unit 3 charging: unit 1 holds leftover', 80, [u(1,false),u(3,true,1),u(4,false),u(5,false)], [1=>10,3=>48,4=>11,5=>11]);
$bad = array_filter(['$ST 0 0 8 30', '$FS', '$FR', '$SL 2', '$SC 0', '$SC 5', '$SC 20 M', '$S4 1', 'status'], 'lb_cmd_allowed');
check('whitelist refuses schedule/sleep/reboot/invalid ($ST, $FS, $FR, $SC 0, $SC 5, $SC 20 M ...)', !$bad, json_encode(array_values($bad)));
check('whitelist allows $SC 20, $SC 20 V, $FD, $FE, $GE, $GS, $GG, $GC', lb_cmd_allowed('$SC 20') && lb_cmd_allowed('$SC 20 V') && lb_cmd_allowed('$FD') && lb_cmd_allowed('$FE') && lb_cmd_allowed('$GG') && lb_cmd_allowed('$GC'));
$ps = lb_pause_steps('rapi', true); $pr = lb_pause_steps('rapi', false);
check('2.9.1 pause = $FD (never $FS); resume = $FE', $ps == [['rapi','$FD',0]] && $pr == [['rapi','$FE',0]], lb_steps_text($ps) . ' / ' . lb_steps_text($pr));
$ok = true; foreach ([lb_pause_steps('rapi', true), lb_pause_steps('rapi', false)] as $st0) foreach ($st0 as $x) if (!lb_cmd_allowed($x[1])) $ok = false;
check('pause/resume commands pass the whitelist', $ok);
check('v5 pause = our claim with state disabled; resume = our claim without a state (POST replaces the claim)', lb_pause_steps('http', true, 6)[0][1] == ['state'=>'disabled', 'charge_current'=>6, 'max_current'=>6, 'auto_release'=>false] && !isset(lb_pause_steps('http', false, 20)[0][1]['state']), lb_steps_text(lb_pause_steps('http', true)));
echo "== idle pause condition\n";
$cu = function($id, $chg, $cap = null, $rating = 48){ return ['id'=>$id,'rating'=>$rating,'plugged'=>$chg,'plug_since'=>$chg ? $id : null,'prio'=>null,'setting'=>20,'cap'=>$cap,'charging'=>$chg]; };
check('2 cars at 34 (below 48) + 2 idle: pause wanted', lb_pause_wanted(80, [$cu(1,true),$cu(3,true),$cu(4,false),$cu(5,false)]) === true);
check('1 car: no pause (it already gets its 48)', lb_pause_wanted(80, [$cu(1,true),$cu(3,false),$cu(4,false),$cu(5,false)]) === false);
check('2 cars both capped below their share: no pause', lb_pause_wanted(80, [$cu(1,true,20),$cu(3,true,24),$cu(4,false),$cu(5,false)]) === false);
t('2 cars, 2 idle paused: cars get 40/40', 80, [$cu(1,true),$cu(3,true),$cu(4,false)+['lbpause'=>true],$cu(5,false)+['lbpause'=>true]], [1=>40,3=>40,4=>'P6',5=>'P6']);
$h = []; $h = lb_pause_arm($h, true, 0); $a0 = $h['armed']; $h = lb_pause_arm($h, true, 30); $a1 = $h['armed']; $h = lb_pause_arm($h, false, 40); $h = lb_pause_arm($h, false, 99); $a2 = $h['armed']; $h = lb_pause_arm($h, false, 100); $a3 = $h['armed'];
check('hysteresis: armed after 30 s, disarmed 60 s after the condition ends', !$a0 && $a1 && $a2 && !$a3);
echo "== simulation, 80 A, units 1,3,4,5 at 48 A (car ramps 1.5 A/s, follows a lower pilot in 3 s)\n";
$R = [1=>48, 3=>48, 4=>48, 5=>48];
// 1) cell-balancing taper: car A full until 15 min, tapers to 3 A by 25 min; car B wants 48 throughout
$A = function($t){ return $t < 900 ? 48 : ($t < 1500 ? max(3, 48 - 45 * ($t - 900) / 600) : 3); };
$B = function($t){ return 48; };
$s1 = lb_sim(80, $R, [1=>['plug'=>10, 'demand'=>$A], 3=>['plug'=>40, 'demand'=>$B]], 3600);
check('taper: car A capped, car B gets 48, never over 80 A', $s1['final_live'][3] == 48 && $s1['final_live'][1] <= 9 && $s1['over_s'] == 0 && $s1['max_live'] <= 80,
	sprintf('A cap %d A drawing %.1f, B %d A drawing %.1f; max draw %.1f, max live %d, cuts %d, writes %d', $s1['final_live'][1], $s1['final_draw'][1], $s1['final_live'][3], $s1['final_draw'][3], $s1['max_draw'], $s1['max_live'], $s1['cuts'], $s1['writes']));
// 2) sudden ramp-up after the cap: car A jumps back to 48 at 50 min
$A2 = function($t) use ($A){ return $t < 3000 ? $A($t) : 48; };
$s2 = lb_sim(80, $R, [1=>['plug'=>10, 'demand'=>$A2], 3=>['plug'=>40, 'demand'=>$B]], 3300);
$claw = $s2['claw_t'][0] ?? null;
$back = null; foreach ($s2['log'] as $l) if (preg_match('/^(\d+):(\d+):(\d+) set u1 \d+ -> \((\d+) A/', $l, $m) && ($m[1]*3600+$m[2]*60+$m[3]) > 3000 && $m[4] >= 30) { $back = $m[1]*3600+$m[2]*60+$m[3]; break; }
check('sudden ramp-up: claw-back, car A back to its share, never over 80 A', $claw !== null && $back !== null && $s2['over_s'] == 0 && $s2['max_live'] <= 80,
	sprintf('demand jump at 50:00; claw-back decided %+d s; A raised %+d s (B lowered first); A ends %d A, B %d A; max draw %.1f A', $claw - 3000, $back - 3000, $s2['final_live'][1], $s2['final_live'][3], $s2['max_draw']));
// 3) outage with all four plugged in and charging, headroom active (one car balancing), coordinator down 120 s
$C = function($t){ return 48; }; $Bal = function($t){ return $t < 600 ? 48 : 4; };
$s3 = lb_sim(80, $R, [1=>['plug'=>0, 'demand'=>$Bal], 3=>['plug'=>0, 'demand'=>$C], 4=>['plug'=>0, 'demand'=>$C], 5=>['plug'=>0, 'demand'=>$C]], 2400, ['outage'=>[1500, 120]]);
check('outage (accepted risk): units restart at 48 saved; once the coordinator is back the shares are re-applied before the cars ramp, total <= 80 A after', $s3['over_after_back'] == 0 && act($s3) <= 80,
	sprintf('seconds over 80 A while the coordinator was down: %d, after it was back: %d; max draw %.1f A; final %s', $s3['over_s'], $s3['over_after_back'], $s3['max_draw'], json_encode($s3['final_live'])));
// 4) outage with nothing plugged (all at 20 A), four cars plug in while the coordinator is still down
$s4 = lb_sim(80, $R, [1=>['plug'=>30, 'demand'=>$C], 3=>['plug'=>31, 'demand'=>$C], 4=>['plug'=>32, 'demand'=>$C], 5=>['plug'=>33, 'demand'=>$C]], 400, ['outage'=>[5, 240]]);
check('outage, 4 cars plug in while the coordinator is down: over 80 A until it is back (accepted risk), then <= 80 A', $s4['over_after_back'] == 0 && act($s4) <= 80, sprintf('seconds over while down %d, after back %d; max draw %.1f A', $s4['over_s'], $s4['over_after_back'], $s4['max_draw']));
// 5) second car plugs in while first charges at 48: lower first, then raise
$s5 = lb_sim(80, $R, [1=>['plug'=>0, 'demand'=>$C], 3=>['plug'=>300, 'demand'=>$C]], 600);
check('second car: A lowered before B raised, never over; idle units paused, cars 40/40', $s5['over_s'] == 0 && $s5['final_live'][1] == 40 && $s5['final_live'][3] == 40 && $s5['final_paused'] == [4,5] && order_ok($s5['w'], LB_HR['settle_sec']), sprintf('max draw %.1f A, end %s, paused %s', $s5['max_draw'], json_encode($s5['final_live']), json_encode($s5['final_paused'])));

// 6) schedules: unit 3 charging since 23:30; unit 1 (wake 00:00) and unit 5 (wake 00:05) asleep with cars plugged in; unit 4 idle (v5)
$day = 1800;   // sim t=0 is 23:30, midnight = 1800
$schedRun = function($w3r, $w2f) use ($R, $C){
	return lb_sim(80, $R, [3=>['plug'=>0, 'demand'=>$C], 1=>['plug'=>0, 'demand'=>$C], 5=>['plug'=>0, 'demand'=>$C]], 3600, ['sleep'=>[1=>$w3r, 5=>$w2f], 'measure_from'=>1]);   // all saved at 48; measured after the first decision
};
$s6 = $schedRun($day, $day + 300);
check('unit 1 wakes 00:00, unit 5 00:05: never over 80 A (live and draw), lowers before raises', $s6['over_s'] == 0 && $s6['max_live'] <= 80 && order_ok($s6['w'], LB_HR['settle_sec']) && act($s6) <= 80,
	sprintf('max draw %.1f A, max live %d A; end %s', $s6['max_draw'], $s6['max_live'], json_encode($s6['final_live'])));
$s7 = $schedRun($day, $day);
check('units 1 and 5 wake in the same second: never over 80 A, lowers before raises', $s7['over_s'] == 0 && $s7['max_live'] <= 80 && order_ok($s7['w'], LB_HR['settle_sec']) && act($s7) <= 80,
	sprintf('max draw %.1f A, max live %d A; end %s', $s7['max_draw'], $s7['max_live'], json_encode($s7['final_live'])));
$asleepIdle = true; foreach ($s6['w'] as [$t, $id, $f, $to]) if ($t < $day && ($id == 1 || $id == 5) && $to > 20) $asleepIdle = false;
check('before waking, sleeping units 1/5 (cars plugged) are held as idle (<= fair share)', $asleepIdle);

// 8) cars with lower on-board maximums: a 40 A car, a 32 A car and a ~28 A car (unit 4 = v5, no temporary raises)
echo "== cars that max out below their share (40 A, 32 A, ~28 A)\n";
$cap40 = function($t){ return 40; }; $cap32 = function($t){ return 32; }; $car28 = function($t){ return 27.8; };
$fin = function($s){ return json_encode($s['final_live']) . ' draw ' . json_encode($s['final_draw']); };
$a1 = lb_sim(80, $R, [5=>['plug'=>0, 'demand'=>$car28]], 900);
check('28 A car alone (27.8 A): capped at 32 A, idle units up to their fair share, <= 80 A', $a1['final_live'][5] == 32 && $a1['over_s'] == 0 && $a1['max_live'] <= 80 && max($a1['final_live'][1], $a1['final_live'][3], $a1['final_live'][4]) <= 20, $fin($a1));
$a2 = lb_sim(80, $R, [1=>['plug'=>0, 'demand'=>$cap40]], 900);
check('40 A car alone: capped at 46 A (frees 2 A), <= 80 A', $a2['final_live'][1] == 46 && $a2['over_s'] == 0 && $a2['max_live'] <= 80, $fin($a2));
$a3 = lb_sim(80, $R, [1=>['plug'=>0, 'demand'=>$cap40], 5=>['plug'=>0, 'demand'=>$car28]], 900);
check('40 A car + 28 A car: idle units paused, 28 A car capped at 32, 40 A car gets 46, <= 80 A', $a3['final_live'][5] == 32 && $a3['final_live'][1] >= 44 && $a3['over_s'] == 0 && $a3['max_live'] <= 80, $fin($a3) . ' paused ' . json_encode($a3['final_paused']));
$a4 = lb_sim(80, $R, [1=>['plug'=>0, 'demand'=>$cap40], 3=>['plug'=>0, 'demand'=>$cap32], 5=>['plug'=>0, 'demand'=>$car28]], 900);
check('all three: unit 4 paused, shares 26/27/27 below every car\'s maximum, no caps needed, <= 80 A', $a4['cuts'] == 0 && $a4['over_s'] == 0 && $a4['max_live'] <= 80, $fin($a4));
$a5 = lb_sim(80, $R, [5=>['plug'=>0, 'demand'=>$car28], 3=>['plug'=>600, 'demand'=>$cap32], 1=>['plug'=>1200, 'demand'=>$cap40, 'unplug'=>2400]], 3000);
check('staggered (28 A car, +32 A car at 10 min, +40 A car at 20 min, 40 A car leaves at 40 min): <= 80 A throughout', $a5['over_s'] == 0 && $a5['max_live'] <= 80, sprintf('cuts %d, claw-backs %d, max draw %.1f A; end ', $a5['cuts'], $a5['claws'], $a5['max_draw']) . $fin($a5));
echo "== idle pause (sim)\n";
// two cars at 48 A demand; unit 4 idle; at 15 min a car plugs into the paused unit 4; it leaves at 25 min
$p1 = lb_sim(80, $R, [1=>['plug'=>0, 'demand'=>$C], 3=>['plug'=>0, 'demand'=>$C], 4=>['plug'=>900, 'demand'=>$C, 'unplug'=>1500]], 2400);
$rs = $p1['resume_t'][0] ?? null;
check('pause idle units with 2 cars held at 34 A: cars go to 40/40, never over 80 A, paused units draw nothing', $p1['pauses'] >= 2 && $p1['over_s'] == 0 && $p1['max_live'] <= 80 && $p1['paused_draw'] == 0.0, sprintf('pauses %d, resumes %d, max draw %.1f A, end %s paused %s', $p1['pauses'], $p1['resumes'], $p1['max_draw'], json_encode($p1['final_live']), json_encode($p1['final_paused'])));
check('car plugs into a paused unit: resumed within 10 s, others lowered first', $rs !== null && $rs - 900 <= 10 && order_ok($p1['w'], LB_HR['settle_sec']), 'resumed after ' . ($rs !== null ? $rs - 900 : '-') . ' s');
$ok = true; foreach ($p1['cmds'] as $c0) if (!lb_cmd_allowed($c0)) $ok = false;
check('every command in the pause run passes the whitelist; no $FS/$ST', $ok && !preg_grep('/^\$(FS|ST)/', $p1['cmds']), implode(' ', array_unique($p1['cmds'])));
$p2 = lb_sim(80, $R, [1=>['plug'=>0, 'demand'=>$C], 3=>['plug'=>0, 'demand'=>$C, 'unplug'=>1200]], 2000);
check('one car leaves: condition ends, idle units resumed (after 60 s), car alone gets 48', $p2['final_paused'] == [] && $p2['final_live'][1] == 48 && $p2['over_s'] == 0, sprintf('resumes at %s; end %s', json_encode($p2['resume_t']), json_encode($p2['final_live'])));
$p3 = lb_sim(80, $R, [1=>['plug'=>0, 'demand'=>$C], 3=>['plug'=>0, 'demand'=>$C]], 1200, ['sleep'=>[5=>99999]]);
check('a unit asleep on its schedule is never paused', !in_array(5, $p3['final_paused']) && in_array(4, $p3['final_paused']), 'paused ' . json_encode($p3['final_paused']));
$p4 = lb_sim(80, $R, [1=>['plug'=>0, 'demand'=>$C], 3=>['plug'=>0, 'demand'=>$C]], 600, ['saved'=>[1=>34, 3=>27, 4=>6, 5=>6], 'start'=>[1=>34, 3=>27, 4=>6, 5=>6]]);
check('from example saved shares to full saved: never over 80 A, saved end at 48', $p4['over_s'] == 0 && $p4['max_live'] <= 80 && $p4['final_saved'] == [1=>48, 3=>48, 4=>48, 5=>48], 'saved ' . json_encode($p4['final_saved']) . ' live ' . json_encode($p4['final_live']));
echo "== monitored units (not in a load group): reads only\n";
$mu = ['id'=>2, 'monitor'=>true]; $gu = ['id'=>1];
$bad = array_filter(['$SC 12', '$SC 12 V', '$FD', '$FE', '$FS', '$ST 0 0 8 30', '$FR'], function($c) use ($mu){ return lb_cmd_allowed_for($mu, $c); });
check('monitored unit: every write refused ($SC, $SC V, $FD, $FE, ...)', !$bad && !lb_write_allowed($mu), json_encode(array_values($bad)));
check('monitored unit: reads allowed ($GE $GS $GG $GC)', lb_cmd_allowed_for($mu, '$GE') && lb_cmd_allowed_for($mu, '$GS') && lb_cmd_allowed_for($mu, '$GG') && lb_cmd_allowed_for($mu, '$GC'));
check('grouped unit: writes still allowed ($SC 20 V, $FD)', lb_write_allowed($gu) && lb_cmd_allowed_for($gu, '$SC 20 V') && lb_cmd_allowed_for($gu, '$FD'));
$src = file_get_contents(__DIR__ . '/coordinator.php');
check('coordinator: claim / release / saved-current only inside the write guards', substr_count($src, 'evse_claim(') == 1 && substr_count($src, 'evse_release_claim(') == 1 && substr_count($src, 'evse_set_current(') == 1 && substr_count($src, 'lb_rapi_send(') >= 2 && !preg_match('/\brapi\(\$u(nit)?, *.\$(SC|FD|FE|FS)/', $src));
echo "== sleep-free (asleep on its schedule: holds 6 A, not counted, while MQTT is up; max 10 % of the limit)\n";
$sfu = function($id, $chg, $sf = false){ return ['id'=>$id,'rating'=>48,'plugged'=>$chg,'plug_since'=>$chg ? $id : null,'prio'=>null,'setting'=>20,'cap'=>null,'charging'=>$chg,'sleepfree'=>$sf]; };
t('car A + car B (heater) capped 28, unit 5 asleep free, unit 4 paused: car A 48 (was 46)', 80, [$sfu(1,true), array_merge($sfu(3,true), ['cap'=>28]), $sfu(4,false)+['lbpause'=>true], $sfu(5,false,true)], [1=>48,3=>28,4=>'P6',5=>6]);
t('2 cars, unit 5 asleep free, unit 4 idle: 37/37, unit 4 6, unit 5 6 uncounted', 80, [$sfu(1,true), $sfu(3,true), $sfu(4,false), $sfu(5,false,true)], [1=>37,3=>37,4=>6,5=>6]);
check('sleep-free only with MQTT up; 80 A: one unit; 40 A: none', lb_sleep_free([5,1], 80, true) == [5] && lb_sleep_free([5], 80, false) == [] && lb_sleep_free([5], 40, true) == [] && lb_sleep_free([5,1,3], 120, true) == [5,1]);
// units 1 + 3 charging; unit 5 asleep with a car, wakes at 10 min
$sfr = function($delay) use ($R, $C){ return lb_sim(80, $R, [1=>['plug'=>0, 'demand'=>$C], 3=>['plug'=>0, 'demand'=>$C], 5=>['plug'=>0, 'demand'=>$C]], 1200, ['sleep'=>[5=>600], 'sleep_free'=>true, 'wake_delay'=>$delay, 'measure_from'=>1]); };
$z1 = $sfr(5);
check('unit 5 wakes (car starts 5 s later, as measured on one EV): draw never over 80 A, lowers before raises, ends <= 80', $z1['over_s'] == 0 && order_ok($z1['w'], LB_HR['settle_sec']) && act($z1) <= 80,
	sprintf('max draw %.1f A; awake settings over 80 for %d s (max %d A); end %s paused %s', $z1['max_draw'], $z1['over_live_awake_s'] ?? 0, $z1['max_live_awake'], json_encode($z1['final_live']), json_encode($z1['final_paused'])));
$z0 = $sfr(0);
check('worst case (car draws the second its unit wakes): over at most 6 A for a few seconds',  $z0['max_draw'] <= 86 && $z0['over_s'] <= 6 && act($z0) <= 80,
	sprintf('seconds over 80 A: %d, max draw %.1f A', $z0['over_s'], $z0['max_draw']));
$zb = lb_sim(80, $R, [1=>['plug'=>0, 'demand'=>$C], 3=>['plug'=>0, 'demand'=>$C]], 900, ['sleep'=>[5=>99999], 'sleep_free'=>true]);
check('2 cars, unit 5 asleep (free), unit 4 paused: cars get 40/40 (was 37/37)', $zb['final_live'][1] + $zb['final_live'][3] == 80 && $zb['over_s'] == 0, json_encode($zb['final_live']) . ' paused ' . json_encode($zb['final_paused']));
if ($V) { echo "\n-- simulated dry-run log (taper + sudden ramp-up):\n"; foreach ($s2['log'] as $l) echo "  $l\n"; echo "\n-- outage:\n"; foreach ($s3['log'] as $l) echo "  $l\n"; echo "\n-- schedule wake 00:00 / 00:05 (sim 00:30:00 = midnight):\n"; foreach ($s6['log'] as $l) echo "  $l\n"; echo "\n-- both wake in the same second:\n"; foreach ($s7['log'] as $l) echo "  $l\n"; echo "\n-- staggered 40/32/28:\n"; foreach ($a5['log'] as $l) echo "  $l\n"; }
echo $fail ? "$fail FAILED\n" : "all passed\n";
exit($fail ? 1 : 0);
