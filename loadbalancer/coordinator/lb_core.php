<?php
/******************************************************************************
 * Load-balance split + headroom + idle pause (pure functions, no I/O - tested in tests.php).
 *
 * OpenEVSE as designed: every unit's SAVED (EEPROM) current is the user's max
 * (unit page, never above Max current in Settings) and is never written by the
 * balancer; a unit's rating = min(saved, Max current, hw max); all balancing is TEMPORARY: WiFi 2.x / RAPI '$SC N V'
 * (the controller won't take a temporary value above the saved one, $GC shows
 * min / hw max / active / saved), v5 a claim (charge_current = max_current = N).
 * Accepted risk: a controller that restarts (power cut) comes back at its saved
 * full rating until the coordinator re-applies its share (seconds, on MQTT
 * "connected" or the next read).
 *
 * Split:
 *   - units with a car plugged in share the limit equally (water-filled: capped at
 *     each unit's rating or headroom cap, never below 6 A, the J1772 minimum);
 *   - units with nothing plugged in get what is left, up to the fair share
 *     (limit / units in the group), never below 6 A;
 *   - a unit we can't read keeps whatever it had (counted as is);
 *   - more units than fit at 6 A (80 / 6 = 13): lowest priority cars pause, warning.
 * Headroom: a car drawing clearly less than its share gets a cap = draw + margin;
 *   the freed amps go to the other cars (lb_hr_step), lifted when it pushes back.
 * Idle pause: when 2+ cars are charging and at least one is held below what it
 *   can take (rating or cap), idle units that are awake with nothing plugged in
 *   are paused (2.x '$FD', v5 claim disabled) and their 6 A go to the cars.
 *   Resume as soon as a car plugs into one, or when the condition ends.
 *   Units asleep on their schedule are never paused (left alone).
 * Schedules: a unit asleep on its schedule (or disabled by someone else) counts as
 * IDLE even with a car plugged in (lb_effective_plugged); when it wakes, MQTT
 * reports the state change and it gets its share (others lowered first).
 * Sleep-free: while MQTT is up, a unit asleep on its schedule
 * holds 6 A (the J1772 minimum; we never touch its sleep) but is NOT counted, so
 * the cars can use those amps. At most 10 % of the limit is lent this way
 * (80 A: one unit, 6 A). When it wakes, MQTT reports it in ~1-3 s, the others are
 * lowered first and it stays at 6 A until they have settled; a car takes 5-10 s
 * to start drawing after a wake (measured on one EV: 9 s to 6 A), so the draw stays
 * under the limit. Accepted risk: coordinator down + that unit wakes = up to 6 A over.
 * The coordinator never sends schedule or sleep/wake commands ($ST, $FS, ...);
 * see lb_cmd_allowed().
 *
 * Input unit: ['id','rating','plugged'=>true|false|null,'plug_since','prio','setting','cap'=>null|A,
 *              'charging'=>bool, 'lbpause'=>bool (paused by us now, counts 0 A)]
 * Returns ['units'=>[id=>['target','saved','temp','pause','lbpause','why']], 'total', 'saved_total', 'warn']
 ******************************************************************************/
const LB_MIN_AMPS = 6;
// headroom tuning (seconds / amps)
const LB_HR = [
	'grace'        => 180,   // no cuts in the first 3 min after charging starts or after the unit was raised
	'below_a'      => 4,     // cut when drawing at least 4 A under its allocation ...
	'below_for'    => 120,   // ... for 2 min
	'margin_a'     => 4,     // cap = draw + max(4 A, 15 %), at least 6 A
	'margin_pct'   => 0.15,
	'claw_a'       => 2,     // lift the cap when drawing within 2 A of it ...
	'claw_for'     => 15,    // ... for 15 s
	'down_every'   => 300,   // at most one cut per unit every 5 min
	'up_every'     => 30,    // at most one raise per unit every 30 s
	'ignore_a'     => 2,     // ignore headroom changes under 2 A
	'fast_sec'     => 5,     // amps read every 5 s, charging units only
	'settle_sec'   => 5,     // after lowering a unit, wait this long (J1772: car must follow a lower pilot within 5 s) before raising another
];

