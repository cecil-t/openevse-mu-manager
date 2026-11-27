<?php
/******************************************************************************
 * evse_v5.php - is a unit's /config the WiFi v4/v5 JSON API?
 * Safe to include on its own (no database). Used by include_v5.php, jobs, and
 * Settings.
 ******************************************************************************/
if (!function_exists('evse_config_is_v5')) {
	// Version major 4+, or a local/dev build with no leading version number
	// that still has max_current_soft (e.g. local_pr1395_...).
	function evse_config_is_v5($c){
		return is_array($c) && ((int)ltrim((string)($c['version'] ?? ''), 'vV') >= 4 || isset($c['max_current_soft']));
	}
}
