<?php
/******************************************************************************
 * settings_lib.php - site-wide settings (Settings > Preferences), stored in
 * openevse.db table `settings` (k, v). Read once per request; CLI scripts and
 * the load balancer read the same table. Secrets (SMTP password) are stored
 * encrypted (secret_lib.php) and never shown.
 ******************************************************************************/
require_once __DIR__ . '/secret_lib.php';
require_once __DIR__ . '/db_path.php';   // OEV_DB_FILE, oev_cfg_db_open()
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
			$db = oev_cfg_db_open(array(PDO::ATTR_TIMEOUT => 3, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY));
			foreach ($db->query('SELECT k, v FROM settings') as $r) $s[$r['k']] = $r['v'];
			$db = null;
		} catch (Exception $e) { /* no settings table yet: defaults */ }
	}
	return $s;
}
function oev_setting($k, $default = null){ $s = oev_settings(); return array_key_exists($k, $s) ? $s[$k] : $default; }
function oev_setting_int($k, $default, $min, $max){ $v = oev_setting($k); return (is_numeric($v) && (int)$v >= $min && (int)$v <= $max) ? (int)$v : $default; }
function oev_settings_save($kv){
	$db = oev_cfg_db_open(array(PDO::ATTR_TIMEOUT => 5));
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

// users: "Charging stopped" emails (n_stops, off for everyone until they turn it on; n_stops_since = when they did,
// so nothing from before that is sent). Safe to run again; setup_auth.php runs it.
function oev_users_stop_cols($db){
	$cols = array_column($db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
	$add = array();
	if (!in_array('n_stops', $cols, true)) { $db->exec('ALTER TABLE users ADD COLUMN n_stops INTEGER DEFAULT 0'); $add[] = 'n_stops'; }
	if (!in_array('n_stops_since', $cols, true)) { $db->exec('ALTER TABLE users ADD COLUMN n_stops_since INTEGER'); $add[] = 'n_stops_since'; }
	// Session summary: when it was last turned on (NULL while on = turned on before this column existed: no limit)
	if (!in_array('n_sessions_since', $cols, true)) { $db->exec('ALTER TABLE users ADD COLUMN n_sessions_since INTEGER'); $add[] = 'n_sessions_since'; }
	return $add;
}
function oev_users_have_col($db, $c){ return in_array($c, array_column($db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true); }
function oev_users_have_stops($db){ return in_array('n_stops', array_column($db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true); }

/* ---- per-unit "Your notifications" (each login's own choice per unit; no row = use the account default) ----
 * user_unit_notify: one row per (login, unit, kind). kind 'sessions' (Session summary) or 'stops' (Charging stopped).
 * mode 'on' / 'off' = override; 'inherit' = use the account default (kept only to remember continuity).
 * since: when this unit's email last became effectively ON for this login (null when not on): nothing that
 * happened before it is mailed. updated_at: when the row was saved. */
function oev_unit_notify_table($db){
	if (oev_unit_notify_have($db)) return false;
	$db->exec("CREATE TABLE IF NOT EXISTS user_unit_notify (user_id INTEGER NOT NULL, unit_id INTEGER NOT NULL,
		kind TEXT NOT NULL CHECK (kind IN ('sessions','stops')), mode TEXT NOT NULL CHECK (mode IN ('inherit','on','off')),
		since INTEGER, updated_at INTEGER NOT NULL, PRIMARY KEY (user_id, unit_id, kind))");
	return true;
}
function oev_unit_notify_have($db){ return (bool)$db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'user_unit_notify'")->fetchColumn(); }
function oev_unit_notify_kinds(){ return array('sessions' => 'Session summary', 'stops' => 'Charging stopped'); }
// rows for one login: [unit_id][kind] => row
function oev_unit_notify_rows($db, $user_id){
	$o = array(); if (!oev_unit_notify_have($db)) return $o;
	$st = $db->prepare('SELECT * FROM user_unit_notify WHERE user_id = ?'); $st->execute(array((int)$user_id));
	foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $o[(int)$r['unit_id']][$r['kind']] = $r;
	return $o;
}
// The account default for a kind: on?, and since when. stops: users.n_stops_since (null = not proven, nothing sent).
// sessions: users.n_sessions_since; null while on = on since before that column existed (0: no limit, as before)
function oev_notify_account($u, $kind){
	$on = !empty($u['n_' . $kind]);
	if (!$on) return array('on' => false, 'since' => null);
	if ($kind === 'stops') return array('on' => true, 'since' => empty($u['n_stops_since']) ? null : (int)$u['n_stops_since']);
	return array('on' => true, 'since' => empty($u['n_sessions_since']) ? 0 : (int)$u['n_sessions_since']);
}
// What applies for this login + unit + kind: the unit override first, then the account default.
// on, since (events before it are not mailed; null with on = never proven, nothing is mailed), src 'unit' / 'account', mode
function oev_notify_effective($u, $row, $kind){
	$m = ($row && in_array($row['mode'] ?? '', array('on', 'off'), true)) ? $row['mode'] : 'inherit';
	if ($m === 'on') return array('on' => true, 'since' => ($row['since'] === null || $row['since'] === '') ? null : (int)$row['since'], 'src' => 'unit', 'mode' => 'on');
	if ($m === 'off') return array('on' => false, 'since' => null, 'src' => 'unit', 'mode' => 'off');
	$a = oev_notify_account($u, $kind);
	$since = $a['since'];
	// switched to "account default" while on: keep that earlier start, but only with proof the account was already on
	// BEFORE the switch (strictly earlier second; the same second is ambiguous, so the account's own start is used)
	if ($a['on'] && $row && $row['since'] !== null && $row['since'] !== '' && $since !== null && $since < (int)$row['updated_at']) $since = (int)$row['since'];
	return array('on' => $a['on'], 'since' => $since, 'src' => 'account', 'mode' => 'inherit');
}
// Save one login's choice for one unit. $modes: kind => 'inherit'|'on'|'off' (checked by the caller AND here).
// Reads the login's account default fresh, in one write transaction. An unchanged choice changes nothing
// (its start time is kept); turning on again later starts from now (nothing older is mailed).
function oev_unit_notify_save($db, $user_id, $unit_id, $modes, $now = null){
	$now = $now ?? time(); $kinds = oev_unit_notify_kinds();
	foreach ($modes as $k => $m) if (!isset($kinds[$k]) || !in_array($m, array('inherit', 'on', 'off'), true)) throw new InvalidArgumentException('bad choice');
	$own = !$db->inTransaction(); if ($own) $db->exec('BEGIN IMMEDIATE');
	try {
		$st = $db->prepare('SELECT * FROM users WHERE id = ?'); $st->execute(array((int)$user_id)); $u = $st->fetch(PDO::FETCH_ASSOC);
		if (!$u) throw new RuntimeException('login removed');
		$rows = oev_unit_notify_rows($db, $user_id); $rows = $rows[(int)$unit_id] ?? array();
		$changed = 0;
		foreach ($modes as $k => $m) {
			$row = $rows[$k] ?? null;
			$cur = $row ? $row['mode'] : 'inherit';
			if ($cur === $m) continue;   // same choice: keep it as it is
			$prev = oev_notify_effective($u, $row, $k);
			$keep = ($prev['on'] && $prev['since'] !== null) ? $prev['since'] : $now;   // still on: no new start, no backlog either
			if ($m === 'inherit' && !$row) continue;
			// account default chosen while the account is off: nothing to carry (it starts with the account)
			$since = ($m === 'off' || ($m === 'inherit' && !oev_notify_account($u, $k)['on'])) ? null : $keep;
			$db->prepare('INSERT INTO user_unit_notify (user_id, unit_id, kind, mode, since, updated_at) VALUES (?, ?, ?, ?, ?, ?)
				ON CONFLICT (user_id, unit_id, kind) DO UPDATE SET mode = excluded.mode, since = excluded.since, updated_at = excluded.updated_at')
				->execute(array((int)$user_id, (int)$unit_id, $k, $m, $since, $now));
			$changed++;
		}
		if ($own) $db->exec('COMMIT');
		return $changed;
	} catch (Throwable $x) { if ($own && $db->inTransaction()) $db->exec('ROLLBACK'); throw $x; }
}
// One login's choices for the units they can see, resolved exactly as the notifier does (oev_notify_effective).
// $units: id => row (with loadgroupid), already limited to what this login may see. Returns unit id => kind =>
// array(mode, on, src, account_on). $master: the site-wide switch (Settings > Preferences); off = nothing is sent at all.
function oev_unit_notify_view($db, $u, $units){
	$rows = oev_unit_notify_rows($db, (int)$u['id']); $o = array();
	foreach ($units as $id => $x) foreach (oev_unit_notify_kinds() as $k => $t) {
		$ef = oev_notify_effective($u, $rows[(int)$id][$k] ?? null, $k); $a = oev_notify_account($u, $k);
		$o[(int)$id][$k] = array('mode' => $ef['mode'], 'on' => $ef['on'], 'src' => $ef['src'], 'account_on' => $a['on']);
	}
	return $o;
}
// a login or a unit removed: its rows go too (settings.php)
function oev_unit_notify_forget($db, $user_id = null, $unit_id = null){
	if (!oev_unit_notify_have($db)) return;
	if ($user_id !== null) $db->prepare('DELETE FROM user_unit_notify WHERE user_id = ?')->execute(array((int)$user_id));
	if ($unit_id !== null) $db->prepare('DELETE FROM user_unit_notify WHERE unit_id = ?')->execute(array((int)$unit_id));
}
