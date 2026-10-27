<?php
/******************************************************************************
 * settings_lib.php - site-wide settings (Settings > Preferences), stored in
 * openevse.db table `settings` (k, v). Read once per request; CLI scripts and
 * the load balancer read the same table. Secrets (SMTP password) are stored
 * encrypted (secret_lib.php) and never shown.
 ******************************************************************************/
require_once __DIR__ . '/secret_lib.php';
if (!defined('OEV_DB_FILE')) define('OEV_DB_FILE', __DIR__ . '/openevse.db');
if (!defined('OEV_SERVER_TZ')) define('OEV_SERVER_TZ', date_default_timezone_get());   // before any override

if (!defined('OEV_SITE_NAME_DEFAULT')) define('OEV_SITE_NAME_DEFAULT', 'OpenEVSE MU Manager');
function oev_settings_defaults(){
	return array(
		'site_name' => OEV_SITE_NAME_DEFAULT,         // page titles, header, email subjects and from-name
		'site_url' => '',                             // web address of this site, '' = detected (oev_site_url())
		'site_url_seen' => '',                        // last detected address (saved when an admin opens Settings), for the CLI scripts
		'volts_l1' => '120', 'volts_l2' => '240',     // assumed volts per service level (no voltage sensors)
		'temp_unit' => 'F',                           // F or C (display only)
		'timezone' => '',                             // '' = server default
		'notify_on' => '0',                           // master switch for emails
		'heartbeat_min' => '30',                      // no word from a unit this long = error
		'smtp_host' => '', 'smtp_port' => '587', 'smtp_secure' => 'tls', 'smtp_user' => '', 'smtp_pass' => '',
		'smtp_from' => '', 'smtp_from_name' => '',   // '' = the site name
	);
}
function oev_settings($reload = false){
	static $s = null;
	if ($s === null || $reload) {
		$s = oev_settings_defaults();
		try {
			$db = new PDO('sqlite:' . OEV_DB_FILE, null, null, array(PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY));
			foreach ($db->query('SELECT k, v FROM settings') as $r) $s[$r['k']] = $r['v'];
			$db = null;
		} catch (Exception $e) { /* no settings table yet: defaults */ }
	}
	return $s;
}
function oev_setting($k, $default = null){ $s = oev_settings(); return array_key_exists($k, $s) ? $s[$k] : $default; }
function oev_setting_int($k, $default, $min, $max){ $v = oev_setting($k); return (is_numeric($v) && (int)$v >= $min && (int)$v <= $max) ? (int)$v : $default; }
function oev_settings_save($kv){
	$db = new PDO('sqlite:' . OEV_DB_FILE, null, null, array(PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
	$st = $db->prepare('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)');
	foreach ($kv as $k => $v) $st->execute(array($k, (string)$v));
	$db = null;
	oev_settings(true);
}
function oev_site_name(){ $n = trim((string)oev_setting('site_name', '')); return $n !== '' ? $n : OEV_SITE_NAME_DEFAULT; }

/* ---------- site web address (email links, view-only links, the units' Emoncms server) ----------
 * Settings > Preferences "Site web address"; blank = detected from the page request. The command-line
 * scripts (jobs.php, notifier.php) have no request: they use the saved address, else the last one
 * detected when an admin opened Settings (site_url_seen). Always ends in '/'; '' if not known. */
function oev_site_url_norm($u){
	$u = trim((string)$u); if ($u === '') return '';
	if (!preg_match('#^https?://#i', $u)) $u = 'https://' . $u;
	if (!filter_var($u, FILTER_VALIDATE_URL) || preg_match('#[?\#]#', $u)) return '';
	return rtrim($u, '/') . '/';
}
function oev_site_url_detect(){
	if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) return '';
	$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
	$sn = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
	$f = str_replace('\\', '/', (string)@realpath($_SERVER['SCRIPT_FILENAME'] ?? '')); $root = str_replace('\\', '/', __DIR__);
	$rel = ($f !== '' && strpos($f, $root . '/') === 0) ? substr($f, strlen($root)) : '/' . basename($sn);   // e.g. /settings.php or /emoncms/receiver.php
	$base = (substr($sn, -strlen($rel)) === $rel) ? substr($sn, 0, -strlen($rel)) : rtrim(dirname($sn), '/');
	return oev_site_url_norm(($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $base);
}
function oev_site_url(){
	$u = oev_site_url_norm(oev_setting('site_url', '')); if ($u !== '') return $u;
	$u = oev_site_url_detect(); if ($u !== '') return $u;
	return oev_site_url_norm(oev_setting('site_url_seen', ''));
}

/* ---------- unit hostnames (Settings > Units, Help "Names") ----------
 * Rule: lowercase; anything not a-z/0-9 becomes '-'; repeated dashes merged;
 * dashes trimmed from the ends; at most 32 characters; empty -> evse-<unit #>.
 * oev_host_suggest(): if two units come out the same, the later one (by unit #) gets -<unit #>. */
define('OEV_HOST_MAX', 32);
function oev_host_base($name, $id){
	$s = preg_replace('/-+/', '-', preg_replace('/[^a-z0-9]/', '-', strtolower((string)$name)));
	$s = trim(substr(trim($s, '-'), 0, OEV_HOST_MAX), '-');
	return $s !== '' ? $s : 'evse-' . (int)$id;
}
function oev_host_suffix($base, $id){ $x = '-' . (int)$id; return rtrim(substr($base, 0, OEV_HOST_MAX - strlen($x)), '-') . $x; }
// $units: rows with id and name -> array(id => suggested hostname)
function oev_host_suggest($units){
	$rows = array(); foreach ($units as $u) $rows[(int)$u['id']] = (string)$u['name']; ksort($rows);
	$out = array(); $used = array();
	foreach ($rows as $id => $name) {
		$h = oev_host_base($name, $id);
		if (isset($used[$h])) $h = oev_host_suffix($h, $id);
		$used[$h] = 1; $out[$id] = $h;
	}
	return $out;
}
function oev_timezone(){ $tz = (string)oev_setting('timezone', ''); return ($tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) ? $tz : OEV_SERVER_TZ; }
// apply the override once, when the library is first loaded
if (!defined('OEV_TZ_APPLIED')) { define('OEV_TZ_APPLIED', 1); if (oev_timezone() !== date_default_timezone_get()) date_default_timezone_set(oev_timezone()); }
