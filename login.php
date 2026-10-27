<?php
// Log in to the OpenEVSE pages. Logins never expire on a device until "Log out".
require_once __DIR__ . '/auth_lib.php';
require_once __DIR__ . '/util_lib.php';   // h(), ago(), oev_sess_ro()
header('Referrer-Policy: same-origin'); header('X-Frame-Options: SAMEORIGIN');
oev_csrf_token();   // the form's CSRF cookie, set before any output (works without PHP output buffering)
$next = (string)($_POST['next'] ?? $_GET['next'] ?? '');
if (!preg_match('#^/[A-Za-z0-9/_.\-]*(\?[^\s]*)?$#', $next) || strpos($next, '//') === 0 || strpos($next, 'login.php') !== false) $next = './';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!oev_csrf_ok()) $err = 'The page had expired. Please try again.';
	else { $r = oev_login((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? '')); if ($r === true) { header('Location: ' . $next, true, 303); exit; } $err = $r; }
}
$u = oev_user();
$title = 'Log in'; $wq = '';
$GLOBALS['OEV_NOBAR'] = 1;
include __DIR__ . '/_head.php';
?>
<style>
.lg{font-size:16px;line-height:1.45;color:#444;text-align:left}
.lg label{display:block;margin:10px 0 3px 0;color:#333}
.lg input[type=email],.lg input[type=password]{width:100%;box-sizing:border-box;font-size:16px;padding:7px 8px;border:1px solid #bbb;border-radius:4px}
.lg button{font-size:17px;padding:7px 22px;margin:14px 0 4px 0;border:1px solid #00706f;border-radius:5px;background:#00706f;color:#fff;cursor:pointer}
.lg .err{background:#fde3e3;border:1px solid #e99;border-radius:6px;padding:6px 9px;color:#a11;font-weight:normal}
.lg .note{color:#666;margin-top:10px}
</style>
<div class="slidebox lg">
<?php if ($u && $u['role'] !== 'public') { ?>
<p>You are logged in as <?=h($u['email'])?>.</p><p><a href="./">Go to the home page</a> &middot; <a href="account.php">Account</a></p>
<?php } else { ?>
<?php if ($err) echo '<div class="err">' . h($err) . '</div>'; ?>
<form method="post" action="login.php"><?=oev_csrf_field()?><input type="hidden" name="next" value="<?=h($next)?>">
<label>Email</label><input type="email" name="email" value="<?=h($_POST['email'] ?? '')?>" autocomplete="username" autocapitalize="off" required autofocus>
<label>Password</label><input type="password" name="password" autocomplete="current-password" required>
<button type="submit">Log in</button>
</form>
<div class="note">You stay logged in on this device until you log out.<?=$u ? ' You are using a view-only link now.' : ''?></div>
<?php } ?>
</div></div></body></html>
