<?php
/******************************************************************************
 * account.php - My account: name, password, and Email notifications (draft host;
 * page may be renamed/moved to Preferences without changing the storage).
 * Self-service only: never admin privilege. Public links refused.
 ******************************************************************************/
require_once __DIR__ . '/auth_lib.php';
$me = oev_require('view');
require_once __DIR__ . '/util_lib.php';
define('UNP_LIB_ONLY', 1);
require_once __DIR__ . '/notify_prefs.php';

$msg = ''; $err = ''; $db = oev_db();
$go = function($m, $hash = 'notify'){ header('Location: account.php?msg=' . rawurlencode($m) . '#' . $hash, true, 303); exit; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	oev_csrf_check();
	$a = $_POST['a'] ?? '';
	if ($a === 'logout' || $a === 'logout_all') { oev_logout($a === 'logout_all'); header('Location: login.php', true, 303); exit; }
	if ($a === 'name') {
		$fn = trim(substr((string)($_POST['first_name'] ?? ''), 0, 60)); $ln = trim(substr((string)($_POST['last_name'] ?? ''), 0, 60));
		$em = strtolower(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));
		if (strlen($em) > 254 || filter_var($em, FILTER_VALIDATE_EMAIL) !== $em) $err = 'Enter a valid email address.';
		else {
			$st = $db->prepare('SELECT count(*) FROM users WHERE email = ? COLLATE NOCASE AND id != ?'); $st->execute(array($em, $me['id']));
			if ((int)$st->fetchColumn()) $err = 'That email address already has a login.';
			else {
				try { $db->prepare('UPDATE users SET first_name = ?, last_name = ?, email = ? WHERE id = ?')->execute(array($fn, $ln, $em, $me['id'])); }
				catch (PDOException $e) { $err = 'That email address already has a login.'; }
				if ($err === '') { header('Location: account.php?saved=1', true, 303); exit; }
			}
		}
	}
	if ($a === 'password') {
		$st = $db->prepare('SELECT pass_hash FROM users WHERE id = ?'); $st->execute(array($me['id'])); $hash = $st->fetchColumn();
		$new = (string)($_POST['new'] ?? '');
		if (!password_verify((string)($_POST['current'] ?? ''), $hash)) $err = 'Your current password is not right.';
		elseif ($new !== (string)($_POST['new2'] ?? '')) $err = 'The two new passwords are not the same.';
		elseif ($p = oev_password_problem($new)) $err = $p;
		else {
			$db->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute(array(password_hash($new, PASSWORD_DEFAULT), $me['id']));
			$db->prepare('DELETE FROM auth_tokens WHERE user_id = ? AND id != ?')->execute(array($me['id'], $me['token_id']));
			$msg = 'Password changed. Other devices will need to log in again.';
		}
	}
	/* ---- personal email defaults (never another login's row) ---- */
	if ($a === 'notify_defaults') {
		$ne = isset($_POST['n_errors']) ? 1 : 0; $nr = isset($_POST['n_reboots']) ? 1 : 0;
		$ns = isset($_POST['n_sessions']) ? 1 : 0; $nst = isset($_POST['n_stops']) ? 1 : 0;
		$nrem = isset($_POST['n_reminders']) ? 1 : 0;
		$hss = oev_users_have_col($db, 'n_sessions_since'); $hs = oev_users_have_stops($db);
		$sql = 'UPDATE users SET n_errors = ?, n_reboots = ?, n_sessions = ?, n_reminders = ?';
		$args = array($ne, $nr, $ns, $nrem);
		if ($hss) { $sql .= ', n_sessions_since = CASE WHEN CAST(? AS INTEGER) = 0 THEN NULL WHEN coalesce(n_sessions, 0) = 0 THEN CAST(? AS INTEGER) ELSE n_sessions_since END'; $args[] = $ns; $args[] = time(); }
		if ($hs) { $sql .= ', n_stops = ?, n_stops_since = CASE WHEN CAST(? AS INTEGER) = 0 THEN NULL WHEN coalesce(n_stops, 0) = 0 THEN CAST(? AS INTEGER) ELSE n_stops_since END'; $args[] = $nst; $args[] = $nst; $args[] = time(); }
		$sql .= ' WHERE id = ?'; $args[] = (int)$me['id'];
		$db->prepare($sql)->execute($args);
		$go('Email defaults saved.');
	}
	/* ---- own plug-in reminders (owner = this login; mail = account email only; live on the unit panel) ---- */
	if ($a === 'rem_add' || $a === 'rem_save' || $a === 'rem_delete') {
		if (!oev_reminders_have_owner($db)) $go('Reminders are not ready yet (run setup).');
		$anchor = function($uid){ return $uid > 0 ? ('notify-u' . (int)$uid) : 'notify'; };
		if ($a === 'rem_delete') {
			$rid = (int)($_POST['id'] ?? 0);
			$st = $db->prepare('SELECT id, unit_id FROM reminders WHERE id = ? AND user_id = ?'); $st->execute(array($rid, (int)$me['id'])); $row = $st->fetch(PDO::FETCH_ASSOC);
			if (!$row) $go('Reminder not found.', 'notify');
			$db->prepare('DELETE FROM reminders WHERE id = ? AND user_id = ?')->execute(array($rid, (int)$me['id']));
			$go('Reminder removed.', $anchor((int)$row['unit_id']));
		}
		$uid = (int)($_POST['unit'] ?? 0);
		$st = $db->prepare('SELECT id, loadgroupid, name FROM evses WHERE id = ?'); $st->execute(array($uid)); $unit = $st->fetch(PDO::FETCH_ASSOC);
		if (!$unit || !oev_unit_visible($unit)) $go('Pick a charger you can see.', $anchor($uid));
		$t = (string)($_POST['at'] ?? '');
		/* days[] present (even empty) → validate; omitted (old clients/tests) → every day. */
		$days = array_key_exists('days', $_POST) ? $_POST['days'] : 127;
		if ($a === 'rem_add') {
			list($ok, $rmsg, , $au) = oev_reminder_write($db, (int)$me['id'], $uid, $t, 1, 0, $days);
			if ($ok) $rmsg = 'Reminder added. It emails only ' . $me['email'] . '.';
			$go($rmsg, $anchor((int)$au));
		}
		/* rem_save: keep the reminder on its unit (no charger move); ownership + dupe check under lock; existence=on */
		$rid = (int)($_POST['id'] ?? 0);
		list($ok, $rmsg, , $au) = oev_reminder_write($db, (int)$me['id'], $uid, $t, 1, $rid, $days);
		$go($rmsg, $anchor((int)$au));
	}
}
if (isset($_GET['saved'])) $msg = 'Saved.';
if (isset($_GET['msg']) && $_GET['msg'] !== '') $msg = (string)$_GET['msg'];