// water-fill $budget over items [id => max] (ascending max first), each at least $min. Returns [id => amps].
function lb_waterfill(array $max, $budget, $min){
	asort($max); $out = []; $left = $budget; $n = count($max); $i = 0;
	foreach ($max as $id => $m) {
		$share = intdiv(max(0, $left), $n - $i);
		$out[$id] = max($min, min($m, $share));
		$left -= $out[$id]; $i++;
	}
	return $out;
}

// one split; $useCaps = apply headroom caps
function lb_split($limit, array $units, $useCaps){
	$plan = []; $warn = [];
	$fixed = 0; $idle = []; $want = [];
	$fair = max(LB_MIN_AMPS, intdiv($limit, max(1, count($units))));
	foreach ($units as $u) {
		$rating = max(LB_MIN_AMPS, (int)$u['rating']);
		if (!empty($u['sleepfree'])) {   // asleep on its schedule, MQTT up: holds 6 A, not counted (see header)
			$plan[$u['id']] = ['target'=>LB_MIN_AMPS, 'pause'=>false, 'sleepfree'=>true, 'why'=>'asleep: ' . LB_MIN_AMPS . ' A, not counted'];
			continue;
		}
		if (!empty($u['lbpause'])) {   // paused by us while idle: draws nothing, holds 6 A for when it resumes
			$plan[$u['id']] = ['target'=>LB_MIN_AMPS, 'pause'=>true, 'lbpause'=>true, 'why'=>'paused (idle)'];
			continue;
		}
		if ($u['plugged'] === null) {   // not readable: keep its saved value (or assume its rating)
			$keep = ($u['setting'] !== null) ? (int)$u['setting'] : $rating;
			$fixed += $keep;
			$plan[$u['id']] = ['target'=>null, 'pause'=>false, 'why'=>'no reply: counted at ' . $keep . ' A, not changed'];
		} elseif (!$u['plugged']) {
			$idle[] = $u + ['rating_c'=>$rating];
		} else {
			$cap = ($useCaps && isset($u['cap']) && $u['cap'] !== null) ? max(LB_MIN_AMPS, min($rating, (int)$u['cap'])) : null;
			$w = $cap ?? $rating;
			if ($useCaps && isset($u['live_max'])) $w = max(LB_MIN_AMPS, min($w, (int)$u['live_max']));
			$want[] = $u + ['rating_c'=>$rating, 'want'=>$w, 'capped'=>$cap !== null];
		}
	}
	usort($want, function($a, $b){
		$pa = $a['prio'] ?? PHP_INT_MAX; $pb = $b['prio'] ?? PHP_INT_MAX;
		if ($pa != $pb) return $pa <=> $pb;
		$sa = $a['plug_since'] ?? PHP_INT_MAX; $sb = $b['plug_since'] ?? PHP_INT_MAX;
		if ($sa != $sb) return $sa <=> $sb;
		return $a['id'] <=> $b['id'];
	});
	$active = $want; $paused = []; $overflow = false;
	if ($fixed + LB_MIN_AMPS * (count($idle) + count($want)) <= $limit) {
		$budget = $limit - $fixed - LB_MIN_AMPS * count($idle);   // idle units' 6 A minimum is reserved first
	} else {
		$overflow = true;
		$warn[] = 'more cars than the limit allows at ' . LB_MIN_AMPS . ' A each: the last ones pause';
		$k = max(0, min(count($want), intdiv($limit - $fixed, LB_MIN_AMPS)));
		while (count($active) > $k) $paused[] = array_pop($active);
		$budget = max(LB_MIN_AMPS * count($active), $limit - $fixed - LB_MIN_AMPS * (count($idle) + count($paused)));
	}
	foreach ($paused as $u) $plan[$u['id']] = ['target'=>LB_MIN_AMPS, 'pause'=>true, 'why'=>'paused: no room at ' . LB_MIN_AMPS . ' A'];
	// plugged-in cars first: equal share, water-filled up to rating (or headroom cap)
	$mx = []; foreach ($active as $u) $mx[$u['id']] = $u['want'];
	$got = lb_waterfill($mx, $budget, LB_MIN_AMPS);
	$left = $budget - array_sum($got);
	foreach ($active as $u) {
		$t = $got[$u['id']];
		$why = 'car: ' . ($u['capped'] && $t == $u['want'] ? 'capped ' . $t . ' A' : ($t == $u['rating_c'] ? 'saved ' . $t . ' A' : 'share ' . $t . ' A of ' . $limit . ' A'));
		$plan[$u['id']] = ['target'=>$t, 'pause'=>false, 'why'=>$why];
	}
	// idle units: 6 A plus what is left, up to the fair share
	$ix = []; foreach ($idle as $u) $ix[$u['id']] = max(0, min($fair, $u['rating_c'], ($useCaps && isset($u['live_max'])) ? (int)$u['live_max'] : PHP_INT_MAX) - LB_MIN_AMPS);
	$extra = $overflow ? array_fill_keys(array_keys($ix), 0) : lb_waterfill($ix, max(0, $left), 0);
	foreach ($idle as $u) {
		$t = LB_MIN_AMPS + $extra[$u['id']];
		$plan[$u['id']] = ['target'=>$t, 'pause'=>false, 'why'=>'no car: share ' . $t . ' A'];
	}
	$total = $fixed; foreach ($plan as $p) if ($p['target'] !== null && empty($p['lbpause']) && empty($p['sleepfree'])) $total += $p['target'];
	if ($total > $limit && !$warn) $warn[] = "total $total A is over the $limit A limit";
	return ['units'=>$plan, 'total'=>$total, 'fixed'=>$fixed, 'warn'=>$warn];
}

