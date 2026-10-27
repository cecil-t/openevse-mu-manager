<?php
/* Load balancer state for the web pages (home + lbstatus): the coordinator's heartbeat file
 * (loadbalancer/run/heartbeat.json, rewritten every 5 s) instead of polling grouped units.
 * How fresh it is: charging units' amps are read every 5 s; a state change (plug in, wake,
 * sleep) is reported over MQTT and read within ~1-3 s, otherwise on the next 20 s poll; a
 * setting is read back right after every change. Older than 90 s = load balancer down:
 * the home page polls the units itself, as before.
 * Slow-changing things the heartbeat doesn't carry (schedule, session limit, lifetime kWh,
 * clock check, volts) come from a per-unit cache refreshed by a full read every 10 min,
 * when a car plugs in or out, or after any action on the unit page (action.php clears it). */
define('EVSE_LB_STALE', 90);          // seconds: heartbeat older than this = load balancer down
define('EVSE_HOME_SLOW_TTL', 600);    // seconds: schedule / limit / lifetime / clock cache

function evse_lb_heartbeat(){
	$hb = @json_decode((string)@file_get_contents(__DIR__ . '/loadbalancer/run/heartbeat.json'), true);
	if (!is_array($hb) || empty($hb['time'])) return null;
	$hb['age'] = time() - (int)$hb['time'];
	$hb['fresh'] = $hb['age'] <= EVSE_LB_STALE;
	return $hb;
}
// heartbeat entry per unit id (fresh heartbeats only)
function evse_lb_units($hb){
	$o = array();
	if (is_array($hb) && !empty($hb['fresh'])) {
		foreach ((array)$hb['groups'] as $g) foreach ((array)$g['units'] as $u) $o[(int)$u['id']] = $u + array('_group'=>$g['id']);
		foreach ((array)($hb['monitor'] ?? array()) as $u) $o[(int)$u['id']] = $u + array('_group'=>0);   // not in a group: monitored, never written
	}
	return $o;
}
// short note for a grouped unit: why its setting is what it is (empty = nothing special)
function evse_lb_note($u){
	if (!is_array($u)) return '';
	if (!empty($u['lb_paused']) || !empty($u['pause'])) return (!empty($u['plugged']) || !empty($u['charging'])) ? 'waiting' : 'amps lent';
	if (isset($u['cap']) && $u['cap'] !== null) return 'capped';
	if (!empty($u['sleepfree'])) return 'not counted';
	return '';
}
// seconds since the unit's last MQTT check-in (null = never)
function evse_lb_checkin_age($u){ return (is_array($u) && !empty($u['mqtt_seen'])) ? max(0, time() - (int)$u['mqtt_seen']) : null; }

