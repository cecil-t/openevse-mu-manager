<?php
/******************************************************************************
 * db_path.php - where the config database (openevse.db) lives.
 *
 * Resolution (first match wins), relative to the site folder:
 *  1. private/cfg_db_path  - optional one-line override (absolute, or relative
 *     to the site folder). No secrets; deploy-time switch only.
 *  2. data/openevse.db     - protected location (deny-all data/.htaccess), if it exists
 *  3. openevse.db          - legacy location next to the PHP pages, if it exists
 *  4. data/openevse.db     - nothing exists yet: a new install makes it here
 * The path is worked out from the site folder, never the working directory, so
 * pages, collector/ CLI jobs and the coordinator container all agree.
 * oev_cfg_db_open() refuses a missing file so a reader can never create an empty
 * database by accident; only setup (collector/setup_auth.php) may create it.
 ******************************************************************************/
if (!function_exists('oev_cfg_db_path')) {
	function oev_cfg_db_path($site = null){
		$site = ($site !== null && $site !== '') ? rtrim(str_replace('\\', '/', (string)$site), '/') : str_replace('\\', '/', __DIR__);
		$ov = $site . '/private/cfg_db_path';
		if (is_file($ov)) {
			$p = trim((string)@file_get_contents($ov));
			if ($p !== '' && $p[0] !== '#') { $p = str_replace('\\', '/', $p); return $p[0] === '/' ? $p : $site . '/' . $p; }
		}
		if (is_file($site . '/data/openevse.db')) return $site . '/data/openevse.db';
		if (is_file($site . '/openevse.db')) return $site . '/openevse.db';
		return $site . '/data/openevse.db';
	}
	// PDO on the config DB; throws if the file is missing (unless $create, setup only)
	function oev_cfg_db_open(array $opts = array(), $create = false, $site = null){
		$p = ($site === null && defined('OEV_DB_FILE')) ? OEV_DB_FILE : oev_cfg_db_path($site);
		if (!$create && !is_file($p)) throw new RuntimeException('config database not found');
		if ($create && !is_dir(dirname($p))) @mkdir(dirname($p), 0750, true);
		return new PDO('sqlite:' . $p, null, null, $opts + array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
	}
}
if (!defined('OEV_DB_FILE')) define('OEV_DB_FILE', oev_cfg_db_path());
