<?php
/******************************************************************************
 * cap_lib.php - the site's Max current (Settings, evses.breaker) is the ceiling
 * for each unit's own SAVED current (v5 config max_current_soft, 2.x $GC 4th
 * field). Needs include_v5.php. Used by Settings (unit save) and the collector
 * (self-heal every SESS_CAP_CHECK_SEC per unit).
 *   oev_cap_read($unit)          fresh read: hw (hardware max), saved, active
 *   oev_cap_enforce($unit, $db)  refresh evses.hw_max if the unit reports a
 *                                different hardware max; lower a saved current
 *                                above min(Max current, hw) to that cap.
 * Only the saved current (and hw_max in the site database) is ever written:
 * never schedules, sleep state or overrides. On 2.x the active current is put
 * back as a temporary value when it was below the cap (the load balancer's share).
 ******************************************************************************/
function oev_cap_read($unit){
	$d = evse_detect($unit, true);
	if ($d['api'] === 'offline') return array('ok'=>false, 'api'=>'offline');
	if ($d['api'] === 'http') {
		$c = is_array($d['config']) ? $d['config'] : array();
		$hw = isset($c['max_current_hard']) ? (int)$c['max_current_hard'] : null;
		$sv = isset($c['max_current_soft']) ? (int)$c['max_current_soft'] : null;
		return array('ok'=>$sv !== null, 'api'=>'http', 'hw'=>($hw >= 6 && $hw <= 80) ? $hw : null, 'saved'=>$sv, 'active'=>null);
	}
	for ($i = 0; $i < 3; $i++) {
		if ($i) usleep(evse_rapi_retry_delay_us());
		$gc = rapi($unit, '$GC');   // "min hwmax active saved"
		if (evse_rapi_ok($gc) && preg_match('/^\s*(\d{1,2}) (\d{1,2}) (\d{1,2}) (\d{1,2})\s*$/', $gc, $m)) {
			$hw = (int)$m[2]; $sv = (int)$m[4];
			if ($hw >= 6 && $hw <= 80 && $sv >= 6 && $sv <= 80) return array('ok'=>true, 'api'=>'rapi', 'hw'=>$hw, 'saved'=>$sv, 'active'=>(int)$m[3]);
		}
	}
	return array('ok'=>false, 'api'=>'rapi');
}

function oev_cap_enforce($unit, $db = null){
	$out = array('ok'=>false, 'changed'=>false, 'hw_updated'=>false, 'from'=>null, 'to'=>null, 'hw'=>null, 'msg'=>'');
	$x = oev_cap_read($unit);
	if (!$x['ok']) { $out['msg'] = "Couldn't read the unit's saved current."; return $out; }
	$hw = $x['hw']; $out['hw'] = $hw;
	if ($db && $hw && !empty($unit['id']) && (int)($unit['hw_max'] ?? 0) !== $hw) {
		$db->prepare('UPDATE evses SET hw_max = ? WHERE id = ?')->execute(array($hw, (int)$unit['id']));
		$out['hw_updated'] = (int)($unit['hw_max'] ?? 0) ?: true;
	}
	$cap = (int)($unit['breaker'] ?? 0); if ($cap <= 0) $cap = 80;
	if ($hw) $cap = min($cap, $hw);
	$out['ok'] = true; $out['from'] = $x['saved']; $out['to'] = $x['saved'];
	if ($cap < 6 || $x['saved'] <= $cap) return $out;
	if ($x['api'] === 'http') {
		$u = $unit; $u['breaker'] = $cap;
		$r = evse_set_current($u, $cap);   // POST /config max_current_soft only
	} else {
		$r = evse_rapi_action($unit, '$SC ' . $cap, 'Save current');
		if ($r['ok'] && $x['active'] >= 6 && $x['active'] < $cap) evse_rapi_action($unit, '$SC ' . (int)$x['active'] . ' V', 'Put back current (temporary)');
	}
	$y = oev_cap_read($unit);
	$out['changed'] = !empty($r['ok']);
	$out['ok'] = $y['ok'] && $y['saved'] === $cap;
	$out['to'] = $y['ok'] ? $y['saved'] : null;
	$out['msg'] = "The unit's saved current was {$x['saved']} A, above Max current ($cap A): " . ($out['ok'] ? "lowered to $cap A." : 'tried to lower it to ' . $cap . ' A, but it now reads ' . ($y['ok'] ? $y['saved'] . ' A' : 'nothing') . '.');
	return $out;
}
