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
 * Writer lock contract (cross-process, per site, independent of the DB's location):
 * every write-capable connection first takes a SHARED flock on data/cfg_db.lock,
 * BEFORE the path is resolved, and keeps it until the process ends (a web request
 * or one CLI run). private/cfg_db_move.php takes it EXCLUSIVE, so it waits for every
 * open write-capable connection (including uncommitted transactions) to be gone,
 * and new ones wait (bounded) until the move is published, then open the new path.
 * Read-only opens (coordinator, report pages) take no lock and need no write access.
 * Maintenance (private/cfg_db_maint, made only by cfg_db_move.php): new connections
 * open read-only (a write fails loudly) so the site keeps serving pages meanwhile.
 * Code that opens openevse.db without this function is NOT covered (see docs).
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
	// Shared writer lock, held for the rest of the process. Bounded wait; web: 503 on timeout.
	function oev_cfg_lock_sh($site = null, $wait = null){
		static $held = array();
		$site = ($site !== null && $site !== '') ? rtrim(str_replace('\\', '/', (string)$site), '/') : str_replace('\\', '/', __DIR__);
		if (isset($held[$site])) return true;
		$wait = $wait !== null ? (float)$wait : (float)(getenv('OEV_CFG_LOCK_WAIT') ?: 10);
		$h = @fopen($site . '/data/cfg_db.lock', 'c');
		if (!$h) throw new RuntimeException('config DB lock file unavailable');
		$end = microtime(true) + $wait;
		while (!flock($h, LOCK_SH | LOCK_NB)) {
			if (microtime(true) >= $end) {
				fclose($h);
				if (PHP_SAPI !== 'cli' && !headers_sent()) { http_response_code(503); header('Retry-After: 30'); echo 'The site is doing a short maintenance step. Try again in a minute.'; exit; }
				throw new RuntimeException('config database maintenance in progress (lock wait timed out)');
			}
			usleep(50000);
		}
		$held[$site] = $h;   // kept open: the lock lasts until this process ends
		return true;
	}
	// PDO on the config DB. Never creates it unless $create (setup only); read-only during maintenance.
	function oev_cfg_db_open(array $opts = array(), $create = false, $site = null){
		$ro = isset($opts[PDO::SQLITE_ATTR_OPEN_FLAGS]) && ($opts[PDO::SQLITE_ATTR_OPEN_FLAGS] & PDO::SQLITE_OPEN_READONLY);
		if ($create && oev_cfg_maint($site)) throw new RuntimeException('config database maintenance in progress');
		if (!$ro && !oev_cfg_maint($site)) oev_cfg_lock_sh($site);   // lock BEFORE resolving the path
		$maint = oev_cfg_maint($site);                                // re-check after a possible wait
		if ($create && $maint) throw new RuntimeException('config database maintenance in progress');
		$p = oev_cfg_db_path($site);   // resolved on every open, so a long-running process follows a move
		if (!$create && !is_file($p)) throw new RuntimeException('config database not found');
		if ($create && !is_dir(dirname($p))) @mkdir(dirname($p), 0750, true);
		if ($maint || $ro) $opts[PDO::SQLITE_ATTR_OPEN_FLAGS] = PDO::SQLITE_OPEN_READONLY;
		elseif (!$create) $opts[PDO::SQLITE_ATTR_OPEN_FLAGS] = PDO::SQLITE_OPEN_READWRITE;   // no CREATE: a vanished file is an error, never a new empty DB
		return new PDO('sqlite:' . $p, null, null, $opts + array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
	}
}
if (!defined('OEV_DB_FILE')) define('OEV_DB_FILE', oev_cfg_db_path());
