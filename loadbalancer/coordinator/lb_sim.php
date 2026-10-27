<?php
/******************************************************************************
 * Second-by-second simulation of units + cars + the coordinator's decisions
 * (same lb_plan / lb_write_order / lb_hr_step / lb_pause_* as the live coordinator).
 * Used by tests.php only; touches nothing.
 * Model: saved = full rating (opt 'saved' to start from other values); all shares are
 * temporary; a 'full' write saves the rating and re-applies the share at once.
 * Car: draws min(own demand, active value); ramps UP at ramp_aps (default 1.5 A/s);
 * follows a LOWER value after lag_s (default 3 s; J1772 allows 5 s).
 * Coordinator: full read every 20 s and 2 s after a state change (MQTT), amps every 5 s
 * for charging units; lowers first, raises 5 s after the last lower, max every 30 s per unit.
 * Outage: every controller restarts from its SAVED value (full rating), cars stop ~10 s;
 * the coordinator re-applies the shares when it is back (accepted risk).
 * $opt['sleep'] = [id => wake second]: asleep on its schedule until then (never paused).
 * $opt['sleep_free'] = true: MQTT up, sleep-free on (lb_sleep_free). $opt['wake_delay'] = s a car
 * waits after its unit wakes before drawing (default 0 = worst case; one EV measured ~5 s, 6 A at ~9 s).
 ******************************************************************************/
