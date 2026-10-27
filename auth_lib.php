<?php
/******************************************************************************
 * auth_lib.php - logins for the OpenEVSE pages.
 *  - Users (openevse.db `users`): email, salted password hash (password_hash),
 *    role admin (can change things) or viewer (read only), scope: all units or
 *    only the units in chosen load groups (`user_groups`).
 *  - Logins never expire: a random cookie (selector:validator, only a sha256 of
 *    the validator is stored in `auth_tokens`), HttpOnly, SameSite=Lax, Secure on
 *    HTTPS, 10 years. "Log out" deletes it.
 *  - Public read-only links (`public_links`): a random token in the URL gives
 *    view-only access to the home page, unit details and load balancer status.
 *  - CSRF: every POST form carries a token matching a cookie (oev_csrf_field()).
 * Pages call oev_require('public' | 'view' | 'admin') before any output.
 * Command-line scripts (collector, load balancer, tools) are never gated.
 ******************************************************************************/
require_once __DIR__ . '/settings_lib.php';
define('OEV_COOKIE', 'oev_auth');
define('OEV_VIEW_COOKIE', 'oev_view');
define('OEV_CSRF_COOKIE', 'oev_csrf');
define('OEV_COOKIE_DAYS', 3650);

function oev_db(){
	static $db = null;
	if ($db === null) $db = new PDO('sqlite:' . OEV_DB_FILE, null, null, array(PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
	return $db;
}
function oev_https(){ return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (($_SERVER['SERVER_PORT'] ?? '') == 443); }
function oev_cookie_path(){ $d = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/x.php')), '/'); return ($d === '' ? '' : $d) . '/'; }
function oev_set_cookie($name, $value, $days){
	setcookie($name, $value, array('expires' => $days ? time() + $days * 86400 : 0, 'path' => oev_cookie_path(), 'secure' => oev_https(), 'httponly' => true, 'samesite' => 'Lax'));
	$_COOKIE[$name] = $value;
}
function oev_clear_cookie($name){ setcookie($name, '', array('expires' => time() - 3600, 'path' => oev_cookie_path(), 'secure' => oev_https(), 'httponly' => true, 'samesite' => 'Lax')); unset($_COOKIE[$name]); }
function oev_split_token($v){ return (is_string($v) && preg_match('/^([a-f0-9]{16})\.([a-f0-9]{48})$/', $v, $m)) ? array($m[1], $m[2]) : null; }
function oev_new_token(){ return array(bin2hex(random_bytes(8)), bin2hex(random_bytes(24))); }

/* ---------- who is asking ---------- */
// the logged-in user (array with id, email, role, scope_all, groups[]), a public-link pseudo user (role 'public'), or null
function oev_user(){
	if (array_key_exists('OEV_USER', $GLOBALS)) return $GLOBALS['OEV_USER'];
	$GLOBALS['OEV_USER'] = null;
	try {
		$t = oev_split_token($_COOKIE[OEV_COOKIE] ?? '');
		if ($t) {
			$st = oev_db()->prepare('SELECT t.id tid, t.token_hash, t.last_used_at, u.* FROM auth_tokens t JOIN users u ON u.id = t.user_id WHERE t.selector = ?');
			$st->execute(array($t[0])); $r = $st->fetch();
			if ($r && hash_equals($r['token_hash'], hash('sha256', $t[1]))) {
				if (time() - (int)$r['last_used_at'] > 3600) oev_db()->prepare('UPDATE auth_tokens SET last_used_at = ? WHERE id = ?')->execute(array(time(), $r['tid']));
				unset($r['token_hash'], $r['pass_hash']);
				$r['groups'] = oev_user_groups((int)$r['id']);
				$r['token_id'] = (int)$r['tid'];
				return $GLOBALS['OEV_USER'] = $r;
			}
		}
		$p = oev_public_check($_COOKIE[OEV_VIEW_COOKIE] ?? '');
		if ($p) return $GLOBALS['OEV_USER'] = array('id' => 0, 'email' => '', 'role' => 'public', 'scope_all' => 1, 'groups' => array(), 'link_id' => (int)$p['id']);
	} catch (Exception $e) { /* tables missing: nobody */ }
	return null;
}
function oev_user_groups($uid){ $st = oev_db()->prepare('SELECT group_id FROM user_groups WHERE user_id = ?'); $st->execute(array($uid)); return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)); }
function oev_is_admin(){ $u = oev_user(); return $u && $u['role'] === 'admin'; }
function oev_can_control(){ return PHP_SAPI === 'cli' || oev_is_admin(); }
function oev_is_public(){ $u = oev_user(); return $u && $u['role'] === 'public'; }

/* ---------- scope: which units a user may see ---------- */
// null = everything; otherwise the list of load group ids (units with no group are hidden)
function oev_scope_groups(){
	if (PHP_SAPI === 'cli') return null;
	$u = oev_user();
	if (!$u) return array();
	return ($u['role'] === 'admin' || !empty($u['scope_all'])) ? null : $u['groups'];   // admins always see everything
}
function oev_unit_visible($unit){
	$g = oev_scope_groups();
	if ($g === null) return true;
	$gid = (int)($unit['loadgroupid'] ?? 0);
	return $gid && in_array($gid, $g, true);
}
function oev_group_visible($gid){ $g = oev_scope_groups(); return $g === null || in_array((int)$gid, $g, true); }
// unit ids visible to this user (null = all), from openevse.db (for pages that don't load $evses)
function oev_visible_unit_ids(){
	$g = oev_scope_groups(); if ($g === null) return null;
	if (!$g) return array();
	return array_map('intval', oev_db()->query('SELECT id FROM evses WHERE loadgroupid IN (' . implode(',', array_map('intval', $g)) . ')')->fetchAll(PDO::FETCH_COLUMN));
}
// the load balancer heartbeat with only the groups / units this user may see
function oev_filter_hb($hb){
	if (!is_array($hb) || oev_scope_groups() === null) return $hb;
	$ids = oev_visible_unit_ids();
	$hb['groups'] = array_values(array_filter((array)$hb['groups'], function($g){ return oev_group_visible($g['id']); }));
	$hb['monitor'] = array_values(array_filter((array)($hb['monitor'] ?? array()), function($u) use ($ids){ return in_array((int)$u['id'], $ids, true); }));
	return $hb;
}

/* ---------- the gate ---------- */
// 'public': logged in, or a valid public link. 'view': logged in. 'admin': admin users.
function oev_require($need = 'view', $json = false){
	if (PHP_SAPI === 'cli') return null;
	header('Referrer-Policy: same-origin');
	header('X-Frame-Options: SAMEORIGIN');
	// a public link: ?view=<token> sets the view cookie, then the token leaves the address bar
	if (isset($_GET['view']) && !isset($_COOKIE[OEV_COOKIE])) {
		$p = oev_public_check((string)$_GET['view']);
		if ($p) {
			oev_set_cookie(OEV_VIEW_COOKIE, (string)$_GET['view'], OEV_COOKIE_DAYS);
			$q = $_GET; unset($q['view']);
			header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . ($q ? '?' . http_build_query($q) : ''), true, 303); exit;
		}
		oev_deny($json, 'This view-only link is not valid any more.');
	}
	$u = oev_user();
	if ($u && $u['role'] !== 'public') oev_csrf_token();   // cookie set before output (the avatar menu's Log out form)
	$ok = $u && ($need === 'public' || ($need === 'view' && $u['role'] !== 'public') || ($need === 'admin' && $u['role'] === 'admin'));
	if ($ok) return $u;
	if ($u && $u['role'] !== 'public') oev_deny($json, 'Your login can only view. Ask an admin if you need to change settings.', 403);
	if ($json) oev_deny(true, 'Please log in.', 401);
	header('Location: login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? ''), true, 303); exit;
}
function oev_deny($json, $msg, $code = 403){
	http_response_code($code);
	if ($json) { header('Content-Type: application/json'); echo json_encode(array('ok' => false, 'error' => $msg)); exit; }
	require_once __DIR__ . '/util_lib.php';
	$title = ''; $wq = '';
	include __DIR__ . '/_head.php';
	echo '<div class="slidebox" style="text-align:left;font-size:16px;line-height:1.5"><p>' . h($msg) . '</p><p><a href="./">Home</a> &middot; ' . (oev_user() && !oev_is_public() ? '<a href="account.php">My account</a>' : '<a href="login.php">Log in</a>') . '</p></div></div></body></html>';
	exit;
}

/* ---------- CSRF (double submit: cookie + form field) ---------- */
function oev_csrf_token(){
	$t = $_COOKIE[OEV_CSRF_COOKIE] ?? '';
	if (!preg_match('/^[a-f0-9]{32}$/', $t)) { $t = bin2hex(random_bytes(16)); if (!headers_sent()) oev_set_cookie(OEV_CSRF_COOKIE, $t, OEV_COOKIE_DAYS); }
	return $t;
}
function oev_csrf_field(){ return '<input type="hidden" name="csrf" value="' . htmlspecialchars(oev_csrf_token(), ENT_QUOTES) . '">'; }
function oev_csrf_ok(){ $c = $_COOKIE[OEV_CSRF_COOKIE] ?? ''; $f = $_POST['csrf'] ?? ''; return is_string($f) && preg_match('/^[a-f0-9]{32}$/', $c) && hash_equals($c, $f); }
function oev_csrf_check(){ if ($_SERVER['REQUEST_METHOD'] === 'POST' && !oev_csrf_ok()) { http_response_code(400); die('This form has expired. Go back, reload the page and try again.'); } }

/* ---------- log in / out ---------- */
function oev_login($email, $pass){
	$db = oev_db(); $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
	$db->prepare('DELETE FROM login_fails WHERE ts < ?')->execute(array(time() - 900));
	$st = $db->prepare('SELECT count(*) FROM login_fails WHERE ip = ? OR email = ?'); $st->execute(array($ip, strtolower($email)));
	if ((int)$st->fetchColumn() >= 10) return 'Too many tries. Wait 15 minutes and try again.';
	$st = $db->prepare('SELECT * FROM users WHERE email = ? COLLATE NOCASE'); $st->execute(array(trim($email))); $u = $st->fetch();
	if (!$u || !password_verify($pass, $u['pass_hash'])) {
		$db->prepare('INSERT INTO login_fails (ip, email, ts) VALUES (?, ?, ?)')->execute(array($ip, strtolower($email), time()));
		usleep(700000);
		return 'Wrong email or password.';
	}
	if (password_needs_rehash($u['pass_hash'], PASSWORD_DEFAULT)) $db->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute(array(password_hash($pass, PASSWORD_DEFAULT), $u['id']));
	list($sel, $val) = oev_new_token();
	$db->prepare('INSERT INTO auth_tokens (selector, token_hash, user_id, created_at, last_used_at, agent) VALUES (?, ?, ?, ?, ?, ?)')
		->execute(array($sel, hash('sha256', $val), $u['id'], time(), time(), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)));
	$db->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute(array(time(), $u['id']));
	oev_set_cookie(OEV_COOKIE, "$sel.$val", OEV_COOKIE_DAYS);
	oev_clear_cookie(OEV_VIEW_COOKIE);
	unset($GLOBALS['OEV_USER']);
	return true;
}
function oev_logout($everywhere = false){
	$u = oev_user();
	if ($u && $u['role'] !== 'public') {
		if ($everywhere) oev_db()->prepare('DELETE FROM auth_tokens WHERE user_id = ?')->execute(array($u['id']));
		else oev_db()->prepare('DELETE FROM auth_tokens WHERE id = ?')->execute(array($u['token_id']));
	}
	oev_clear_cookie(OEV_COOKIE); oev_clear_cookie(OEV_VIEW_COOKIE);
	unset($GLOBALS['OEV_USER']);
}
function oev_password_problem($p){
	if (strlen($p) < 8) return 'Use at least 8 characters.';
	if (strlen($p) > 200) return 'That password is too long.';
	return '';
}

