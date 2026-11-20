<?php
/******************************************************************************
 * demo.php - one-click demo login: signs a visitor in as the demo user (Settings
 * key demo_user, made by collector/demo_user.php) and goes to the home page.
 * The demo user is always view only and can't change anything (auth_lib.php).
 * Someone already logged in keeps their own login. Old demo logins are pruned.
 ******************************************************************************/
require_once __DIR__ . '/auth_lib.php';
require_once __DIR__ . '/util_lib.php';
header('Referrer-Policy: same-origin'); header('X-Frame-Options: SAMEORIGIN'); header('X-Robots-Tag: noindex, nofollow');
$u = oev_user();
if ($u && $u['role'] !== 'public') { header('Location: ./', true, 303); exit; }   // logged in already (a real login or the demo): keep it
$id = oev_demo_user_id(); $ok = false;
if ($id && !oev_cfg_maint()) { $st = oev_db()->prepare('SELECT id FROM users WHERE id = ?'); $st->execute(array($id)); $ok = (bool)$st->fetchColumn(); }
if (!$ok) oev_deny(false, 'The demo is not available right now.', 404);
$db = oev_db(); $now = time();
// keep the demo's logins few: drop ones unused for 30 days, and all but the newest 500
$db->prepare('DELETE FROM auth_tokens WHERE user_id = ? AND coalesce(last_used_at, 0) < ?')->execute(array($id, $now - 30 * 86400));
$db->prepare('DELETE FROM auth_tokens WHERE user_id = ? AND id NOT IN (SELECT id FROM auth_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 499)')->execute(array($id, $id));
list($sel, $val) = oev_new_token();
$db->prepare('INSERT INTO auth_tokens (selector, token_hash, user_id, created_at, last_used_at, agent) VALUES (?, ?, ?, ?, ?, ?)')
	->execute(array($sel, hash('sha256', $val), $id, $now, $now, substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)));
oev_set_cookie(OEV_COOKIE, "$sel.$val", OEV_COOKIE_DAYS);
oev_clear_cookie(OEV_VIEW_COOKIE);
header('Location: ./', true, 303);
