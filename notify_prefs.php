<?php
/******************************************************************************
 * notify_prefs.php - "Your notifications" on the unit page (unit.php): the
 * logged-in person's own Session summary / Charging stopped choice for ONE unit
 * (Use account default / On / Off). Any login that can see the unit, view-only
 * included; never a view-only link. Changes nothing else: no unit settings, no
 * other login's choices (the login always comes from the session, never the form).
 * Stored in openevse.db user_unit_notify (settings_lib.php); used by
 * collector/notifier.php.
 ******************************************************************************/
require_once __DIR__ . '/auth_lib.php';

// $me: the logged-in user (oev_user()); $post: the form; $visible: fn(unit row) -> bool.
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

if (!defined('UNP_LIB_ONLY')) {
	$me = oev_require('view');   // logged in (view-only logins too); view-only links are turned away
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php', true, 303); exit; }
	oev_csrf_check();
	try { list($ok, $msg) = unp_handle(oev_db(), $me, $_POST, 'oev_unit_visible'); }
	catch (Throwable $x) { $msg = 'Could not save right now; nothing was changed. Try again in a minute.'; }
	$u = (string)($_POST['unitid'] ?? ''); $w = (string)($_POST['with'] ?? '');
	$q = ctype_digit($u) ? 'unitid=' . $u : '';
	if ($q !== '' && preg_match('/^[0-9,]{1,100}$/', $w)) $q .= '&with=' . $w;
	header('Location: ' . ($q !== '' ? 'unit.php?' . $q . '&msg=' . urlencode($msg) : 'index.php'), true, 303); exit;
}
