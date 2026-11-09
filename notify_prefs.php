<?php
/******************************************************************************
 * notify_prefs.php - save one login's per-unit Session summary / Charging stopped
 * choice (Default / On / Off). Called from Account. Login from the
 * session only; never a view-only link. Nothing else is changed.
 ******************************************************************************/
require_once __DIR__ . '/auth_lib.php';

// $me: logged-in user; $post: the form; $visible: fn(unit row) -> bool.
// Returns array(ok, message). Nothing is saved unless every field is valid.
function unp_handle($db, $me, $post, $visible){
	if (!$me || ($me['role'] ?? '') === 'public' || empty($me['id'])) return array(false, 'Please log in.');
	if (!oev_unit_notify_have($db)) return array(false, 'Per-unit notifications are not set up yet (run setup).');
	$uid = $post['unitid'] ?? '';
	if (!is_string($uid) || !ctype_digit($uid)) return array(false, 'Unknown unit.');
	$st = $db->prepare('SELECT id, loadgroupid FROM evses WHERE id = ?'); $st->execute(array((int)$uid)); $unit = $st->fetch(PDO::FETCH_ASSOC);
	if (!$unit || !$visible($unit)) return array(false, 'Unknown unit.');
	$modes = array();
	foreach (oev_unit_notify_kinds() as $k => $t) {
		$m = $post['n_' . $k] ?? null;
		if (!is_string($m) || !in_array($m, array('inherit', 'on', 'off'), true)) return array(false, 'Choose Default, On or Off. Nothing was changed.');
		$modes[$k] = $m;
	}
	$n = oev_unit_notify_save($db, (int)$me['id'], (int)$unit['id'], $modes);
	return array(true, $n ? 'Your notifications saved.' : 'No change.');
}

// Safe return after save: only Account (with optional unit anchor). Never an open redirect.
function unp_next($post, $unit_id = 0){
	$n = is_string($post['next'] ?? null) ? $post['next'] : '';
	if ($n === 'account' || $n === '') {
		$a = ($unit_id > 0) ? '#notify-u' . (int)$unit_id : '#notify';
		return 'account.php?msg=' . rawurlencode('') . $a;   // msg filled by caller
	}
	return 'account.php#notify';
}