function lb_sim($limit, array $ratings, array $cars, $T, array $opt = []){
	$H = LB_HR; $ramp = $opt['ramp_aps'] ?? 1.5; $lag = $opt['lag_s'] ?? 3; $poll = 20;
	$live = []; $saved = []; $draw = []; $overSince = []; $paused = [];
	foreach ($ratings as $id => $r) { $saved[$id] = $opt['saved'][$id] ?? $r; $live[$id] = $opt['start'][$id] ?? min(20, $saved[$id]); $draw[$id] = 0.0; $paused[$id] = false; }
	$hr = []; $plugSince = []; $lastUp = []; $lastDown = -999; $nextPoll = 0; $nextFast = 0; $dirty = null; $pending = false; $arm = [];
	$st = ['max_draw'=>0, 'max_live'=>0, 'over_s'=>0, 'over_after_back'=>0, 'log'=>[], 'cuts'=>0, 'claws'=>0, 'writes'=>0, 'w'=>[], 'pauses'=>0, 'resumes'=>0, 'pause_t'=>[], 'resume_t'=>[], 'paused_draw'=>0.0, 'cmds'=>[]];
	$down = false; $prevCharging = []; $prevAwake = []; $prevPlug = []; $awake = []; $plugged = []; $charging = [];
	$log = function($t, $m) use (&$st){ $st['log'][] = sprintf('%02d:%02d:%02d %s', intdiv($t, 3600), intdiv($t % 3600, 60), $t % 60, $m); };
	for ($t = 0; $t <= $T; $t++) {
		if (isset($opt['outage']) && $t == $opt['outage'][0]) {
			foreach ($live as $id => $v) { $live[$id] = $saved[$id]; $draw[$id] = 0.0; $paused[$id] = false; }
			$down = $t + $opt['outage'][1]; $log($t, 'POWER OUTAGE: units restart at saved ' . json_encode($saved) . '; coordinator down ' . $opt['outage'][1] . ' s');
		}
		$coordUp = ($down === false || $t >= $down);
		if ($down !== false && $t == $down) { $log($t, 'coordinator back'); $nextPoll = $t; }
		foreach ($ratings as $id => $r) {
			$c = $cars[$id] ?? null;
			$pl = $c && $t >= $c['plug'] && $t < ($c['unplug'] ?? PHP_INT_MAX);
			$restart = isset($opt['outage']) && $t >= $opt['outage'][0] && $t < $opt['outage'][0] + 10;
			$asleep = isset($opt['sleep'][$id]) && $t < $opt['sleep'][$id];
			$awake[$id] = !$asleep;
			$wd = isset($opt['sleep'][$id]) && $t < $opt['sleep'][$id] + ($opt['wake_delay'] ?? 0);
			$dem = ($pl && !$restart && !$asleep && !$wd && !$paused[$id]) ? (float)($c['demand'])($t) : 0.0;
			$targetDraw = min($dem, $live[$id]);
			if ($draw[$id] < $targetDraw) $draw[$id] = min($targetDraw, $draw[$id] + $ramp);
			elseif ($draw[$id] > $targetDraw) {
				if ($dem < $draw[$id]) $draw[$id] = $dem;
				else { $overSince[$id] = $overSince[$id] ?? $t; if ($t - $overSince[$id] >= $lag) { $draw[$id] = $targetDraw; unset($overSince[$id]); } }
			} else unset($overSince[$id]);
			if ($paused[$id]) $st['paused_draw'] = max($st['paused_draw'], $draw[$id]);
			$charging[$id] = $pl && $draw[$id] > 0.05;
			$plugged[$id] = $pl;
			if ($coordUp && !empty($opt['sleep_free']) && ($prevAwake[$id] ?? true) === false && $awake[$id]) $dirty = $t;   // sleep-free unit woke: MQTT state, no debounce
			if ($coordUp && (($prevCharging[$id] ?? false) !== $charging[$id] || ($prevPlug[$id] ?? false) !== $pl || ($prevAwake[$id] ?? true) !== $awake[$id])) $dirty = $dirty ?? $t + 2;
			$prevCharging[$id] = $charging[$id]; $prevPlug[$id] = $pl; $prevAwake[$id] = $awake[$id];
		}
		$sd = array_sum($draw); $sl = 0; $sla = 0; foreach ($live as $id => $v) if (!$paused[$id]) { $sl += $v; if ($awake[$id]) $sla += $v; }
		if ($t >= ($opt['measure_from'] ?? 0)) { $st['max_draw'] = max($st['max_draw'], $sd); $st['max_live'] = max($st['max_live'], $sl); $st['max_live_awake'] = max($st['max_live_awake'] ?? 0, $sla); if ($sla > $limit) $st['over_live_awake_s'] = ($st['over_live_awake_s'] ?? 0) + 1; }
		if ($sd > $limit + 0.01) { $st['over_s']++; if ($down !== false && $t >= $down + 10) $st['over_after_back']++; }
		if (!$coordUp) continue;
		$replan = false;
		if ($t >= $nextFast) {
			$nextFast = $t + $H['fast_sec'];
			foreach ($ratings as $id => $r) {
				if (!$charging[$id] && empty($hr[$id]['cap']) && empty($hr[$id]['charge_start'])) continue;
				[$hr[$id], $ev] = lb_hr_step($hr[$id] ?? [], ['charging'=>$charging[$id], 'amps'=>round($draw[$id], 1), 'alloc'=>$live[$id]], $t);
				if ($ev) { $log($t, lb_hr_event_text($id, '', $ev)); $replan = true; if ($ev[0] == 'cut') $st['cuts']++; if ($ev[0] == 'claw') { $st['claws']++; $st['claw_t'][] = $t; } }
			}
		}
		if ($t >= $nextPoll || ($dirty !== null && $t >= $dirty)) { $replan = true; $nextPoll = $t + $poll; $dirty = null;
			foreach ($ratings as $id => $r) { if ($plugged[$id] && !isset($plugSince[$id])) $plugSince[$id] = $t; if (!$plugged[$id]) unset($plugSince[$id]); }
		}
		if (!$replan && !$pending) continue;
		$in = []; $pausable = [];
		foreach ($ratings as $id => $r) {
			$pausable[$id] = !$plugged[$id] && $awake[$id];
			$in[] = ['id'=>$id, 'rating'=>$r, 'plugged'=>lb_effective_plugged($plugged[$id], $paused[$id] ? 255 : ($awake[$id] ? 1 : 254), $paused[$id]), 'plug_since'=>$plugSince[$id] ?? null, 'prio'=>null, 'setting'=>$live[$id], 'cap'=>$hr[$id]['cap'] ?? null, 'charging'=>$charging[$id]];
		}
		$sf = lb_sleep_free(array_keys(array_filter($awake, function($a){ return !$a; })), $limit, !empty($opt['sleep_free']));
		foreach ($in as $k => $u) $in[$k]['sleepfree'] = in_array($u['id'], $sf, true);
		$arm = lb_pause_arm($arm, empty($opt['no_pause']) && lb_pause_wanted($limit, $in), $t);
		foreach ($in as $k => $u) $in[$k]['lbpause'] = $arm['armed'] && $pausable[$u['id']] && $paused[$u['id']];
		$p = lb_plan($limit, $in);
		$w = lb_write_order($p, $live, $saved, $ratings, $charging);
		$pending = false; $didDown = false;
		foreach ($w as [$id, $from, $to, $kind]) {
			$raise = ($kind === 'full') || $to > $from;
			if (!$raise) { $live[$id] = $to; $lastDown = $t; $didDown = true; $st['writes']++; $st['w'][] = [$t, $id, $from, $to, $kind]; $st['cmds'][] = '$SC ' . $to . ' V'; $log($t, "set u$id $from -> ($to A)"); continue; }
			if ($didDown || $t - $lastDown < $H['settle_sec'] || ($kind !== 'full' && $t - ($lastUp[$id] ?? -999) < $H['up_every'])) { $pending = true; continue; }
			if ($kind === 'full') { $saved[$id] = $ratings[$id]; $st['cmds'][] = '$SC ' . $ratings[$id]; }
			$live[$id] = $to; $lastUp[$id] = $t; $st['writes']++; $st['w'][] = [$t, $id, $from, $to, $kind]; $st['cmds'][] = '$SC ' . $to . ' V';
			if (isset($hr[$id])) $hr[$id]['last_raise'] = $t;
			$log($t, "set u$id $from -> ($to A)" . ($kind === 'full' ? ", saved {$ratings[$id]} A" : ''));
		}
		// pause / resume (after the writes; resume only once lowers have settled)
		foreach ($ratings as $id => $r) {
			$wantP = $arm['armed'] && $pausable[$id];
			if ($wantP && !$paused[$id]) { $paused[$id] = true; $st['pauses']++; $st['pause_t'][] = $t; $st['cmds'][] = '$FD'; $log($t, "pause u$id (idle)"); $pending = true; }
			elseif (!$wantP && $paused[$id]) {
				if ($pending || $didDown || $t - $lastDown < $H['settle_sec'] || $live[$id] > ($p['units'][$id]['target'] ?? 0)) { $pending = true; continue; }
				$paused[$id] = false; $st['resumes']++; $st['resume_t'][] = $t; $st['cmds'][] = '$FE'; $log($t, "resume u$id" . ($plugged[$id] ? ' (car plugged in)' : ''));
			}
		}
		if ($pending) $dirty = $t + 1;
	}
	$st['final_live'] = $live; $st['final_saved'] = $saved; $st['final_paused'] = array_keys(array_filter($paused)); $st['final_draw'] = array_map(function($x){ return round($x, 1); }, $draw); $st['hr'] = $hr;
	return $st;
}
