<?php
/******************************************************************************
 * The demo login (run as the web user, CLI only):
 *   sudo -u <web user> php demo_user.php on | off | status
 * on:     makes (or resets) the demo user demo@demo.invalid: view only, all units,
 *         no emails, no reminders or per-unit overrides, a random password nobody
 *         knows (never printed), then turns on demo.php (Settings key demo_user).
 * off:    turns demo.php off and logs out every demo visitor (the row is kept).
 * status: prints whether it is on and how many demo logins exist.
 * Prints no secrets.
 ******************************************************************************/
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$site = dirname(__DIR__);
if (is_file($site . '/data/cfg_db_maint')) { fwrite(STDERR, "config DB maintenance in progress\n"); exit(1); }
require $site . '/settings_lib.php';
define('OEV_DEMO_EMAIL', 'demo@demo.invalid');
$cmd = $argv[1] ?? 'status';
$db = oev_cfg_db_open(array(PDO::ATTR_TIMEOUT => 10, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
$tbl = function($t) use ($db){ return (bool)$db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = " . $db->quote($t))->fetchColumn(); };
$st = $db->prepare('SELECT id FROM users WHERE email = ? COLLATE NOCASE'); $st->execute(array(OEV_DEMO_EMAIL)); $id = (int)$st->fetchColumn(); $st->closeCursor();
$save = function($v) use ($db){ $db->prepare("INSERT OR REPLACE INTO settings (k, v) VALUES ('demo_user', ?)")->execute(array((string)$v)); };
if ($cmd === 'on') {
	$db->beginTransaction();
	$pw = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
	$cols = array_column($db->query('PRAGMA table_info(users)')->fetchAll(), 'name');
	if (!$id) { $db->prepare("INSERT INTO users (email, pass_hash, role, scope_all, created_at) VALUES (?, ?, 'viewer', 1, ?)")->execute(array(OEV_DEMO_EMAIL, $pw, time())); $id = (int)$db->lastInsertId(); echo "demo user made (id $id)\n"; }
	else { $db->prepare("UPDATE users SET pass_hash = ?, role = 'viewer', scope_all = 1 WHERE id = ?")->execute(array($pw, $id)); echo "demo user reset (id $id)\n"; }
	$set = array(); foreach (array('n_errors', 'n_reboots', 'n_sessions', 'n_reminders', 'n_stops') as $c) if (in_array($c, $cols, true)) $set[] = "$c = 0";
	foreach (array('n_stops_since', 'n_sessions_since') as $c) if (in_array($c, $cols, true)) $set[] = "$c = NULL";
	if (in_array('first_name', $cols, true)) $set[] = "first_name = 'Demo'";
	if (in_array('last_name', $cols, true)) $set[] = "last_name = 'Visitor'";
	if ($set) $db->prepare('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?')->execute(array($id));
	$db->prepare('DELETE FROM user_groups WHERE user_id = ?')->execute(array($id));
	if ($tbl('user_unit_notify')) $db->prepare('DELETE FROM user_unit_notify WHERE user_id = ?')->execute(array($id));
	if ($tbl('reminders') && in_array('user_id', array_column($db->query('PRAGMA table_info(reminders)')->fetchAll(), 'name'), true)) $db->prepare('DELETE FROM reminders WHERE user_id = ?')->execute(array($id));
	$save($id);
	$db->commit();
	echo "demo login on: demo.php\n";
} elseif ($cmd === 'off') {
	if ($id) $db->prepare('DELETE FROM auth_tokens WHERE user_id = ?')->execute(array($id));
	$save('');
	echo "demo login off\n";
} elseif ($cmd === 'status') {
	$on = ctype_digit((string)oev_setting('demo_user', '')) && (int)oev_setting('demo_user', '') === $id && $id;
	$n = 0; if ($id) { $st = $db->prepare('SELECT count(*) FROM auth_tokens WHERE user_id = ?'); $st->execute(array($id)); $n = (int)$st->fetchColumn(); }
	echo ($on ? "demo login on (user id $id)" : 'demo login off' . ($id ? " (user id $id kept)" : '')) . ", $n demo login(s)\n";
} else { fwrite(STDERR, "usage: php demo_user.php on | off | status\n"); exit(2); }
