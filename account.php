<?php
// My account: change password, log out (this device or everywhere).
require_once __DIR__ . '/auth_lib.php';
$me = oev_require('view');
require_once __DIR__ . '/util_lib.php';   // h(), ago(), oev_sess_ro()
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	oev_csrf_check();
	$a = $_POST['a'] ?? '';
	if ($a === 'logout' || $a === 'logout_all') { oev_logout($a === 'logout_all'); header('Location: login.php', true, 303); exit; }
	if ($a === 'name') {
		$fn = trim(substr((string)($_POST['first_name'] ?? ''), 0, 60)); $ln = trim(substr((string)($_POST['last_name'] ?? ''), 0, 60));
		oev_db()->prepare('UPDATE users SET first_name = ?, last_name = ? WHERE id = ?')->execute(array($fn, $ln, $me['id']));
		header('Location: account.php?saved=1', true, 303); exit;
	}
	if ($a === 'password') {
		$st = oev_db()->prepare('SELECT pass_hash FROM users WHERE id = ?'); $st->execute(array($me['id'])); $hash = $st->fetchColumn();
		$new = (string)($_POST['new'] ?? '');
		if (!password_verify((string)($_POST['current'] ?? ''), $hash)) $err = 'Your current password is not right.';
		elseif ($new !== (string)($_POST['new2'] ?? '')) $err = 'The two new passwords are not the same.';
		elseif ($p = oev_password_problem($new)) $err = $p;
		else {
			oev_db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute(array(password_hash($new, PASSWORD_DEFAULT), $me['id']));
			oev_db()->prepare('DELETE FROM auth_tokens WHERE user_id = ? AND id != ?')->execute(array($me['id'], $me['token_id']));   // other devices log in again
			$msg = 'Password changed. Other devices will need to log in again.';
		}
	}
}
if (isset($_GET['saved'])) $msg = 'Name saved.';
$title = 'Account'; $wq = '';
include __DIR__ . '/_head.php';
?>
<style>
.ac{font-size:16px;line-height:1.45;color:#444;text-align:left}
.ac .card{background:#fff;border:1px solid #ccc;border-radius:8px;padding:8px 10px;margin:8px 0}
.ac label{display:block;margin:8px 0 2px 0;color:#333}
.ac input[type=password],.ac input[type=text]{width:100%;box-sizing:border-box;font-size:16px;padding:5px 6px;border:1px solid #bbb;border-radius:4px}
.ac button{font-size:16px;padding:5px 14px;margin:10px 8px 2px 0;border:1px solid #888;border-radius:5px;background:#f6f6f6;cursor:pointer}
.ac button.go{background:#00706f;border-color:#00706f;color:#fff}
.ac .msg{background:#e3f4e3;border:1px solid #9c9;border-radius:6px;padding:6px 9px;color:#245a24}
.ac .err{background:#fde3e3;border:1px solid #e99;border-radius:6px;padding:6px 9px;color:#a11;font-weight:normal}
</style>
<div class="slidebox ac">
<?php if ($msg) echo '<div class="msg">' . h($msg) . '</div>'; if ($err) echo '<div class="err">' . h($err) . '</div>'; ?>
<div class="card"><b><?=h(oev_user_name($me))?></b><br><?=oev_v($me['email'])?><br><?=$me['role'] === 'admin' ? 'Admin: can view and change everything.' : 'View only.'?> <?=($me['role'] === 'admin' || $me['scope_all']) ? 'Sees all load groups.' : 'Sees some load groups.'?></div>
<div class="card"><form method="post" action="account.php"><?=oev_csrf_field()?><input type="hidden" name="a" value="name">
<b>Your name</b>
<div style="display:flex;gap:8px"><div style="flex:1;min-width:0"><label>First name</label><input type="text" name="first_name" value="<?=h($me['first_name'] ?? '')?>" maxlength="60" autocomplete="given-name"></div>
<div style="flex:1;min-width:0"><label>Last name</label><input type="text" name="last_name" value="<?=h($me['last_name'] ?? '')?>" maxlength="60" autocomplete="family-name"></div></div>
<div style="color:#666;margin-top:4px">Your initials show in the circle at the top right.</div>
<button type="submit" class="go">Save name</button></form></div>
<div class="card"><form method="post" action="account.php"><?=oev_csrf_field()?><input type="hidden" name="a" value="password">
<b>Change password</b>
<label>Current password</label><input type="password" name="current" autocomplete="current-password" required>
<label>New password</label><input type="password" name="new" autocomplete="new-password" required>
<label>New password again</label><input type="password" name="new2" autocomplete="new-password" required>
<button type="submit" class="go">Change password</button></form></div>
<div class="card"><form method="post" action="account.php"><?=oev_csrf_field()?>
<button type="submit" name="a" value="logout">Log out</button><button type="submit" name="a" value="logout_all">Log out on all devices</button></form></div>
<p><a href="./">&larr; Home</a><?=oev_is_admin() ? ' &middot; <a href="settings.php">Settings</a>' : ''?> &middot; <a href="help.php">Help</a></p>
</div></div></body></html>