/* ---------- public read-only links ---------- */
function oev_public_check($v){
	$t = oev_split_token($v); if (!$t) return null;
	$st = oev_db()->prepare('SELECT * FROM public_links WHERE selector = ? AND revoked_at IS NULL'); $st->execute(array($t[0])); $r = $st->fetch();
	if (!$r || !hash_equals($r['token_hash'], hash('sha256', $t[1]))) return null;
	if (time() - (int)$r['last_used_at'] > 3600) oev_db()->prepare('UPDATE public_links SET last_used_at = ? WHERE id = ?')->execute(array(time(), $r['id']));
	return $r;
}
// oev_site_url(): settings_lib.php (Settings > Preferences > Site web address, or detected)

/* ---------- small helpers for pages ---------- */
// a value read from a real source (database, unit, server, settings, code constants) inside
// explanatory text: shown in black (class "v", _head.php) so it stands out from fixed wording
function oev_v($x, $html = false){ return '<span class="v">' . ($html ? (string)$x : htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8')) . '</span>'; }
// NEC 240.6(A) standard breaker sizes; an EVSE is a continuous load, so its breaker is at least 125% of its current
function oev_min_breaker($a){ $need = (int)$a * 1.25; foreach (array(15, 20, 25, 30, 35, 40, 45, 50, 60, 70, 80, 90, 100, 110, 125, 150, 175, 200) as $b) if ($b >= $need - 1e-9) return $b; return (int)ceil($need); }
function oev_user_name($u){ $n = trim(trim((string)($u['first_name'] ?? '')) . ' ' . trim((string)($u['last_name'] ?? ''))); return $n !== '' ? $n : (string)($u['email'] ?? ''); }
function oev_first_char($s){ return preg_match('/\p{L}|\p{N}/u', (string)$s, $m) ? $m[0] : ''; }
function oev_initials($u){
	$i = oev_first_char($u['first_name'] ?? '') . oev_first_char($u['last_name'] ?? '');
	if ($i === '') $i = oev_first_char($u['email'] ?? '?');
	return strtoupper($i);   // ASCII letters; others stay as they are (no mbstring on the web PHP)
}
// top-right avatar (teal circle with initials) and its menu; a "Log in" button for view-only links
function oev_userbar(){
	$u = oev_user(); if (!$u) return '';
	$e = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
	if ($u['role'] === 'public') return '<div class="av"><a class="avlogin" href="login.php" title="You are using a view-only link">Log in</a></div>';
	$adm = $u['role'] === 'admin'; $sys = ''; $p = array();
	if ($adm) {
		require_once __DIR__ . '/sys_lib.php';
		$p = array(); try { $p = oev_sys_problems(); } catch (Throwable $x) {}
		$sys = '<a href="settings.php?s=system">System status <span class="' . ($p ? 'avbad">' . count($p) . ' problem' . (count($p) > 1 ? 's' : '') : 'avok">OK') . '</span></a>';
	}
	$o = '<div class="av"><button type="button" class="avb" aria-haspopup="true" aria-expanded="false" title="' . $e(oev_user_name($u)) . '" onclick="oevAv(event)"><span class="avc">' . $e(oev_initials($u)) . '</span></button>'
		. (!empty($p) ? '<span class="avdot" title="System problem: see System status"></span>' : '')
		. '<div class="avm" hidden><div class="avn">' . $e(oev_user_name($u)) . '<span>' . $e($u['email']) . '<br>' . ($adm ? 'Admin' : 'View only') . '</span></div>'
		. '<a href="./">Home</a><a href="account.php">Account</a><a href="help.php">Help</a>' . ($adm ? '<a href="settings.php">Settings</a>' . $sys : '')
		. '<form method="post" action="account.php">' . oev_csrf_field() . '<button type="submit" name="a" value="logout">Log out</button></form></div></div>'
		. '<script>function oevAv(ev){ ev.stopPropagation(); var b = ev.currentTarget, m = b.parentNode.querySelector(".avm"); m.hidden = !m.hidden; b.setAttribute("aria-expanded", m.hidden ? "false" : "true"); }'
		. 'document.addEventListener("click", function(ev){ document.querySelectorAll(".av .avm").forEach(function(m){ if (!m.hidden && !m.contains(ev.target)) { m.hidden = true; m.parentNode.querySelector(".avb").setAttribute("aria-expanded", "false"); } }); });'
		. 'document.addEventListener("keydown", function(ev){ if (ev.key === "Escape") document.querySelectorAll(".av .avm").forEach(function(m){ m.hidden = true; }); });</script>';
	return $o;
}
