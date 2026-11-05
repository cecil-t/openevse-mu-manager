<?php
/******************************************************************************
 * volts_lib.php - the ONE voltage rule for the whole site: unit pages, pilot kW,
 * session kW / averages / graphs and the volts stored with each sample.
 *
 * None of these units has a voltage sensor. The controller's $GG millivolts and
 * WiFi v5's "voltage" are not measurements: the controller sets them from the
 * service level (MV_FOR_L1 120000 / MV_FOR_L2 240000 mV), and v5 reports a 240 V
 * default until it has read $GG (which it only does while charging).
 *
 * Order: per-unit override -> measured (only if the unit has a voltmeter) ->
 * its load group's voltage (load_groups.volts_override, if set) ->
 * the assumed voltage for its service level (below).
 * Per-unit settings are nullable columns in openevse.db 'evses':
 *   volts_override  e.g. 208 (3-phase commercial) or 230 (abroad); NULL = use the defaults
 *   voltmeter       1 = a real voltage sensor is fitted (OpenEVSE II / add-on); NULL/0 = none
 ******************************************************************************/
// ---- assumed line voltage per service level (site-wide defaults)
// from Settings > Preferences (Level 1 / Level 2 voltage); defaults 120 / 240
require_once __DIR__ . '/settings_lib.php';
if (!defined('EVSE_VOLTS_L1')) define('EVSE_VOLTS_L1', oev_setting_int('volts_l1', 120, 90, 260));   // North America 120; 230 most of the world
if (!defined('EVSE_VOLTS_L2')) define('EVSE_VOLTS_L2', oev_setting_int('volts_l2', 240, 90, 260));   // split-phase 240; 208 3-phase commercial; 230 abroad
if (!defined('EVSE_VOLTS_MIN')) define('EVSE_VOLTS_MIN', 90);   // plausible range for an override or a reading
if (!defined('EVSE_VOLTS_MAX')) define('EVSE_VOLTS_MAX', 260);

function evse_has_voltmeter($unit){ return is_array($unit) && !empty($unit['voltmeter']); }

function evse_volts_v($x){   // V or mV -> V, or null if implausible
	if ($x === null || $x === false || !is_numeric($x)) return null;
	$v = (float)$x; if ($v > 1000) $v /= 1000;
	return ($v >= EVSE_VOLTS_MIN && $v <= EVSE_VOLTS_MAX) ? (int)round($v) : null;
}

// a load group's voltage override (openevse.db load_groups.volts_override; NULL = the Preferences voltage), re-read at most once a minute
function evse_group_volts($gid){
	static $m = null, $at = 0;
	if (!$gid) return null;
	if ($m === null || time() - $at > 60) {
		$m = array(); $at = time();
		try { $db = new PDO('sqlite:' . OEV_DB_FILE, null, null, array(PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY));
			foreach ($db->query('SELECT id, volts_override FROM load_groups') as $g) $m[(int)$g['id']] = evse_volts_v($g['volts_override']); } catch (Exception $e) {}   // no column yet: none
	}
	return $m[(int)$gid] ?? null;
}
// returns array(volts or null, source: 'override' | 'measured' | 'group' | 'L1 assumed' | 'L2 assumed' | '')
function evse_volts_info($unit, $level, $measured = null){
	if (is_array($unit) && ($v = evse_volts_v($unit['volts_override'] ?? null)) !== null) return array($v, 'override');
	if (evse_has_voltmeter($unit) && ($v = evse_volts_v($measured)) !== null) return array($v, 'measured');
	if (is_array($unit) && ($v = evse_group_volts($unit['loadgroupid'] ?? null)) !== null) return array($v, 'group');
	if ((int)$level === 1) return array(EVSE_VOLTS_L1, 'L1 assumed');
	if ((int)$level === 2) return array(EVSE_VOLTS_L2, 'L2 assumed');
	return array(null, '');
}
function evse_volts($unit, $level, $measured = null){ $i = evse_volts_info($unit, $level, $measured); return $i[0]; }