function lb_plan($limit, array $units){
	$sp = lb_split($limit, $units, true);
	$plan = []; $saved = 0;
	$rt = []; foreach ($units as $u) $rt[$u['id']] = max(LB_MIN_AMPS, (int)$u['rating']);
	foreach ($sp['units'] as $id => $x) {
		$x += ['lbpause'=>false, 'sleepfree'=>false];
		$x['saved'] = $rt[$id];                                          // the unit's cap (rating, which includes its saved current)
		$x['temp'] = ($x['target'] !== null && $x['target'] < $rt[$id]); // active value below the saved one
		$saved += $rt[$id];
		$plan[$id] = $x;
	}
	ksort($plan);
	return ['units'=>$plan, 'total'=>$sp['total'], 'saved_total'=>$saved, 'warn'=>$sp['warn']];
}

// Sleep-free: which units asleep on their schedule may go uncounted. $cands = ids in order;
// only with fast wake detection (MQTT up), at most 10 % of the limit (80 A: 1 unit).
const LB_SLEEP_FREE_PCT = 0.10;
function lb_sleep_free(array $cands, $limit, $fastDetect){
	if (!$fastDetect) return [];
	return array_slice(array_values($cands), 0, intdiv((int)floor($limit * LB_SLEEP_FREE_PCT), LB_MIN_AMPS));
}

// Idle pause wanted? 2+ cars charging and at least one held below what it can take
// (its rating, or its headroom cap). Judged on the split WITHOUT any idle pause, so
// pausing can't switch its own condition off (no flapping).
function lb_pause_wanted($limit, array $units){
	$u0 = array_map(function($u){ $u['lbpause'] = false; return $u; }, $units);
	$s = lb_split($limit, $u0, true); $n = 0; $held = false;
	foreach ($u0 as $u) {
		if (empty($u['charging']) || empty($u['plugged'])) continue;
		$n++;
		$t = $s['units'][$u['id']]['target'] ?? null;
		$w = max(LB_MIN_AMPS, min((int)$u['rating'], isset($u['cap']) && $u['cap'] !== null ? (int)$u['cap'] : PHP_INT_MAX));
		if ($t !== null && $t < $w) $held = true;
	}
	return $n >= 2 && $held;
}
// hysteresis: armed after the condition held 30 s, disarmed after it is gone for 60 s
const LB_PAUSE = ['arm_after'=>30, 'disarm_after'=>60];
function lb_pause_arm(array $h, $want, $now){
	$h += ['armed'=>false, 'since'=>null];
	if ($want === $h['armed']) { $h['since'] = null; return $h; }
	if ($h['since'] === null) $h['since'] = $now;
	if ($now - $h['since'] >= ($want ? LB_PAUSE['arm_after'] : LB_PAUSE['disarm_after'])) { $h['armed'] = $want; $h['since'] = null; }
	return $h;
}