// HTML for one unit's Your notifications panel (Account): collapsed summary + expandable
// editors + that charger's plug-in reminder controls. id=notify-uN stays the unit anchor.
// $rems: this login's reminder rows for this unit only (may be empty / several).
// $acct_rem_on: users.n_reminders — when off, reminders do not mail (summary must not claim they will).
function unp_rem_time_label($at){
	if (!preg_match('/^(\d{1,2}):([0-5]\d)$/', (string)$at, $m)) return (string)$at;
	$h = (int)$m[1]; $min = $m[2]; $ap = $h >= 12 ? 'PM' : 'AM'; $h12 = $h % 12; if ($h12 === 0) $h12 = 12;
	return $h12 . ':' . $min . ' ' . $ap;
}
function unp_form_html($unit_id, $unit_name, $view, $csrf_html, $master_on, $rems = array(), $acct_rem_on = true){
	$e = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
	$short = array('errors' => 'Errors', 'reboots' => 'Reboots', 'sessions' => 'Summary', 'stops' => 'Stopped');
	$bits = array();
	foreach (oev_unit_notify_kinds() as $k => $t) {
		$c = $view[$k];
		$txt = $short[$k] . ' ' . (!empty($c['on']) ? 'On' : 'Off');
		$bit = $e($txt);   // escape text; bold only explicit On/Off overrides (not inherit)
		if (($c['mode'] ?? '') === 'on' || ($c['mode'] ?? '') === 'off') $bit = '<b>' . $bit . '</b>';
		$bits[] = $bit;
	}
	// Reminder summary: existence = on; no per-row off. Account / site email gates annotate.
	$labs = array();
	foreach ($rems as $r) $labs[] = unp_rem_time_label($r['at_time'] ?? '');
	if (!$labs) {
		$rbit = $e('Reminder Off');
	} else {
		$txt = (count($labs) === 1 ? 'Reminder ' : 'Reminders ') . implode(', ', $labs);
		if (!$acct_rem_on) $txt .= ' (account off)';
		elseif (!$master_on) $txt .= ' (email off)';
		$rbit = '<b>' . $e($txt) . '</b>';
	}
	$bits[] = $rbit;
	$sum = implode(' · ', $bits);   // separators stay unbolded
	$uid = (int)$unit_id;
	$o = '<details class="ync" id="notify-u' . $uid . '">'
		. '<summary><span class="ync-name">' . $e($unit_name) . '</span>'
		. '<span class="ync-sum">' . $sum . '</span></summary>'
		. '<form method="post" action="notify_prefs.php" class="ynf">' . $csrf_html
		. '<input type="hidden" name="unitid" value="' . $uid . '"><input type="hidden" name="next" value="account">'
		. '<div class="yn-row">';
	foreach (oev_unit_notify_kinds() as $k => $t) {
		$c = $view[$k]; $id = 'yn' . $uid . '_' . $k;
		$def = 'Default (' . ($c['account_on'] ? 'On' : 'Off') . ')';
		$cls = 'yn' . (($c['mode'] ?? '') === 'inherit' ? ' yn-def' : '');
		$o .= '<div class="' . $cls . '"><label for="' . $id . '">' . $e($t) . '</label>'
			. '<select id="' . $id . '" name="n_' . $k . '">'
			. '<option value="inherit"' . ($c['mode'] === 'inherit' ? ' selected' : '') . '>' . $e($def) . '</option>'
			. '<option value="on"' . ($c['mode'] === 'on' ? ' selected' : '') . '>On</option>'
			. '<option value="off"' . ($c['mode'] === 'off' ? ' selected' : '') . '>Off</option>'
			. '</select></div>';
	}
	$o .= '<div class="ynsave"><button type="submit" class="go">Save</button></div></div></form>';
	if (!$master_on) $o .= '<div class="note">Outgoing email is off for the whole site, so nothing is sent right now.</div>';
	// Plug-in reminder(s) for this charger only (no charger selector).
	$o .= '<div class="yn-rem"><div class="note">Plug-in reminder: if nothing is plugged in at or shortly after this time, '
		. 'you get one email for <b>each</b> reminder that day (a later reminder on this charger can still email separately). '
		. 'Goes only to your account email. Needs &ldquo;Plug-in reminders&rdquo; on in your defaults above'
		. ($acct_rem_on ? '' : ' <b>(currently off — will not email)</b>') . '.</div>';
	foreach ($rems as $r) {
		$rid = (int)$r['id'];
		$cfm = json_encode('Remove the ' . unp_rem_time_label($r['at_time'] ?? '') . ' reminder?', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		$o .= '<form method="post" action="account.php" class="rem-row">' . $csrf_html
			. '<input type="hidden" name="a" value="rem_save"><input type="hidden" name="id" value="' . $rid . '">'
			. '<input type="hidden" name="unit" value="' . $uid . '">'
			. '<div class="rem-f"><label for="remat' . $rid . '">Plug in by</label>'
			. '<input type="time" id="remat' . $rid . '" name="at" value="' . $e($r['at_time']) . '"></div>'
			. '<button type="submit" class="go">Save</button>'
			. '<button type="submit" name="a" value="rem_delete" class="del" onclick="return confirm(' . $cfm . ')">Remove</button>'
			. '</form>';
	}
	$o .= '<form method="post" action="account.php" class="rem-row rem-add">' . $csrf_html
		. '<input type="hidden" name="a" value="rem_add"><input type="hidden" name="unit" value="' . $uid . '">'
		. '<div class="rem-f"><label for="remadd' . $uid . '">' . ($rems ? 'Add another' : 'Plug in by') . '</label>'
		. '<input type="time" id="remadd' . $uid . '" name="at" value="21:00"></div>'
		. '<button type="submit" class="go">Add reminder</button></form>';
	$o .= '</div></details>';
	return $o;
}


if (!defined('UNP_LIB_ONLY')) {
	$me = oev_require('view');
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: account.php#notify', true, 303); exit; }
	oev_csrf_check();
	$uid = (is_string($_POST['unitid'] ?? null) && ctype_digit($_POST['unitid'])) ? (int)$_POST['unitid'] : 0;
	try { list($ok, $msg) = unp_handle(oev_db(), $me, $_POST, 'oev_unit_visible'); }
	catch (Throwable $x) { $msg = 'Could not save right now; nothing was changed. Try again in a minute.'; }
	header('Location: account.php?msg=' . rawurlencode($msg) . ($uid ? '#notify-u' . $uid : '#notify'), true, 303); exit;
}