function evse_home_slow_file($unit){ return sys_get_temp_dir() . '/oev_home_slow_' . md5((string)$unit['evseurl']) . '.json'; }
function evse_home_slow_get($unit){ $c = @json_decode((string)@file_get_contents(evse_home_slow_file($unit)), true); return is_array($c) ? $c : null; }
function evse_home_slow_clear($unit){ @unlink(evse_home_slow_file($unit)); }
function evse_home_slow_put($unit, $e){
	$c = array('t'=>time(), 'api'=>$e['api'] ?? null, 'sched'=>$e['sched'] ?? null, 'lim'=>$e['lim'] ?? null, 'connected'=>!empty($e['connected']),
		'lifetimeKWH'=>$e['lifetimeKWH'] ?? 0, 'clock_offset'=>$e['clock_offset'] ?? null, 'clock_off'=>!empty($e['clock_off']), 'volts'=>$e['volts'] ?? null);
	$old = evse_home_slow_get($unit);
	if ($c['volts'] === null && is_array($old)) $c['volts'] = $old['volts'] ?? null;   // volts are only known while charging: keep the last one
	@file_put_contents(evse_home_slow_file($unit), json_encode($c), LOCK_EX);
}
// a list-page summary built from the heartbeat + the slow cache (same keys evse_par_summary_gen() returns)
function evse_home_from_hb($x, $slow){
	$code = (int)$x['state'];
	$e = array('api'=>$slow['api'], 'statecode'=>$code, 'state'=>evse_state_text($code), 'connected'=>!empty($x['plugged']) ? 1 : 0,
		'pilot'=>$x['setting'], 'saved_current'=>$x['saved'], 'amps'=>$x['amps'], 'lifetimeKWH'=>$slow['lifetimeKWH'],
		'sched'=>$slow['sched'], 'lim'=>$slow['lim'], 'clock_offset'=>$slow['clock_offset'], 'clock_off'=>$slow['clock_off'], 'src'=>'lb');
	if ($code == 3 && $x['amps'] !== null) $e['power'] = round((float)$x['amps'] * (float)($slow['volts'] ?: (defined('EVSE_VOLTS_L2') ? EVSE_VOLTS_L2 : 240)) / 1000, 1);
	return $e;
}
// every unit's summary for the home page: units the coordinator reads (grouped + monitored) from the heartbeat when it is fresh (and
// the unit was read in the last minute), everything else - and any unit whose slow cache is due -
// read live in parallel (evse_summary_all), which also refreshes that unit's slow cache.
function evse_home_all($units, $hb, $grouped){
	$hu = evse_lb_units($hb); $out = array(); $live = array();
	foreach ($units as $k => $u) {
		$x = $hu[(int)$u['id']] ?? null; $slow = evse_home_slow_get($u);
		$ok = is_array($x) && !empty($x['ok']) && $x['state'] !== null && $x['setting'] !== null
			&& time() - (int)($x['last_ok'] ?? 0) <= 60 && is_array($slow) && time() - (int)$slow['t'] <= EVSE_HOME_SLOW_TTL
			&& (bool)$slow['connected'] === !empty($x['plugged']);
		if ($ok) $out[$k] = evse_home_from_hb($x, $slow); else $live[$k] = $u;
	}
	if ($live) foreach (evse_summary_all($live) as $k => $e) {
		$out[$k] = $e;
		if (is_array($e) && ($e['statecode'] ?? -1) != -1) evse_home_slow_put($units[$k], $e);
	}
	$o = array(); foreach ($units as $k => $u) $o[$k] = $out[$k] ?? null;   // keep the unit order
	return $o;
}

// what the pages show for one grouped unit, from its heartbeat entry (lbstate.php sends this to the
// pages' live-update script, index.php / lbstatus.php render the same on load)
function evse_lb_view($u){
	$code = ($u['state'] ?? null) === null ? -1 : (int)$u['state'];
	$paused = !empty($u['lb_paused']);
	$set = $u['setting'] ?? null; $sv = $u['saved'] ?? null;
	$ca = evse_lb_checkin_age($u);
	return array(
		'id'=>(int)$u['id'], 'ok'=>!empty($u['ok']), 'code'=>$code,
		'pill'=>$paused ? 'Paused' : ($code == -1 ? 'Connection Error' : evse_state_text($code)),
		'pill_cls'=>in_array($code, array(1, 2, 3, 254, 255), true) ? 's' . $code : 'serr',
		'pill_title'=>$paused ? 'Paused by the load balancer (idle; its amps go to the charging cars)' : ($code == -1 ? 'Not responding' : evse_state_text($code)),
		'plugged'=>!empty($u['plugged']), 'charging'=>$code == 3, 'amps'=>$u['amps'] ?? null,
		'label'=>($set !== null && (int)$set > 0) ? evse_amps_label($set, $sv) : '',
		'label_title'=>($set !== null && $sv !== null && (int)$set < (int)$sv) ? 'Temporary ' . (int)$set . ' A (saved ' . (int)$sv . ' A)' : 'Saved setting',
		'setting'=>$set, 'saved'=>$sv, 'note'=>evse_lb_note($u), 'why'=>$u['why'] ?? '',
		'checkin'=>$ca, 'stale'=>($ca === null || $ca > 90),
		'cap'=>$u['cap'] ?? null, 'paused'=>$paused, 'sleepfree'=>!empty($u['sleepfree']));
}