// Writes to reach the plan: lower values first, then raises. All shares are temporary.
// $live = active value per unit, $saved = saved value (null = unknown), $rating, $charging.
// The saved value is the user's (unit page) and is never written here: $rating already
// includes it (lb_rating), so every share is at or below it. $saved/$charging are unused.
// Returns [[id, from, to, 'temp'], ...].
function lb_write_order(array $plan, array $live, array $saved = [], array $rating = [], array $charging = []){
	$down = []; $up = [];
	foreach ($plan['units'] as $id => $p) {
		if ($p['target'] === null || !array_key_exists($id, $live) || $live[$id] === null) continue;
		$cur = (int)$live[$id]; $t = (int)$p['target'];
		if ($t < $cur) $down[] = [$id, $cur, $t, 'temp'];
		elseif ($t > $cur) $up[] = [$id, $cur, $t, 'temp'];
	}
	return array_merge($down, $up);
}

/* Headroom state machine for ONE plugged-in unit, called on every amps reading (every 5 s while charging).
 * $h: ['cap','charge_start','last_raise','last_cut','last_up','below_since','near_since']
 * $in: ['charging'=>bool, 'amps'=>float|null, 'alloc'=>int|null (current live allocation)]
 * Returns [$h, $event] with $event null or ['cut', from, to, amps] / ['claw', cap, amps] / ['end', cap]. */
function lb_hr_step(array $h, array $in, $now){
	$H = LB_HR;
	$h += ['cap'=>null, 'charge_start'=>null, 'last_raise'=>null, 'last_cut'=>null, 'last_up'=>null, 'below_since'=>null, 'near_since'=>null];
	if (empty($in['charging'])) {   // stopped/unplugged: forget the cap
		$ev = $h['cap'] !== null ? ['end', $h['cap']] : null;
		return [['cap'=>null, 'charge_start'=>null, 'last_raise'=>$h['last_raise'], 'last_cut'=>$h['last_cut'], 'last_up'=>$h['last_up'], 'below_since'=>null, 'near_since'=>null], $ev];
	}
	if ($h['charge_start'] === null) $h['charge_start'] = $now;
	if ($in['amps'] === null || $in['alloc'] === null) return [$h, null];
	$a = (float)$in['amps'];
	// claw back: pushing against its cap
	if ($h['cap'] !== null) {
		if ($a >= $h['cap'] - $H['claw_a']) {
			if ($h['near_since'] === null) $h['near_since'] = $now;
			if ($now - $h['near_since'] >= $H['claw_for'] && ($h['last_up'] === null || $now - $h['last_up'] >= $H['up_every'])) {
				$ev = ['claw', $h['cap'], $a];
				$h['cap'] = null; $h['near_since'] = null; $h['below_since'] = null; $h['last_up'] = $now; $h['last_raise'] = $now;
				return [$h, $ev];
			}
		} else $h['near_since'] = null;
	}
	// cut: well under its allocation for long enough (also further steps of a taper)
	$ref = ($h['cap'] !== null) ? min($h['cap'], (int)$in['alloc']) : (int)$in['alloc'];
	if ($a <= $ref - $H['below_a']) { if ($h['below_since'] === null) $h['below_since'] = $now; }
	else $h['below_since'] = null;
	$grace = ($now - $h['charge_start'] < $H['grace']) || ($h['last_raise'] !== null && $now - $h['last_raise'] < $H['grace']);
	if (!$grace && $h['below_since'] !== null && $now - $h['below_since'] >= $H['below_for'] && ($h['last_cut'] === null || $now - $h['last_cut'] >= $H['down_every'])) {
		$new = max(LB_MIN_AMPS, (int)ceil($a + max($H['margin_a'], $H['margin_pct'] * $a)));
		if ($ref - $new >= $H['ignore_a']) {
			$ev = ['cut', $ref, $new, $a];
			$h['cap'] = $new; $h['last_cut'] = $now; $h['below_since'] = null; $h['near_since'] = null;
			return [$h, $ev];
		}
	}
	return [$h, null];
}

