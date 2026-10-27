<?php
/* Display preferences for the OpenEVSE pages. Storage is unchanged: the
 * database and the units always use Celsius; this only changes what is shown.
 * TEMP_UNIT comes from Settings > Preferences. */
require_once __DIR__ . '/settings_lib.php';
if (!defined('TEMP_UNIT')) define('TEMP_UNIT', oev_setting('temp_unit', 'F') === 'C' ? 'C' : 'F');      // 'F' or 'C', from Settings > Preferences

if (!function_exists('temp_conv')) {
	// Celsius -> display unit (float), null stays null
	function temp_conv($c){ return ($c === null || $c === '') ? null : (TEMP_UNIT == 'F' ? (float)$c * 9 / 5 + 32 : (float)$c); }
	function temp_sym(){ return TEMP_UNIT == 'F' ? '&deg;F' : '&deg;C'; }
	// number only, rounded for display (whole degrees F, tenths C)
	function temp_num($c){ return ($c === null || $c === '') ? '-' : (string)round(temp_conv($c), TEMP_UNIT == 'F' ? 0 : 1); }
	function temp_txt($c){ return ($c === null || $c === '') ? '-' : temp_num($c) . ' ' . temp_sym(); }
	// for values already in Fahrenheit (legacy RAPI include.php / evse_status 'temp')
	function temp_txt_f($f){ return is_numeric($f) ? temp_txt(((float)$f - 32) * 5 / 9) : htmlspecialchars((string)$f); }
}
