<?php
/******************************************************************************
 * Setup for the databases, Settings and logins (run as the web user, CLI only):
 *   sudo -u <web user> env OEV_ADMIN_EMAIL=you@example.com php setup_auth.php  < first-admin-password-on-stdin
 * - a new install: makes openevse.db (units, load groups, ...) and sessions.db
 *   (optional first argument: an old email.php to copy SMTP settings from)
 * - new tables in openevse.db: settings, users, user_groups, auth_tokens,
 *   login_fails, public_links, jobs, reminders; new evses columns for Emoncms
 * - encrypts the units' plain-text passwords (secret_lib.php)
 * - copies the old email.php SMTP settings into Settings (password encrypted)
 * - copies the old per-unit plug-in reminders (turned off)
 * - makes the first admin login (OEV_ADMIN_EMAIL) if there is no user yet
 * Safe to run again (setup.sh runs it with OEV_SKIP_ADMIN=1). Prints no secrets.
 ******************************************************************************/
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$site = dirname(__DIR__);
require $site . '/settings_lib.php';
$db = new PDO('sqlite:' . $site . '/openevse.db', null, null, array(PDO::ATTR_TIMEOUT => 10, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
if (!oev_key()) { fwrite(STDERR, "key file missing: " . OEV_KEY_FILE . "\n"); exit(1); }
// units and load groups (a new install starts with none; add them in Settings)
$db->exec("CREATE TABLE IF NOT EXISTS load_groups (id INTEGER PRIMARY KEY NOT NULL, name TEXT, max_load INTEGER DEFAULT 50);
CREATE TABLE IF NOT EXISTS evses (id INTEGER PRIMARY KEY NOT NULL, name TEXT, evseurl TEXT, breaker INTEGER DEFAULT 40, username TEXT, password TEXT,
	notifybytime DATETIME, notifybyemail TEXT, enabled BOOLEAN DEFAULT 1, loadgroupid INTEGER);
CREATE TABLE IF NOT EXISTS notifications_sent (id INTEGER PRIMARY KEY NOT NULL, evse_id INTEGER, sent DATETIME DEFAULT CURRENT_TIMESTAMP, type TEXT);");
$db->exec("CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT);
CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE COLLATE NOCASE, pass_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'viewer',
	scope_all INTEGER NOT NULL DEFAULT 1, n_errors INTEGER DEFAULT 0, n_reboots INTEGER DEFAULT 0, n_sessions INTEGER DEFAULT 0, n_reminders INTEGER DEFAULT 0,
	created_at INTEGER, last_login_at INTEGER);
CREATE TABLE IF NOT EXISTS user_groups (user_id INTEGER NOT NULL, group_id INTEGER NOT NULL, PRIMARY KEY (user_id, group_id));
CREATE TABLE IF NOT EXISTS auth_tokens (id INTEGER PRIMARY KEY, selector TEXT NOT NULL UNIQUE, token_hash TEXT NOT NULL, user_id INTEGER NOT NULL, created_at INTEGER, last_used_at INTEGER, agent TEXT);
CREATE TABLE IF NOT EXISTS login_fails (ip TEXT, email TEXT, ts INTEGER);
CREATE TABLE IF NOT EXISTS public_links (id INTEGER PRIMARY KEY, selector TEXT NOT NULL UNIQUE, token_hash TEXT NOT NULL, token_enc TEXT, label TEXT, created_at INTEGER, created_by INTEGER, revoked_at INTEGER, last_used_at INTEGER);
CREATE TABLE IF NOT EXISTS jobs (id INTEGER PRIMARY KEY, kind TEXT NOT NULL, unit_id INTEGER, arg TEXT, created_at INTEGER, created_by INTEGER, status TEXT DEFAULT 'queued', msg TEXT, done_at INTEGER);
CREATE TABLE IF NOT EXISTS reminders (id INTEGER PRIMARY KEY, unit_id INTEGER NOT NULL, at_time TEXT NOT NULL, extra_to TEXT, enabled INTEGER DEFAULT 1);");
$cols = array_column($db->query('PRAGMA table_info(evses)')->fetchAll(), 'name');
foreach (array('volts_override' => 'REAL', 'voltmeter' => 'INTEGER', 'emon_mode' => 'TEXT', 'emon_ext_server' => 'TEXT', 'emon_ext_node' => 'TEXT', 'emon_ext_key' => 'TEXT', 'hw_max' => 'INTEGER') as $c => $t) if (!in_array($c, $cols)) { $db->exec("ALTER TABLE evses ADD COLUMN $c $t"); echo "evses.$c added\n"; }
$cols = array_column($db->query('PRAGMA table_info(users)')->fetchAll(), 'name');
foreach (array('first_name', 'last_name') as $c) if (!in_array($c, $cols)) { $db->exec("ALTER TABLE users ADD COLUMN $c TEXT DEFAULT ''"); echo "users.$c added\n"; }
// sessions.db (readings and sessions): made or brought up to date here (sessions_lib.php)
require_once $site . '/sessions_lib.php';
$sdb = sess_db();
// Emoncms mode from the receiver's key table
$push = $sdb->query('SELECT unit_id FROM emon_nodes WHERE key_hash IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
foreach ($db->query('SELECT id FROM evses WHERE emon_mode IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $id) { $m = in_array($id, $push) ? 'internal' : 'none'; $db->prepare('UPDATE evses SET emon_mode = ? WHERE id = ?')->execute(array($m, $id)); echo "unit $id emoncms: $m\n"; }
// encrypt unit passwords
$n = 0;
foreach ($db->query('SELECT id, password FROM evses')->fetchAll() as $r) {
	if ($r['password'] === null || $r['password'] === '' || oev_is_enc($r['password'])) continue;
	$e = oev_enc($r['password']);
	if (oev_dec($e) !== $r['password']) { fwrite(STDERR, "round trip failed for unit {$r['id']}\n"); exit(1); }
	$db->prepare('UPDATE evses SET password = ? WHERE id = ?')->execute(array($e, $r['id'])); $n++;
}
echo "unit passwords encrypted: $n\n";
// SMTP from the old email.php (its backup copy)
$old = $argv[1] ?? '';
$have = (int)$db->query("SELECT count(*) FROM settings WHERE k = 'smtp_host'")->fetchColumn();
if (!$have && $old && is_readable($old)) {
	$t = file_get_contents($old); $g = function($re) use ($t){ return preg_match($re, $t, $m) ? $m[1] : ''; };
	$kv = array('smtp_host' => $g('/->Host\s*=\s*\'([^\']*)\'/'), 'smtp_user' => $g('/->Username\s*=\s*\'([^\']*)\'/'), 'smtp_port' => $g('/->Port\s*=\s*(\d+)/'),
		'smtp_secure' => $g('/->SMTPSecure\s*=\s*\'([^\']*)\'/') ?: 'tls', 'smtp_from' => $g('/setFrom\(\s*\'([^\']*)\'/'), 'smtp_from_name' => $g('/setFrom\(\s*\'[^\']*\'\s*,\s*\'([^\']*)\'/'), 'notify_on' => '0');
	$p = $g('/->Password\s*=\s*\'([^\']*)\'/'); $kv['smtp_pass'] = $p !== '' ? oev_enc($p) : ''; unset($p, $t);
	$st = $db->prepare('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)'); foreach ($kv as $k => $v) $st->execute(array($k, $v));
	echo "SMTP settings copied from the old email.php: server {$kv['smtp_host']}, port {$kv['smtp_port']}, {$kv['smtp_secure']}, from {$kv['smtp_from']}, password " . ($kv['smtp_pass'] !== '' ? 'stored encrypted' : 'none') . "\n";
}
// old reminders (columns notifybytime / notifybyemail), copied turned off
if (!(int)$db->query('SELECT count(*) FROM reminders')->fetchColumn())
	foreach ($db->query("SELECT id, notifybytime, notifybyemail FROM evses WHERE coalesce(notifybytime, '') != ''")->fetchAll() as $r) {
		$db->prepare('INSERT INTO reminders (unit_id, at_time, extra_to, enabled) VALUES (?, ?, ?, 0)')->execute(array($r['id'], $r['notifybytime'], (string)$r['notifybyemail']));
		echo "reminder for unit {$r['id']} at {$r['notifybytime']} copied (off)\n";
	}
// first admin
if (!(int)$db->query('SELECT count(*) FROM users')->fetchColumn()) {
	$ae = strtolower(trim((string)getenv('OEV_ADMIN_EMAIL')));
	if ($ae === '' && getenv('OEV_SKIP_ADMIN')) { echo "no admin login yet (run this again with OEV_ADMIN_EMAIL set)\ndone\n"; exit(0); }
	if (!filter_var($ae, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "set OEV_ADMIN_EMAIL to the first admin's email address\n"); exit(1); }
	$pw = rtrim((string)stream_get_contents(STDIN), "\r\n");
	if (strlen($pw) < 8) { fwrite(STDERR, "the first password (on stdin) must be 8 characters or more\n"); exit(1); }
	$db->prepare("INSERT INTO users (email, pass_hash, role, scope_all, n_errors, n_reminders, created_at) VALUES (?, ?, 'admin', 1, 1, 0, ?)")->execute(array($ae, password_hash($pw, PASSWORD_DEFAULT), time()));
	unset($pw); echo "admin login made: $ae\n";
}
echo "done\n";
