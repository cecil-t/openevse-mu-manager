<?php
/* Wide (desktop) home page layout: the data behind each row and its expanded detail.
 * Everything here comes from what the home page already has (the load balancer's heartbeat,
 * the home-page cache, sessions.db); nothing here calls a unit. unitinfo.php fills in the
 * rest (firmware, hostname, safety checks ...) with one read when a row is opened. */
if (!function_exists('h')) { http_response_code(404); exit; }
define('IDX_TEMP_NEAR_C', 60.0);  // yellow from here: warm, close to throttling
define('IDX_THROTTLE_C', 65.0);   // orange: controller cuts current to half (controller 8.2.2 ambient thresholds)
define('IDX_QUARTER_C', 68.0);    // still orange: cut to a quarter
define('IDX_OVERTEMP_C', 71.0);   // red: over-temperature fault, charging stopped
define('IDX_TEMP_COLD_C', (40 - 32) * 5 / 9);   // blue below 40 F (4.44 C): cold

// temperature (Celsius) with its coloured dot: red over-temp, orange throttling, yellow warm, blue cold, green fine
function idx_temp_html($c, $after = false){   // $after: the dot after the value (unit.php), as its Last check-in dot
	if ($c === null || $c === '' || !is_numeric($c)) return '';
	$c = (float)$c;
	$cls = $c >= IDX_OVERTEMP_C ? 'r' : ($c >= IDX_THROTTLE_C ? 'o' : ($c >= IDX_TEMP_NEAR_C ? 'y' : ($c < IDX_TEMP_COLD_C - 1e-9 ? 'b' : 'g')));
	$T = function($x){ return strip_tags(html_entity_decode(temp_txt($x))); };
	$t = $cls == 'b' ? 'Cold (blue below ' . $T(IDX_TEMP_COLD_C) . ')'
		: ($cls == 'r' ? 'Over-temperature, charging stopped' : ($cls == 'o' ? ($c >= IDX_QUARTER_C ? 'Throttling (quarter current)' : 'Throttling (half current)') : ($cls == 'y' ? 'Warm, close to throttling' : 'Normal')))
		. ' (yellow from ' . $T(IDX_TEMP_NEAR_C) . ', half current from ' . $T(IDX_THROTTLE_C) . ', quarter from ' . $T(IDX_QUARTER_C) . ', stops at ' . $T(IDX_OVERTEMP_C) . ')';
	$dot = '<span class="td ' . $cls . ($after ? ' ta' : '') . '" title="' . h($t) . '"></span>';
	return $after ? temp_txt($c) . $dot : $dot . temp_txt($c);
}
// WiFi signal as 5 bars
function idx_bars_html($dbm){
	if ($dbm === null || $dbm === '' || !is_numeric($dbm) || (int)$dbm >= 0) return '';
	$d = (int)$dbm; $n = $d >= -55 ? 5 : ($d >= -62 ? 4 : ($d >= -70 ? 3 : ($d >= -78 ? 2 : 1)));
	$o = '<span class="bars" title="' . $d . ' dBm (' . $n . ' of 5 bars)">';
	for ($i = 1; $i <= 5; $i++) $o .= '<i' . ($i <= $n ? ' class="on"' : '') . '></i>';
	return $o . '</span>';
}
function idx_hm($t){ return date('g:i A', strtotime('2000-01-01 ' . $t)); }
// one unit for the wide table (rendered by index_wide.php's script). Detail values are HTML ('' = not known: a dash)
function idx_wide_unit($unit, $e, $lu, $lv, $gid, $s, $us, $vv, $link){
	$pub = oev_is_public();
	$code = (int)($e['statecode'] ?? -1);
	$off = $code == -1;
	$paused = $lv && $lv['paused'];
	$amps = $off ? null : (($code == 3 && isset($e['amps']) && $e['amps'] !== null) ? round((float)$e['amps'], 1) : 0);
	$pilot = (!$off && isset($e['pilot']) && (int)$e['pilot'] > 0) ? (int)$e['pilot'] : null;
	$saved = isset($e['saved_current']) ? (int)$e['saved_current'] : null;
	$volts = $vv ?: evse_volts($unit, $e['level'] ?? (is_array($us) ? $us['level'] : null));   // volts_lib.php: override, else the Level 1 / Level 2 voltage in Settings > Preferences
	// session: plugged in with an open logger session
	$ses = null; $cur = null;
	if (!$off && !empty($e['connected']) && !empty($s['open'])) {
		$o = $s['open'];
		$k = ((float)$o['kwh'] > 0) ? (float)$o['kwh'] : ($o['kwh_meter'] !== null ? (float)$o['kwh_meter'] : null);
		$ses = array('kwh'=>$k === null ? null : round($k, 1), 'dur'=>idx_dur(time() - (int)$o['plug_in_at']));
		$cur = evse_last_session($unit, true);
	}
	// schedule
	$sc = $e['sched'] ?? null; $sch = ''; $run = false;
	if (is_array($sc)) { $sch = idx_hm($sc[0]) . "\u{2013}" . idx_hm($sc[1]); $run = evse_sched_running($sc, $e); }
	elseif ($sc === false && !empty($e['lim'])) $sch = 'Limit ' . implode(', ', $e['lim']);
	elseif ($sc === false) $sch = 'Always';
	// load balancer share (grouped units: the setting it gave this unit)
	$share = ($gid && is_array($lu) && isset($lu['setting'])) ? (int)$lu['setting'] : null;
	// last seen: newest of the MQTT check-in, the load balancer's read, the logger's read
	$seen = max((int)($lu['mqtt_seen'] ?? 0), (int)($lu['last_ok'] ?? 0), (int)($us['last_ok_at'] ?? 0));
	$ca = $seen ? max(0, time() - $seen) : null;
	$upt = '';
	if (is_array($us) && $us['wifi_uptime'] !== null && $us['last_poll_at']) $upt = evse_uptime_text((int)$us['wifi_uptime'] + max(0, time() - (int)$us['last_poll_at']));
	$ct = '';
	if ($cur && (int)$cur['seg_seconds'] > 0) { $tx = evse_elapsed_text((int)$cur['seg_seconds']); $ct = $pub ? h($tx) : '<a href="sessions.php?id=' . (int)$cur['id'] . '" title="Current session report">' . h($tx) . '</a>'; }
	$ls = '';
	if (!$pub && !empty($s['last'])) {
		$l = $s['last']; $lk = ($l['kwh'] !== null && (float)$l['kwh'] > 0) ? $l['kwh'] : $l['kwh_meter'];
		$ls = '<a href="sessions.php?id=' . (int)$l['id'] . '" title="' . h(date('D M j g:ia', (int)$l['plug_in_at'])) . '">' . h(date('n/j', (int)$l['plug_in_at'])) . '</a>' . ($lk !== null ? ' &middot; ' . h(round((float)$lk, 1)) . ' kWh' : '');
	}
	$host = parse_url((string)$unit['evseurl'], PHP_URL_HOST);
	$lvl = (is_array($us) && $us['level']) ? 'L' . (int)$us['level'] : '';
	return array(
		'id'=>(int)$unit['id'], 'name'=>(string)$unit['name'], 'link'=>$link, 'g'=>(int)$gid, 'test'=>!empty($unit['_test']),
		'code'=>$code, 'pill'=>$paused ? 'Paused' : (string)$e['state'],
		'paused'=>$paused,
		'ptip'=>evse_pill_title($code, $paused),
		'pcls'=>in_array($code, array(1, 2, 3, 254, 255), true) ? 's' . $code : 'serr',
		'plugged'=>!empty($e['connected']), 'amps'=>$amps, 'pilot'=>$pilot, 'saved'=>$saved, 'volts'=>$volts,
		'ses'=>$ses, 'sch'=>$sch, 'run'=>$run, 'share'=>$share, 'seen'=>$seen ?: null, 'stale'=>$lv ? (bool)$lv['stale'] : false,
		'clock_off'=>!empty($e['clock_off']),
		'd'=>array(   // expanded detail, HTML
			'pilot'=>$pilot === null ? '' : h(evse_amps_label($pilot, $saved)) . (($saved !== null && $pilot < $saved) ? ' / ' . $saved . ' A' : '') . ($volts ? ' &middot; ' . round($pilot * $volts / 1000, 1) . ' kW' : ''),
			'volts'=>$volts ? (int)$volts . ' volts' : '',
			'temp'=>idx_temp_html($us['temp_max_c'] ?? null),
			'energy'=>($ses && $ses['kwh'] !== null) ? h($ses['kwh']) . ' kWh' : '',
			'ct'=>$ct,
			'start'=>is_array($sc) ? h(idx_hm($sc[0]) . ' / ' . idx_hm($sc[1])) : ($sc === false ? 'None' : ''),
			'lim'=>!empty($e['lim']) ? h(implode(" \u{00B7} ", $e['lim'])) : '',
			'level'=>$lvl,
			'maxa'=>(int)$unit['breaker'] . ' A',
			'checkin'=>$ca === null ? '' : '<span class="lbd ' . ($ca > 90 ? 'r' : 'g') . '"></span> ' . h($ca < 120 ? $ca . ' s ago' : ($ca < 7200 ? round($ca / 60) . ' min ago' : round($ca / 3600, 1) . ' h ago')),
			'uptime'=>h($upt),
			'api'=>h($e['api'] ?? ''),
			'ip'=>oev_is_admin() ? h($host ?: '') : '',   // unit address: admins only
			'rssi'=>idx_bars_html($us['srssi'] ?? null),
			'last'=>$ls,
			'life'=>(!$pub && isset($e['lifetimeKWH']) && $e['lifetimeKWH'] !== null && !$off) ? round((float)$e['lifetimeKWH'] / 1000, 2) . ' MWh' : '',
		),
	);
}
