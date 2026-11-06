<?php
/******************************************************************************
 * sess_path.php - where sessions.db lives.
 *
 * Resolution (first match wins), relative to the site folder:
 *  1. private/sess_db_path  — optional one-line override (absolute, or relative
 *     to the site folder). No secrets; deploy-time switch only.
 *  2. data/sessions.db      — preferred protected location, if the file exists
 *  3. sessions.db           — legacy default next to the PHP pages
 *
 * Pages and collectors include this (or get it via sessions_lib / util_lib).
 * After a cutover that creates data/sessions.db, every caller follows without
 * further code changes. An override file forces a path even when both exist.
 ******************************************************************************/
if (!function_exists('oev_sess_db_path')) {
	function oev_sess_db_path($site = null){
		$site = ($site !== null && $site !== '') ? rtrim(str_replace('\\', '/', (string)$site), '/') : str_replace('\\', '/', __DIR__);
		$ov = $site . '/private/sess_db_path';
		if (is_file($ov)) {
			$p = trim((string)@file_get_contents($ov));
			if ($p !== '' && isset($p[0]) && $p[0] !== '#') {
				$p = str_replace('\\', '/', $p);
				if ($p[0] !== '/') $p = $site . '/' . $p;
				return $p;
			}
		}
		$data = $site . '/data/sessions.db';
		if (is_file($data)) return $data;
		return $site . '/sessions.db';
	}
}
if (!defined('SESS_DB_PATH')) define('SESS_DB_PATH', oev_sess_db_path());

/**
 * Writers pause switch (maintenance). Presence of private/sess_writers_pause
 * (any content; no secrets) makes collector / receiver / jobs skip sessions.db
 * writes. Do NOT create this file on production until Greg approves a window.
 * Pages keep serving (read-only); coordinator/MQTT are unaffected.
 */
if (!function_exists('oev_sess_writers_paused')) {
	function oev_sess_writers_paused($site = null){
		$site = ($site !== null && $site !== '') ? rtrim(str_replace('\\', '/', (string)$site), '/') : str_replace('\\', '/', __DIR__);
		return is_file($site . '/private/sess_writers_pause');
	}
}