function lb_hr_event_text($id, $name, $ev){
	switch ($ev[0]) {
		case 'cut':  return sprintf('u%d %s: capped at %d A (using %.1f of %d A for 2 min; %d A for the others)', $id, $name, $ev[2], $ev[3], $ev[1], $ev[1] - $ev[2]);
		case 'claw': return sprintf('u%d %s: cap lifted (using %.1f A of its %d A cap), back to its share', $id, $name, $ev[2], $ev[1]);
		case 'end':  return sprintf('u%d %s: stopped charging, %d A cap removed', $id, $name, $ev[1]);
	}
	return '';
}

// plugged-in as far as the split is concerned: a unit asleep on its schedule (254) or disabled by
// someone else (255) is idle even with a car connected; a unit WE paused: what is really plugged in.
function lb_effective_plugged($plugged, $state, $pausedByLb){
	if ($plugged === null) return null;
	if ($pausedByLb) return (bool)$plugged;
	if ($state === 254 || $state === 255) return false;
	return (bool)$plugged;
}

// The only commands the coordinator may send. Anything else (schedule $ST, sleep $FS,
// reboot $FR, ...) is refused by lb_cmd_allowed().
const LB_RAPI_ALLOWED = ['$SC', '$FD', '$FE', '$GE', '$GS', '$GG', '$GC'];
// Units NOT in a load group are only monitored: reads only, never a write -
// no $SC/$FD/$FE, no claim, no saved current. lb_write_allowed() guards every write path.
const LB_READ_ONLY_CMDS = ['$GE', '$GS', '$GG', '$GC'];
function lb_write_allowed(array $unit){ return empty($unit['monitor']); }
function lb_cmd_allowed_for(array $unit, $cmd){
	if (!lb_cmd_allowed($cmd)) return false;
	return lb_write_allowed($unit) || in_array(preg_split('/\s+/', trim((string)$cmd))[0], LB_READ_ONLY_CMDS, true);
}
function lb_cmd_allowed($cmd){
	$t = preg_split('/\s+/', trim($cmd));
	if (!in_array($t[0], LB_RAPI_ALLOWED, true)) return false;
	if ($t[0] === '$SC') return count($t) >= 2 && ctype_digit($t[1]) && (int)$t[1] >= LB_MIN_AMPS && (count($t) == 2 || (count($t) == 3 && $t[2] === 'V'));
	return count($t) == 1;
}

// Pause / resume steps per API: [['rapi', '$FD', delay_s] | ['claim', props] | ['release'], ...]
// v5: our claim carries the share too (a POST replaces the whole claim), so pause = claim with
// state disabled at $amps, resume = claim without a state at $amps.
function lb_pause_steps($api, $pause, $amps = LB_MIN_AMPS){
	if ($api === 'http') return [['claim', ($pause ? ['state'=>'disabled'] : []) + ['charge_current'=>(int)$amps, 'max_current'=>(int)$amps, 'auto_release'=>false], 0]];
	return $pause ? [['rapi', '$FD', 0]] : [['rapi', '$FE', 0]];
}
function lb_steps_text(array $steps){
	return implode(', then ', array_map(function($s){
		if ($s[0] === 'rapi') return $s[1] . ($s[2] ? " after {$s[2]} s" : '');
		if ($s[0] === 'claim') return 'claim ' . json_encode($s[1]);
		return 'release our claim';
	}, $steps));
}