// refresh me from DB for flags
$st = $db->prepare('SELECT * FROM users WHERE id = ?'); $st->execute(array((int)$me['id'])); $me = $st->fetch(PDO::FETCH_ASSOC) ?: $me;
$me['groups'] = oev_user_groups((int)$me['id']);
$units = array(); foreach ($db->query('SELECT id, name, loadgroupid FROM evses ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC) as $r) if (oev_unit_visible($r)) $units[(int)$r['id']] = $r;
$have = oev_unit_notify_have($db);
$view = $have ? oev_unit_notify_view($db, $me, $units) : array();
$master = (bool)oev_setting('notify_on', '0');
$CS = oev_csrf_field();
if (oev_reminders_have_owner($db)) oev_reminders_days_col($db);
$rems = oev_reminders_for_user($db, (int)$me['id']);
$unres = oev_is_admin() ? oev_reminders_unresolved($db) : array();

$title = 'Account Preferences'; $wq = '';
$OEV_WIDE = 1; include __DIR__ . '/_head.php';
?>
<style>
.ac{font-size:16px;line-height:1.45;color:#444;text-align:left;max-width:none;width:auto;margin:0;box-sizing:border-box}
.ac .card{background:#fff;border:1px solid #ccc;border-radius:8px;padding:8px 10px;margin:8px 0;box-sizing:border-box}
.ac label{display:block;margin:8px 0 2px 0;color:#333}
.ac label.in{display:block;margin:4px 0}
.ac input[type=password],.ac input[type=text],.ac input[type=email],.ac input[type=time],.ac select{width:100%;max-width:100%;box-sizing:border-box;font-size:16px;padding:5px 6px;border:1px solid #bbb;border-radius:4px}
.ac button{font-size:16px;padding:5px 14px;margin:10px 8px 2px 0;border:1px solid #888;border-radius:5px;background:#f6f6f6;cursor:pointer}
.ac button.go{background:#00706f;border-color:#00706f;color:#fff}
.ac button.del{color:#a11}
.ac .msg{background:#e3f4e3;border:1px solid #9c9;border-radius:6px;padding:6px 9px;color:#245a24}
.ac .err{background:#fde3e3;border:1px solid #e99;border-radius:6px;padding:6px 9px;color:#a11;font-weight:normal}
.ac .note{color:#666;font-size:14px;margin:4px 0}
.ac .nsub{margin:-2px 0 6px 22px;font-size:13px}
.ac .ync{background:#fff;border:1px solid #ccc;border-radius:8px;padding:6px 8px;margin:8px 0;box-sizing:border-box;overflow:hidden;max-width:100%}
/* Desktop: chevron + fixed name + 4 status cols + flexible Reminder (stays inside card) */
.ac .ync>summary{cursor:pointer;list-style:none;display:grid;grid-template-columns:1em 10.8em 5.2em 5.7em 5.9em 5.7em minmax(auto,1fr);column-gap:8px;row-gap:2px;align-items:baseline;color:#333;min-width:0;max-width:100%}
.ac .ync>summary::-webkit-details-marker{display:none}
.ac .ync>summary::before{content:"▸";color:#035a5a;font-weight:bold;grid-column:1}
.ac .ync[open]>summary::before{content:"▾"}
.ac .ync-name{font-weight:bold;grid-column:2;min-width:0;overflow-wrap:anywhere}
.ac .ync-b{font-size:13px;color:#666;font-weight:normal;white-space:nowrap;min-width:0}
.ac .ync-b:last-child{white-space:normal;overflow-wrap:break-word;word-break:normal;min-width:0}  /* Reminder wraps inside track only if needed */
.ac .ync-b b{color:#333;font-weight:700}
@media screen and (max-width:600px){
	/* Mobile: compact inline wrap — name then bits side by side, not full-width stacks */
	.ac .ync>summary{display:flex;flex-wrap:wrap;gap:4px 10px;align-items:baseline}
	.ac .ync>summary::before{grid-column:auto}
	.ac .ync-name{grid-column:auto;flex:0 1 auto;margin-right:4px}
	.ac .ync-b{white-space:nowrap}
}
.ac .yn-row{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;align-items:flex-end}
.ac .yn-row .yn{flex:1 1 110px;min-width:100px;max-width:140px;margin:0}
.ac .yn-row .yn label{display:block;margin:0 0 2px 0;font-size:13px;color:#333}
.ac .yn-row .yn select{display:block;width:100%;max-width:140px;box-sizing:border-box;font-size:15px;padding:4px 6px}
.ac .yn-row .yn.yn-def select{color:#888}
.ac .yn-row .yn select option[value=inherit]{color:#888}
.ac .yn-row .yn select option[value=on],.ac .yn-row .yn select option[value=off]{color:#222}
.ac .yn-row .ynsave{flex:0 0 auto;margin:0 0 1px 0}
.ac .yn-row .ynsave button{margin:0;padding:4px 12px;font-size:15px}
.ac .yn-rem{margin-top:8px;padding-top:8px;border-top:1px solid #eee}
.ac .yn-rem .note{font-size:13px;margin:0 0 6px 0}
.ac .rem-row{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin:6px 0}
.ac .rem-row .rem-f{flex:0 1 auto}
.ac .rem-row .rem-f label{display:block;margin:0 0 2px 0;font-size:13px}
.ac .rem-row input[type=time]{width:auto;max-width:140px;box-sizing:border-box;font-size:15px;padding:4px 6px}
.ac .rem-row label.in{display:inline-flex;align-items:center;gap:4px;margin:0 0 2px 0;white-space:nowrap}
.ac .rem-row button{margin:0;padding:4px 10px;font-size:15px}
.ac .rem-row.rem-add-after{border-top:1px solid #eee;margin-top:10px;padding-top:10px}
.ac .rem-days{display:flex;flex-wrap:wrap;gap:2px 6px;align-items:center;flex:1 1 auto;min-width:0;max-width:100%}
.ac .rem-days .rd{display:inline-flex;align-items:center;gap:3px;margin:0;font-size:12px;color:#333;white-space:nowrap;cursor:pointer;font-weight:normal}
.ac .rem-days .rd input{margin:0;flex:none}
@media screen and (max-width:600px){
	.ac .rem-days{flex:1 1 100%;gap:4px 8px}
}
.ac .st.on{color:#1d6b1d;font-weight:bold}.ac .st.off{color:#888;font-weight:bold}
.ac .uh{font-weight:bold;margin-bottom:4px}
@media screen and (max-width:420px){
	.ac .yn-row .yn{flex:1 1 calc(50% - 8px);max-width:none;min-width:0}
	.ac .yn-row .yn select{max-width:100%}
}
@media screen and (min-width:700px){.ac{max-width:820px;margin-left:auto;margin-right:auto}.ac .ac2{display:flex;gap:12px;align-items:flex-start}.ac .ac2>.card{flex:1 1 0;min-width:0;margin:0}}
</style>
<div class="slidebox ac pgcard">
<?php if ($msg) echo '<div class="msg">' . h($msg) . '</div>'; if ($err) echo '<div class="err">' . h($err) . '</div>'; ?>
<div class="card"><b><?=h(oev_user_name($me))?></b><br><?=oev_v($me['email'])?><br><?=$me['role'] === 'admin' ? 'Admin: can view and change everything.' : 'View only.'?> <?=($me['role'] === 'admin' || $me['scope_all']) ? 'Sees all load groups.' : 'Sees some load groups.'?></div>
<div class="ac2"><div class="card"><form method="post" action="account.php"><?=oev_csrf_field()?><input type="hidden" name="a" value="name">
<b>Your name</b>
<div style="display:flex;gap:8px"><div style="flex:1;min-width:0"><label>First name</label><input type="text" name="first_name" value="<?=h($me['first_name'] ?? '')?>" maxlength="60" autocomplete="given-name"></div>
<div style="flex:1;min-width:0"><label>Last name</label><input type="text" name="last_name" value="<?=h($me['last_name'] ?? '')?>" maxlength="60" autocomplete="family-name"></div></div>
<label>Email</label><input type="email" name="email" value="<?=h(is_string($_POST['email'] ?? null) ? $_POST['email'] : $me['email'])?>" maxlength="254" autocapitalize="off" autocomplete="email" required>
<div class="note">You log in with this email, and notification emails go to it.</div>
<button type="submit" class="go">Save</button></form></div>
<div class="card"><form method="post" action="account.php"><?=oev_csrf_field()?><input type="hidden" name="a" value="password">
<b>Change password</b>
<label>Current password</label><input type="password" name="current" autocomplete="current-password" required>
<label>New password</label><input type="password" name="new" autocomplete="new-password" required>
<label>New password again</label><input type="password" name="new2" autocomplete="new-password" required>
<button type="submit" class="go">Change password</button></form></div></div>

<div class="card" id="notify">
<b>Email notifications</b> <span class="note">(Account Preferences)</span>
<div class="note">These are your own choices (defaults, not a master switch). The site-wide gate and mail server are under Settings &gt; Site settings<?=oev_is_admin() ? '' : ' (an admin)'?>.<?=$master ? '' : ' Outgoing email is currently off for the whole site, so nothing is sent.'?></div>
<form method="post" action="account.php"><?=$CS?><input type="hidden" name="a" value="notify_defaults">
<label class="in"><input type="checkbox" name="n_errors"<?=!empty($me['n_errors']) ? ' checked' : ''?>>Errors</label>
<div class="nsub note">Charger faults and when a charger is offline longer than the site threshold.</div>
<label class="in"><input type="checkbox" name="n_reboots"<?=!empty($me['n_reboots']) ? ' checked' : ''?>>Reboots</label>
<label class="in"><input type="checkbox" name="n_sessions"<?=!empty($me['n_sessions']) ? ' checked' : ''?>>Session summary</label>
<div class="nsub note">Account default for every charger you can see. Per-charger overrides below (Default / On / Off).</div>
<?php if (oev_users_have_stops($db)) { ?>
<label class="in"><input type="checkbox" name="n_stops"<?=!empty($me['n_stops']) ? ' checked' : ''?>>Charging stopped</label>
<div class="nsub note">Default for every charger: email when charging stops while still plugged in (not load-balancer pauses), once per plug-in.</div>
<?php } ?>
<label class="in"><input type="checkbox" name="n_reminders"<?=!empty($me['n_reminders']) ? ' checked' : ''?>>Plug-in reminders</label>
<div class="nsub note">Must be on for your per-charger plug-in reminders to email you.</div>
<button type="submit" class="go">Save defaults</button></form>
</div>

<?php
$rems_by = array();
foreach ($rems as $r) $rems_by[(int)$r['unit_id']][] = $r;
$acct_rem = !empty($me['n_reminders']);
if ($have && $units) { ?>
<div class="note" style="margin:4px 2px 0">Per charger (tap to edit): <b>Default</b> follows your checkboxes above.
<strong>Errors</strong> = faults/offline; <strong>Reboots</strong> = charger reboots; <strong>Session summary</strong> = when unplugged; <strong>Charging stopped</strong> = stopped while still plugged in.
<strong>Plug-in reminders</strong> live on each charger below (your account email only).</div>
<?php foreach ($units as $id => $u) echo unp_form_html($id, $u['name'], $view[$id], $CS, $master, $rems_by[$id] ?? array(), $acct_rem); ?>
<script>(function(){var h=location.hash;if(h&&h.indexOf('#notify-u')===0){var el=document.getElementById(h.slice(1));if(el&&el.tagName==='DETAILS'){el.open=true;try{el.scrollIntoView({block:'nearest'})}catch(e){el.scrollIntoView()}}}})();</script>
<script>(function(){function sync(s){var w=s&&s.closest?s.closest('.yn'):null;if(!w)return;if(s.value==='inherit')w.classList.add('yn-def');else w.classList.remove('yn-def')}document.querySelectorAll('.ac .yn-row select').forEach(function(s){sync(s);s.addEventListener('change',function(){sync(s)})})})();</script>
<?php } elseif ($have) { ?>
<div class="card"><div class="note">You have no chargers you can see, so there are no per-charger overrides or reminders.</div></div>
<?php } ?>
<?php if ($unres) { ?>
<div class="card"><div class="note"><b><?=count($unres)?> legacy reminder(s)</b> could not be matched to a single login. They are not emailed until claimed.</div></div>
<?php } ?>

<div class="card"><form method="post" action="account.php"><?=oev_csrf_field()?>
<button type="submit" name="a" value="logout">Log out</button><button type="submit" name="a" value="logout_all">Log out on all devices</button></form></div>
</div>
<?=oev_home_footer()?>
</div></body></html>
