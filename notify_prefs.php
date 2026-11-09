<?php
/******************************************************************************
 * notify_prefs.php - save one login's per-unit Session summary / Charging stopped
 * choice (Use account default / On / Off). Called from Account. Login from the
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
		if (!is_string($m) || !in_array($m, array('inherit', 'on', 'off'), true)) return array(false, 'Choose Use account default, On or Off. Nothing was changed.');
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

// HTML for one unit's Your notifications form (Account). $view: one unit's oev_unit_notify_view row.
function unp_form_html($unit_id, $unit_name, $view, $csrf_html, $master_on){
	$e = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
	$o = '<form method="post" action="notify_prefs.php" class="ynf" id="notify-u' . (int)$unit_id . '">' . $csrf_html
		. '<input type="hidden" name="unitid" value="' . (int)$unit_id . '"><input type="hidden" name="next" value="account">'
		. '<b>' . $e($unit_name) . '</b>';
	foreach (oev_unit_notify_kinds() as $k => $t) {
		$c = $view[$k]; $id = 'yn' . (int)$unit_id . '_' . $k;
		$o .= '<div class="yn"><label for="' . $id . '">' . $e($t) . '</label>'
			. '<select id="' . $id . '" name="n_' . $k . '">'
			. '<option value="inherit"' . ($c['mode'] === 'inherit' ? ' selected' : '') . '>Use account default (' . ($c['account_on'] ? 'On' : 'Off') . ')</option>'
			. '<option value="on"' . ($c['mode'] === 'on' ? ' selected' : '') . '>On</option>'
			. '<option value="off"' . ($c['mode'] === 'off' ? ' selected' : '') . '>Off</option>'
			. '</select> <span class="ynnow">Now: ' . ($c['on'] ? 'On' : 'Off') . '</span></div>';
	}
	$o .= '<button type="submit" class="go">Save</button></form>';
	if (!$master_on) $o .= '<div class="note">Outgoing email is off for the whole site, so nothing is sent right now.</div>';
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
