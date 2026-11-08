<?php
/******************************************************************************
 * notifications.php - Your notifications: the big picture for the logged-in
 * person only. Every unit they can see, with their own Session summary and
 * Charging stopped choice (Use account default / On / Off) and what actually
 * applies, resolved by the same function the notifier uses
 * (oev_unit_notify_view -> oev_notify_effective, settings_lib.php). Read-only:
 * each unit links to its "Your notifications" box (unit.php?unitid=N#yn).
 * Never another login's choices or addresses; view-only links are turned away.
 ******************************************************************************/
require_once __DIR__ . '/auth_lib.php';

// $me: logged-in user; $units: id => evses row, ALREADY limited to what $me may see; $view: oev_unit_notify_view();
// $master: site-wide email switch; $have: per-unit table exists. Returns the page body (HTML).
function nov_render($me, $units, $view, $master, $have){
	$e = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
	$k = oev_unit_notify_kinds(); $o = '';
	if (!$master) $o .= '<div class="err">Emails are turned off for the whole site (Settings &gt; Preferences), so nothing is sent right now, whatever is On below.</div>';
	$o .= '<div class="card"><b>Your account defaults</b>';
	foreach ($k as $kk => $t) { $a = oev_notify_account($me, $kk); $o .= '<div class="nrow"><span>' . $e($t) . '</span><span class="st ' . ($a['on'] ? 'on' : 'off') . '">' . ($a['on'] ? 'On' : 'Off') . '</span></div>'; }
	$o .= '<div class="note">A default, not a master switch: a unit set to On below emails you even when the default is Off, and a unit set to Off never does. '
		. (($me['role'] ?? '') === 'admin' ? 'Change defaults in <a href="settings.php?s=users#p' . (int)$me['id'] . '">Settings &gt; Users</a>.' : 'An admin changes defaults.') . '</div></div>';
	if (!$units) return $o . '<div class="card">You have no chargers you can see, so there is nothing to set here.</div>';
	if (!$have) return $o . '<div class="card">Per-unit choices are not set up yet; your account defaults apply to every unit.</div>';
	foreach ($units as $id => $u) {
		$o .= '<div class="card"><div class="uh"><b>' . $e($u['name']) . '</b> <a href="unit.php?unitid=' . (int)$id . '#yn">Change</a></div>';
		foreach ($k as $kk => $t) {
			$c = $view[(int)$id][$kk];
			$how = $c['mode'] === 'inherit' ? 'Account default (' . ($c['account_on'] ? 'On' : 'Off') . ')' : 'Set for this unit';
			$o .= '<div class="nrow"><span>' . $e($t) . ' <small>' . $e($how) . '</small></span><span class="st ' . ($c['on'] ? 'on' : 'off') . '">' . ($c['on'] ? 'On' : 'Off') . '</span></div>';
		}
		$o .= '</div>';
	}
	return $o;
}

if (!defined('NOV_LIB_ONLY')) {
	$me = oev_require('view');   // logged in; view-only links go to the login page
	require_once __DIR__ . '/util_lib.php';
	$db = oev_db();
	$units = array(); foreach ($db->query('SELECT id, name, loadgroupid FROM evses ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC) as $r) if (oev_unit_visible($r)) $units[(int)$r['id']] = $r;
	$st = $db->prepare('SELECT * FROM users WHERE id = ?'); $st->execute(array((int)$me['id'])); $fresh = $st->fetch(PDO::FETCH_ASSOC) ?: $me;
	$have = oev_unit_notify_have($db);
	$view = $have ? oev_unit_notify_view($db, $fresh, $units) : array();
	$title = 'Your notifications'; $wq = '';
	$OEV_WIDE = 1; include __DIR__ . '/_head.php';
?>
<style>
.nv{font-size:16px;line-height:1.4;color:#444;text-align:left;max-width:520px;margin:0 auto}
.nv .card{background:#fff;border:1px solid #ccc;border-radius:8px;padding:8px 10px;margin:8px 0}
.nv .nrow{display:flex;justify-content:space-between;gap:8px;padding:3px 0;border-top:1px solid #eee}
.nv .uh{display:flex;justify-content:space-between;gap:8px;margin-bottom:2px}
.nv small{color:#777}
.nv .st{font-weight:bold;white-space:nowrap}.nv .st.on{color:#1d6b1d}.nv .st.off{color:#888}
.nv .note{color:#666;font-size:14px;margin-top:4px}
.nv .err{background:#fde3e3;border:1px solid #e99;border-radius:6px;padding:6px 9px;color:#a11}
</style>
<div class="slidebox nv pgcard">
<b>Your notifications</b> <span style="color:#666">(<?=h($fresh['email'] ?? '')?>)</span>
<?=nov_render($fresh, $units, $view, (bool)oev_setting('notify_on', '0'), $have)?>
<p><a href="./">&larr; Home</a> &middot; <a href="account.php">Account</a></p>
</div></div></body></html>
<?php } ?>
