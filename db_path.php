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
 * oev_cfg_db_open() never creates a file (SQLITE_OPEN_READWRITE without CREATE,
 * PHP 8.1+); only setup (collector/setup_auth.php) may create it.
 * Maintenance (private/cfg_db_maint present, made only by private/cfg_db_move.php):
 * every connection is opened read-only, so any write fails loudly instead of
 * landing in a file that is about to be replaced. Reads keep working.
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
	function oev_cfg_maint($site = null){
		$site = ($site !== null && $site !== '') ? rtrim(str_replace('\\', '/', (string)$site), '/') : str_replace('\\', '/', __DIR__);
		return is_file($site . '/private/cfg_db_maint');
	}
	// PDO on the config DB. Never creates it unless $create (setup only); read-only during maintenance.
	function oev_cfg_db_open(array $opts = array(), $create = false, $site = null){
		$p = oev_cfg_db_path($site);   // resolved on every open, so a long-running process follows a move
		$maint = oev_cfg_maint($site);
		if ($create && $maint) throw new RuntimeException('config database maintenance in progress');
		if (!$create && !is_file($p)) throw new RuntimeException('config database not found');
		if ($create && !is_dir(dirname($p))) @mkdir(dirname($p), 0750, true);
		$ro = isset($opts[PDO::SQLITE_ATTR_OPEN_FLAGS]) && ($opts[PDO::SQLITE_ATTR_OPEN_FLAGS] & PDO::SQLITE_OPEN_READONLY);
		if ($maint || $ro) $opts[PDO::SQLITE_ATTR_OPEN_FLAGS] = PDO::SQLITE_OPEN_READONLY;
		elseif (!$create) $opts[PDO::SQLITE_ATTR_OPEN_FLAGS] = PDO::SQLITE_OPEN_READWRITE;   // no CREATE: a vanished file is an error, never a new empty DB
		return new PDO('sqlite:' . $p, null, null, $opts + array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
	}
}
if (!defined('OEV_DB_FILE')) define('OEV_DB_FILE', oev_cfg_db_path());
