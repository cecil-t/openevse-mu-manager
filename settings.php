<?php
/******************************************************************************
 * settings.php - Settings (admins only): OpenEVSE units, load groups,
 * preferences (voltages, temperature, time zone, email, plug-in reminders),
 * users, view-only links and System status (sys_lib.php). Unit setup that needs the unit itself (Emoncms,
 * MQTT) is queued in openevse.db `jobs` and done within a minute by
 * collector/jobs.php (root task "OpenEVSE jobs and notifications").
 * Passwords are never shown; blank password fields keep the saved one.
 ******************************************************************************/
require_once __DIR__ . '/auth_lib.php';
$me = oev_require('admin');
// Test connection, hostname and its read-back need the unit helpers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['a'] ?? '', array('unit_test', 'unit_hostname', 'host_read'), true)) { require_once __DIR__ . '/include_v5.php'; require_once __DIR__ . '/collector/session_logic.php'; }
require_once __DIR__ . '/util_lib.php';   // h(), ago(), oev_sess_ro()
$db = oev_db();
$sec = in_array($_GET['s'] ?? '', array('units', 'groups', 'prefs', 'users', 'link', 'system'), true) ? $_GET['s'] : 'units';

function back($sec, $msg, $anchor = ''){ header('Location: settings.php?s=' . $sec . '&msg=' . urlencode($msg) . ($anchor ? '#' . $anchor : ''), true, 303); exit; }
function norm_url($u){
	$u = trim($u); if ($u === '') return '';
	if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $u)) $u = 'http://' . $u;
	$p = parse_url($u); if (!$p || empty($p['host'])) return '';
	$s = strtolower($p['scheme'] ?? 'http'); if ($s !== 'http' && $s !== 'https') return '';
	return $s . '://' . $p['host'] . (isset($p['port']) ? ':' . (int)$p['port'] : '') . rtrim($p['path'] ?? '', '/') . '/';
}
// NEC 240.6(A) standard breaker sizes; an EVSE is a continuous load, so the breaker is at least 125% of its current
function min_breaker($a){ return oev_min_breaker($a); }   // auth_lib.php
function queue($kind, $uid, $arg = ''){
	global $db, $me;
	$db->prepare("UPDATE jobs SET status = 'replaced', done_at = ? WHERE status = 'queued' AND unit_id = ? AND kind IN (" . ($kind === 'emon' ? "'emon'" : "'mqtt_on','mqtt_off'") . ")")->execute(array(time(), $uid));
	$db->prepare('INSERT INTO jobs (kind, unit_id, arg, created_at, created_by) VALUES (?, ?, ?, ?, ?)')->execute(array($kind, $uid, $arg, time(), $me['id']));
}
$groups = $db->query('SELECT * FROM load_groups ORDER BY name')->fetchAll(); $gname = array(); foreach ($groups as $g) $gname[(int)$g['id']] = $g['name'];
$emon_names = array('none' => 'None', 'internal' => 'Internal logging', 'external' => 'External server');

/* ======================= POST ======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	oev_csrf_check();
	$a = $_POST['a'] ?? '';
	$P = function($k, $d = ''){ return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; };
	/* ---- units: Test connection (unsaved address / login), JSON ---- */
	if ($a === 'unit_test') {
		header('Content-Type: application/json'); header('Cache-Control: no-store');
		$id = (int)$P('id'); $url = norm_url($P('url'));
		if ($url === '') { echo json_encode(array('ok' => false, 'html' => 'Enter an address first (for example 192.168.1.120).', 'msg' => 'Enter an address first.')); exit; }
		$old = null; if ($id) { $st = $db->prepare('SELECT * FROM evses WHERE id = ?'); $st->execute(array($id)); $old = $st->fetch(); }
		$pw = (string)($_POST['password'] ?? '');
		// the saved password is only sent to the saved address, never to a new one
		if ($pw === '' && $old && !isset($_POST['clearpass']) && norm_url((string)$old['evseurl']) === $url) $pw = (string)(oev_unit_row($old)['password'] ?? '');
		$unit = array('id' => $id ?: 0, 'name' => 'test', 'evseurl' => $url, 'username' => $P('username'), 'password' => $pw);
		require_once __DIR__ . '/include_v5.php';
		require_once __DIR__ . '/collector/session_logic.php';
		$r = evse_http($unit, 'GET', 'config');
		$V = function($x){ return oev_v($x); };   // values from the unit / the form, in black
		$out = function($ok, $html, $hw = null){ echo json_encode(array('ok' => $ok, 'html' => $html, 'msg' => html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'), 'hw' => $hw)); exit; };
		if ($r['code'] == 401) $out(false, 'Reached the unit, but it wants a login: the username or password is missing or wrong.');
		if ($r['code'] == 0) $out(false, 'Could not reach anything at ' . $V($url) . '. Check the address, and that the unit is on and on the network.');
		$c = $r['ok'] && is_array($r['json']) ? $r['json'] : null;
		$st = null; if ($c) { $x = evse_http($unit, 'GET', 'status'); if ($x['ok'] && is_array($x['json'])) $st = $x['json']; }
		$wv = $c ? (string)($c['version'] ?? '') : ''; $ctl = $c ? (string)($c['firmware'] ?? '') : '';
		$gc = sess_rapi($unit, '$GC');   // "min hwmax active saved"
		$hw = null; if (is_string($gc) && preg_match('/^\d+ (\d+)/', $gc, $m)) $hw = (int)$m[1];
		if (!$hw && $c && !empty($c['max_current_hard'])) $hw = (int)$c['max_current_hard'];
		if (!$c && $gc === null) $out(false, 'Something answered at ' . $V($url) . ' (' . $V('HTTP ' . $r['code']) . "), but it doesn't look like an OpenEVSE unit.");
		if (!$ctl) { $gv = sess_rapi($unit, '$GV'); if (is_string($gv)) $ctl = strtok($gv, ' '); }
		$esp = $wv !== '' ? ((int)ltrim($wv, 'vV') >= 4 ? 'ESP32' : 'ESP8266, legacy') : '';
		$lines = array('Reached the unit' . ($unit['username'] !== '' ? ' and logged in' : '') . '.');
		$lines[] = 'Firmware: WiFi ' . ($wv !== '' ? $V($wv) . ' (' . $V($esp) . ')' : 'unknown') . ($ctl !== '' ? ', controller ' . $V($ctl) : '') . '.';
		$lines[] = $hw ? 'Maximum current the unit allows (hardware): ' . $V("$hw A") . '.' : "Couldn't read the unit's maximum current.";
		// hostname on the unit vs the suggested one (rule: settings_lib.php oev_host_suggest, Help "Names")
		$all = $db->query('SELECT id, name FROM evses')->fetchAll(); $tid = $id;
		if (!$tid) { $tid = 1; foreach ($all as $x) $tid = max($tid, (int)$x['id'] + 1); $all[] = array('id' => $tid, 'name' => $P('name')); }
		elseif ($P('name') !== '') foreach ($all as $k => $x) if ((int)$x['id'] === $tid) $all[$k]['name'] = $P('name');
		$sug = oev_host_suggest($all)[$tid] ?? '';
		$hn = $c ? (string)($c['hostname'] ?? '') : '';
		if ($hn === '') $lines[] = "Couldn't read the unit's hostname.";
		elseif ($hn === $sug) $lines[] = 'Hostname on the unit: ' . $V($hn) . ' (matches the suggested one).';
		else $lines[] = 'Hostname on the unit: ' . $V($hn) . '. <span class="nflag">&#9888; Suggested: ' . $V($sug) . '</span> ' . ($id ? 'Use "Set hostname on unit" below to change it.' : 'You can set it after adding the unit.');
		if ($st && isset($st['voltage']) && is_numeric($st['voltage'])) {
			$v = (float)$st['voltage']; $std = in_array(round($v, 1), array(120.0, 208.0, 230.0, 240.0), true);
			$lines[] = 'Voltage it reports: ' . $V(round($v, 1) . ' V') . ($std ? ' (a standard value: most likely the service-level default, not a sensor).' : ' (looks like a real measurement: tick "has its own voltage sensor" if so).');
		}
		$out(true, implode(' ', $lines), $hw);
	}
	/* ---- units: set the hostname on the unit, restart only its WiFi module, read it back ---- */
	/* ---- units: read the unit's current hostname (read only), JSON ---- */
	if ($a === 'host_read') {
		header('Content-Type: application/json'); header('Cache-Control: no-store');
		$id = (int)$P('id'); $us = evse_units(array($id));
		if (!isset($us[$id])) { echo json_encode(array('ok' => false)); exit; }
		$r = evse_http($us[$id], 'GET', 'config');
		$hn = ($r['ok'] && is_array($r['json'])) ? (string)($r['json']['hostname'] ?? '') : '';
		echo json_encode(array('ok' => $hn !== '', 'host' => $hn)); exit;
	}
	if ($a === 'unit_hostname') {
		$id = (int)$P('id'); $ajax = isset($_POST['ajax']);
		$done = function($ok, $html) use ($ajax, $id){
			if ($ajax) { header('Content-Type: application/json'); header('Cache-Control: no-store'); echo json_encode(array('ok' => $ok, 'html' => $html)); exit; }
			back('units', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'), "u$id");
		};
		$us = evse_units(array($id)); if (!isset($us[$id])) $done(false, 'That unit is gone.');
		$u = $us[$id]; $want = oev_host_base($P('hostname'), $id);
		@set_time_limit(180); ignore_user_abort(true);
		$hlog = function($m) use ($id, $me){ @file_put_contents(__DIR__ . '/collector/jobs.log', date('Y-m-d H:i:s') . " hostname u$id: $m (by " . $me['email'] . ")\n", FILE_APPEND | LOCK_EX); };
		$r = evse_http($u, 'GET', 'config');
		if (!$r['ok'] || !is_array($r['json'])) $done(false, "Couldn't read the unit's settings (" . oev_v($r['code'] ? 'HTTP ' . $r['code'] : 'no answer') . '). Nothing was changed.');
		$c = $r['json']; $v5 = (int)ltrim((string)($c['version'] ?? ''), 'vV') >= 4; $old = (string)($c['hostname'] ?? '');
		if ($old === $want) $done(true, 'The unit already has the hostname ' . oev_v($want) . '. Nothing was changed.');
		$w = evse_http($u, 'POST', 'config', array('hostname' => $want), false);
		if (!$v5 && !($w['code'] >= 200 && $w['code'] < 300)) $w = evse_http($u, 'POST', 'saveadvanced?hostname=' . rawurlencode($want), null, false);   // older 2.x
		if (!($w['code'] >= 200 && $w['code'] < 300)) $done(false, 'The unit did not accept the hostname (' . oev_v($w['code'] ? 'HTTP ' . $w['code'] : 'no answer') . '). Nothing was changed.');
		$r = evse_http($u, 'GET', 'config'); $saved = ($r['ok'] && is_array($r['json'])) ? (string)($r['json']['hostname'] ?? '') : '';
		if ($saved !== $want) { $hlog("$old -> $want: saved value reads back as '$saved'"); $done(false, 'The unit answered, but its hostname now reads ' . oev_v($saved !== '' ? $saved : 'nothing') . ', not ' . oev_v($want) . '. Its WiFi was not restarted.'); }
		// v5 keeps manual overrides only in the WiFi module's memory: a restart would drop one, so don't restart then
		if ($v5 && evse_override_active($u)) { $hlog("$old -> $want saved; WiFi NOT restarted (manual override active)"); $done(true, 'Hostname saved on the unit: ' . oev_v($want) . '. It takes effect the next time the unit restarts. Its WiFi was <b>not</b> restarted now, because a manual override is active on the unit and the restart would clear it.'); }
		$hlog("$old -> $want saved; restarting the WiFi module only");
		// WiFi module only: v5 = the gateway (never "evse"); 2.x /restart reboots the ESP8266, not the controller
		evse_http($u, 'POST', 'restart', $v5 ? array('device' => 'gateway') : null, false);
		$t0 = time(); sleep(8); $now = '';
		while (time() - $t0 < 90) { $r = evse_http($u, 'GET', 'config'); if ($r['ok'] && is_array($r['json'])) { $now = (string)($r['json']['hostname'] ?? ''); break; } sleep(3); }
		$secs = time() - $t0;
		if ($now === '') { $hlog("restart sent; no answer after {$secs}s"); $done(false, 'Hostname saved and the WiFi module restarted, but the unit has not answered again after ' . oev_v("$secs seconds") . '. Check it in a minute.'); }
		$hlog("verified '$now' after {$secs}s");
		$done($now === $want, $now === $want
			? 'Done. The unit restarted its WiFi module and answered again after ' . oev_v("about $secs seconds") . '. Its hostname is now ' . oev_v($now) . '. The router (Deco) may show the new name only after the unit next asks it for an address, and keeps any name you typed in its app.'
			: 'The WiFi module restarted, but the hostname reads ' . oev_v($now) . ', not ' . oev_v($want) . '.');
	}
	/* ---- units ---- */
	if ($a === 'unit_save' || $a === 'unit_add') {
		$id = (int)$P('id');
		$old = null; if ($a === 'unit_save') { $st = $db->prepare('SELECT * FROM evses WHERE id = ?'); $st->execute(array($id)); $old = $st->fetch(); if (!$old) back('units', 'That unit is gone.'); }
		$name = $P('name'); $url = norm_url($P('url')); $brk = (int)$P('breaker'); $en = isset($_POST['enabled']) ? 1 : 0; $vm = isset($_POST['voltmeter']) ? 1 : null;
		$hw = (int)$P('hw_max'); if ($hw < 6 || $hw > 80) $hw = $old && $old['hw_max'] ? (int)$old['hw_max'] : null;
		$gid = (int)$P('group'); if ($gid && !isset($gname[$gid])) $gid = 0;
		$em = in_array($P('emon'), array('none', 'internal', 'external'), true) ? $P('emon') : 'internal';
		if ($name === '' || $url === '') back('units', 'Each unit needs a name and an address that works (for example 192.168.1.120).', $id ? "u$id" : 'add');
		if ($brk < 6 || $brk > 80) back('units', 'Max current must be between 6 and 80 A.', $id ? "u$id" : 'add');
		if ($hw && $brk > $hw && $old && $en) {   // the stored hardware max may be stale (the unit moved to another circuit): ask the unit
			require_once __DIR__ . '/include_v5.php'; require_once __DIR__ . '/cap_lib.php';
			$x = oev_cap_read(oev_unit_row($old)); if ($x['ok'] && $x['hw']) $hw = $x['hw'];
		}
		if ($hw && $brk > $hw) back('units', "Max current can't be more than the unit's own maximum ($hw A).", $id ? "u$id" : 'add');
		$user = $P('username');
		$pass = $old ? (string)$old['password'] : '';
		if ((string)($_POST['password'] ?? '') !== '') $pass = oev_enc((string)$_POST['password']);
		if (isset($_POST['clearpass'])) $pass = '';
		$xs = $P('ext_server'); $xn = $P('ext_node'); $xk = $old ? (string)$old['emon_ext_key'] : '';
		if ((string)($_POST['ext_key'] ?? '') !== '') $xk = oev_enc((string)$_POST['ext_key']);
		if ($em === 'external' && $xs === '') back('units', 'For an external Emoncms server, enter its address.', $id ? "u$id" : 'add');
		$f = array($name, $url, $brk, $user, $pass, $en, $gid ?: null, $em, $xs, $xn, $xk, $vm, $hw);
		if ($old) { $db->prepare('UPDATE evses SET name=?, evseurl=?, breaker=?, username=?, password=?, enabled=?, loadgroupid=?, emon_mode=?, emon_ext_server=?, emon_ext_node=?, emon_ext_key=?, voltmeter=?, hw_max=? WHERE id = ?')->execute(array_merge($f, array($id))); }
		else { $db->prepare('INSERT INTO evses (name, evseurl, breaker, username, password, enabled, loadgroupid, emon_mode, emon_ext_server, emon_ext_node, emon_ext_key, voltmeter, hw_max) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($f); $id = (int)$db->lastInsertId(); }
		$msgs = array('Saved.');
		// the unit's own saved current is never above Max current; also refreshes hw_max (cap_lib.php)
		if ($en) {
			require_once __DIR__ . '/include_v5.php'; require_once __DIR__ . '/cap_lib.php';
			$st = $db->prepare('SELECT * FROM evses WHERE id = ?'); $st->execute(array($id));
			$cx = oev_cap_enforce(oev_unit_row($st->fetch()), $db);
			if ($cx['from'] === null) $msgs[] = "Couldn't reach the unit to check its saved current. The site checks it again within 10 minutes.";
			elseif ($cx['msg'] !== '') $msgs[] = $cx['msg'];
		}
		$og = $old ? (int)$old['loadgroupid'] : 0;
		if (!$og && $gid) { queue('mqtt_on', $id); $msgs[] = 'Joining a load group: MQTT will be set up on the unit within a minute.'; }
		elseif ($og && !$gid) { queue('mqtt_off', $id); $msgs[] = 'Leaving the load group: MQTT will be turned off on the unit within a minute.'; }
		$oe = $old ? (string)($old['emon_mode'] ?: 'internal') : '';
		if ($em !== $oe || ($em === 'external' && ($xs !== (string)$old['emon_ext_server'] || $xn !== (string)$old['emon_ext_node'] || $xk !== (string)$old['emon_ext_key']))) {
			queue('emon', $id, $em); $msgs[] = 'Emoncms (' . $emon_names[$em] . ') will be set on the unit within a minute.';
		}
		back('units', implode(' ', $msgs), "u$id");
	}
	if ($a === 'unit_delete') {
		$id = (int)$P('id');
		$db->prepare('DELETE FROM evses WHERE id = ?')->execute(array($id));
		$db->prepare("UPDATE jobs SET status = 'replaced', done_at = ? WHERE status = 'queued' AND unit_id = ?")->execute(array(time(), $id));
		$db->prepare('DELETE FROM reminders WHERE unit_id = ?')->execute(array($id));
		back('units', 'Unit removed. Its session history stays in the reports. Nothing was changed on the unit itself.');
	}
	/* ---- groups ---- */
	if ($a === 'group_save' || $a === 'group_add') {
		$id = (int)$P('id'); $name = $P('name'); $cap = (int)$P('cap');
		if ($name === '' || $cap < 6 || $cap > 999) back('groups', 'A load group needs a name and a capacity from 6 to 999 A.', $id ? "g$id" : '');
		if ($a === 'group_save') $db->prepare('UPDATE load_groups SET name = ?, max_load = ? WHERE id = ?')->execute(array($name, $cap, $id));
		else $db->prepare('INSERT INTO load_groups (name, max_load) VALUES (?, ?)')->execute(array($name, $cap));
		back('groups', 'Saved. The load balancer picks up the change within a minute.');
	}
	if ($a === 'group_delete') {
		$id = (int)$P('id');
		$n = $db->prepare('SELECT count(*) FROM evses WHERE loadgroupid = ?'); $n->execute(array($id));
		if ((int)$n->fetchColumn()) back('groups', 'This group still has units. Set each of them to "None" under Settings > Units first.', "g$id");
		$db->prepare('DELETE FROM load_groups WHERE id = ?')->execute(array($id));
		$db->prepare('DELETE FROM user_groups WHERE group_id = ?')->execute(array($id));
		back('groups', 'Load group removed.');
	}
	/* ---- preferences ---- */
	if ($a === 'prefs_save') {
		$l1 = (int)$P('volts_l1'); $l2 = (int)$P('volts_l2'); $tz = $P('timezone');
		if ($l1 < 90 || $l1 > 260 || $l2 < 90 || $l2 > 260) back('prefs', 'Voltages must be between 90 and 260.');
		if ($tz !== '' && !in_array($tz, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) back('prefs', 'Unknown time zone.');
		$sn = trim(preg_replace('/\s+/', ' ', $P('site_name'))); $sn = function_exists('mb_substr') ? mb_substr($sn, 0, 60) : substr($sn, 0, 60);
		$su = trim(substr($P('site_url'), 0, 200)); if ($su !== '' && oev_site_url_norm($su) === '') back('prefs', 'The site web address is not a valid web address (example: https://example.com/openevse/).');
		oev_settings_save(array('site_name' => $sn !== '' ? $sn : OEV_SITE_NAME_DEFAULT, 'site_url' => oev_site_url_norm($su), 'volts_l1' => $l1, 'volts_l2' => $l2, 'temp_unit' => $P('temp_unit') === 'C' ? 'C' : 'F', 'timezone' => $tz));
		back('prefs', 'Saved.');
	}
	if ($a === 'mail_save' || $a === 'mail_check' || $a === 'mail_test') {
		$kv = array('notify_on' => isset($_POST['notify_on']) ? '1' : '0', 'heartbeat_min' => max(2, min(10080, (int)$P('heartbeat_val') * ($P('heartbeat_unit') === 'h' ? 60 : 1))),
			'smtp_host' => $P('smtp_host'), 'smtp_port' => (string)max(1, min(65535, (int)$P('smtp_port', '587'))), 'smtp_secure' => in_array($P('smtp_secure'), array('tls', 'ssl', 'none'), true) ? $P('smtp_secure') : 'tls',
			'smtp_user' => $P('smtp_user'), 'smtp_from' => $P('smtp_from'), 'smtp_from_name' => $P('smtp_from_name'));
		if ($kv['smtp_from'] !== '' && !filter_var($kv['smtp_from'], FILTER_VALIDATE_EMAIL)) back('prefs', 'The from address is not a valid email address.', 'mail');
		if ((string)($_POST['smtp_pass'] ?? '') !== '') $kv['smtp_pass'] = oev_enc((string)$_POST['smtp_pass']);
		if (isset($_POST['smtp_clearpass'])) $kv['smtp_pass'] = '';
		oev_settings_save($kv);
		require_once __DIR__ . '/mail_lib.php';
		if ($a === 'mail_check') { $r = oev_mail_check(); back('prefs', $r === true ? 'Saved. The server accepted the login (no email was sent).' : 'Saved, but: ' . $r, 'mail'); }
		if ($a === 'mail_test') { $r = oev_mail($me['email'], 'Test email', 'This is a test from ' . oev_site_name() . ".\nIf you can read this, email notifications work."); back('prefs', $r === true ? 'Saved. Test email sent to ' . $me['email'] . '.' : 'Saved, but: ' . $r, 'mail'); }
		back('prefs', 'Saved.', 'mail');
	}
	if ($a === 'rem_add' || $a === 'rem_save') {
		$uid = (int)$P('unit'); $t = $P('at');
		if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $t)) back('prefs', 'Pick a time for the reminder.', 'rem');
		$xl = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\s]+/', $P('extra'))))));
		if (!$xl) back('prefs', 'Enter at least one email address under "Send to".', 'rem');
		foreach ($xl as $e) if (!filter_var($e, FILTER_VALIDATE_EMAIL)) back('prefs', '"' . $e . '" is not an email address.', 'rem');
		$x = implode(', ', $xl);
		if ($a === 'rem_add') $db->prepare('INSERT INTO reminders (unit_id, at_time, extra_to, enabled) VALUES (?, ?, ?, 1)')->execute(array($uid, $t, $x));
		else $db->prepare('UPDATE reminders SET unit_id = ?, at_time = ?, extra_to = ?, enabled = ? WHERE id = ?')->execute(array($uid, $t, $x, isset($_POST['enabled']) ? 1 : 0, (int)$P('id')));
		back('prefs', 'Saved.', 'rem');
	}
	if ($a === 'rem_delete') { $db->prepare('DELETE FROM reminders WHERE id = ?')->execute(array((int)$P('id'))); back('prefs', 'Reminder removed.', 'rem'); }
	/* ---- users ---- */
	if ($a === 'user_add' || $a === 'user_save') {
		$id = (int)$P('id'); $email = strtolower($P('email')); $role = $P('role') === 'admin' ? 'admin' : 'viewer'; $all = ($role === 'admin' || $P('scope') === 'all') ? 1 : 0;   // admins always see everything
		$gs = array_values(array_intersect(array_map('intval', (array)($_POST['groups'] ?? array())), array_keys($gname)));
		$n = array(); foreach (array('errors', 'reboots', 'sessions') as $k) $n[$k] = isset($_POST['n_' . $k]) ? 1 : 0;
		$fn = substr($P('first_name'), 0, 60); $ln = substr($P('last_name'), 0, 60);
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) back('users', 'Enter a valid email address.', $id ? "p$id" : 'addu');
		if (!$all && !$gs) back('users', 'Pick at least one load group, or All load groups.', $id ? "p$id" : 'addu');
		$pw = (string)($_POST['password'] ?? '');
		if ($a === 'user_add' || $pw !== '') { $pp = oev_password_problem($pw); if ($pp) back('users', $pp, $id ? "p$id" : 'addu'); }
		try {
			if ($a === 'user_add') {
				$db->prepare('INSERT INTO users (email, first_name, last_name, pass_hash, role, scope_all, n_errors, n_reboots, n_sessions, n_reminders, created_at) VALUES (?,?,?,?,?,?,?,?,?,0,?)')
					->execute(array($email, $fn, $ln, password_hash($pw, PASSWORD_DEFAULT), $role, $all, $n['errors'], $n['reboots'], $n['sessions'], time()));
				$id = (int)$db->lastInsertId();
			} else {
				if ($id === (int)$me['id'] && $role !== 'admin') back('users', 'You can\'t take admin away from your own login.', "p$id");
				$db->prepare('UPDATE users SET email = ?, first_name = ?, last_name = ?, role = ?, scope_all = ?, n_errors = ?, n_reboots = ?, n_sessions = ? WHERE id = ?')
					->execute(array($email, $fn, $ln, $role, $all, $n['errors'], $n['reboots'], $n['sessions'], $id));
				if ($pw !== '') { $db->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute(array(password_hash($pw, PASSWORD_DEFAULT), $id)); if ($id !== (int)$me['id']) $db->prepare('DELETE FROM auth_tokens WHERE user_id = ?')->execute(array($id)); }
			}
		} catch (PDOException $e) { back('users', 'That email address already has a login.', $id ? "p$id" : 'addu'); }
		$db->prepare('DELETE FROM user_groups WHERE user_id = ?')->execute(array($id));
		if (!$all) foreach ($gs as $g) $db->prepare('INSERT INTO user_groups (user_id, group_id) VALUES (?, ?)')->execute(array($id, $g));
		back('users', $a === 'user_add' ? 'Login added. Give them the password; they can change it under Account.' : 'Saved.', "p$id");
	}
	if ($a === 'user_delete') {
		$id = (int)$P('id');
		if ($id === (int)$me['id']) back('users', 'You can\'t remove your own login.');
		foreach (array('DELETE FROM auth_tokens WHERE user_id = ?', 'DELETE FROM user_groups WHERE user_id = ?', 'DELETE FROM users WHERE id = ?') as $q) $db->prepare($q)->execute(array($id));
		back('users', 'Login removed.');
	}
	/* ---- view-only links ---- */
	if ($a === 'link_add') {
		list($sel, $val) = oev_new_token();
		$db->prepare('INSERT INTO public_links (selector, token_hash, token_enc, label, created_at, created_by) VALUES (?,?,?,?,?,?)')->execute(array($sel, hash('sha256', $val), oev_enc("$sel.$val"), $P('label') ?: 'View-only link', time(), $me['id']));
		back('link', 'Link made. Copy it below and share it.');
	}
	if ($a === 'link_revoke') { $db->prepare('UPDATE public_links SET revoked_at = ? WHERE id = ?')->execute(array(time(), (int)$P('id'))); back('link', 'Link turned off. Anyone using it is shut out now.'); }
	back($sec, 'Nothing changed.');
}

/* ======================= PAGE ======================= */
$title = 'Settings'; $wq = '';
include __DIR__ . '/_head.php';
$S = oev_settings();
// remember this site's public address for the command-line scripts (email links, the units' Emoncms server):
// only an https address with a host name (not a LAN or VPN IP address, not localhost)
$__su = oev_site_url_detect(); $__sh = (string)parse_url($__su, PHP_URL_HOST);
if (strpos($__su, 'https://') === 0 && $__sh !== 'localhost' && !filter_var($__sh, FILTER_VALIDATE_IP) && $__su !== $S['site_url_seen']) { try { oev_settings_save(array('site_url_seen' => $__su)); $S = oev_settings(); } catch (Exception $ex) {} }
$CS = oev_csrf_field();
$units = $db->query('SELECT * FROM evses ORDER BY name')->fetchAll();
$uname = array(); foreach ($units as $u) $uname[(int)$u['id']] = $u['name'];
function sel($a, $b){ return (string)$a === (string)$b ? ' selected' : ''; }
function chk($v){ return $v ? ' checked' : ''; }
?>
<style>
.set{font-size:16px;line-height:1.45;color:#444;text-align:left}
.set .tabs{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 10px 0}
.set .tabs a{display:inline-block;padding:4px 11px;border-radius:15px;background:#fff;border:1px solid #bbb;color:#035a5a;text-decoration:none}
.set .tabs a.on{background:#00706f;border-color:#00706f;color:#fff;font-weight:bold}
.set .card{background:#fff;border:1px solid #ccc;border-radius:8px;padding:8px 10px;margin:8px 0}
.set details.card>summary{cursor:pointer;font-weight:bold;color:#035a5a;list-style-position:outside}
.set details.card>summary span{font-weight:normal;color:#666}
.set label{display:block;margin:8px 0 2px 0;color:#333}
.set label.in{display:inline-block;margin:4px 14px 2px 0;font-weight:normal}
.set input[type=text],.set input[type=email],.set input[type=password],.set input[type=number],.set input[type=time],.set input[type=url],.set select{width:100%;box-sizing:border-box;font-size:16px;padding:5px 6px;border:1px solid #bbb;border-radius:4px;background:#fff}
.set input[type=checkbox],.set input[type=radio]{width:18px;height:18px;vertical-align:-3px;margin:0 5px 0 0}
.set .row2{display:flex;gap:8px}.set .row2>div{flex:1 1 0;min-width:0}
.set .note{color:#666;margin:4px 0}
.set .warn{background:#fff4d6;border:1px solid #e9c46a;border-radius:6px;padding:5px 8px;margin:6px 0;color:#6b4b00}
.set .msg{background:#e3f4e3;border:1px solid #9c9;border-radius:6px;padding:6px 9px;margin:0 0 8px 0;color:#245a24}
.set button{font-size:16px;padding:5px 14px;margin:10px 8px 2px 0;border:1px solid #888;border-radius:5px;background:#f6f6f6;color:#000;cursor:pointer}
.set button.go{background:#00706f;border-color:#00706f;color:#fff}
.set button.del{background:#fff;border-color:#c44;color:#a11}
.set .job{color:#555;margin:2px 0}.set .job b{color:#333}
.set .job.failed b{color:#a11}.set .job.done b{color:#2a7a2a}
.set .lnk{word-break:break-all;background:#f3f3f3;border:1px solid #ddd;border-radius:4px;padding:4px 6px;font-family:monospace;font-size:15px}
.set h2{font-size:18px;margin:14px 0 4px 0;color:#035a5a}
.set .tres{margin:6px 0;padding:6px 9px;border-radius:6px;background:#f1f1f1;border:1px solid #ccc;color:#333}
.set .tres.ok{background:#e3f4e3;border-color:#9c9;color:#245a24}.set .tres.bad{background:#fde3e3;border-color:#e99;color:#a11}
.set .brk{padding-bottom:6px;color:#333}
.set .emrow{display:flex;align-items:center;gap:10px;margin:10px 0 2px 0}.set .emrow label{margin:0;flex:0 0 auto}.set .emrow select{flex:1 1 auto;width:auto;min-width:0}
.set .sys{display:flex;gap:9px;align-items:flex-start;border-top:1px solid #e3e3e3;padding:7px 0}.set .sys:first-of-type{border-top:0}
.set .sys .ic{flex:0 0 26px;height:26px;border-radius:50%;color:#fff;font-weight:bold;text-align:center;line-height:26px}
.set .sys .ic.ok{background:#2a8a2a}.set .sys .ic.bad{background:#c22}.set .sys .ic.na{background:#aaa}
.set .sys b{color:#222}
.set .nflag{display:inline-block;margin-left:4px;padding:0 5px;border-radius:4px;background:#fff4d6;border:1px solid #e9c46a;color:#6b4b00;font-weight:normal}
</style>
<div class="slidebox set">
<div class="tabs">
<?php foreach (array('units' => 'Units', 'groups' => 'Load groups', 'prefs' => 'Preferences', 'users' => 'Users', 'link' => 'View-only link', 'system' => 'System status') as $k => $t) echo '<a href="settings.php?s=' . $k . '"' . ($k === $sec ? ' class="on"' : '') . '>' . $t . '</a>'; ?>
</div>
<?php if (isset($_GET['msg'])) echo '<div class="msg">' . h($_GET['msg']) . '</div>'; ?>
<?php
/* ----------------------------- UNITS ----------------------------- */
if ($sec === 'units') {
	$jobs = array(); foreach ($db->query("SELECT * FROM jobs WHERE status != 'replaced' ORDER BY id DESC LIMIT 60") as $j) if (count($jobs[(int)$j['unit_id']] ?? array()) < 3) $jobs[(int)$j['unit_id']][] = $j;
	$jk = array('emon' => 'Emoncms', 'mqtt_on' => 'MQTT on', 'mqtt_off' => 'MQTT off');
	function unit_form($u, $groups, $CS, $emon_names){
		global $HSUG;
		$id = $u ? (int)$u['id'] : 0; $em = $u ? ($u['emon_mode'] ?: 'internal') : 'internal'; $p = $id ? "u$id" : 'new';
		ob_start(); ?>
		<form method="post" action="settings.php?s=units"><?=$CS?><input type="hidden" name="a" value="<?=$id ? 'unit_save' : 'unit_add'?>"><input type="hidden" name="id" value="<?=$id?>">
		<label>Name</label><input type="text" name="name" value="<?=h($u['name'] ?? '')?>" required>
		<label>Address</label><input type="text" name="url" value="<?=h($u['evseurl'] ?? '')?>" placeholder="192.168.1.120 or openevse-1234.local" autocapitalize="off" autocorrect="off" required>
		<div class="note">Any way this server can reach the unit: local IP, host name, public address or full URL.</div>
		<div class="row2"><div><label>Unit username</label><input type="text" name="username" value="<?=h($u['username'] ?? '')?>" autocapitalize="off" autocomplete="off"></div>
		<div><label>Unit password</label><input type="password" name="password" value="" placeholder="<?=!empty($u['password']) ? '(saved)' : '(none)'?>" autocomplete="new-password"></div></div>
		<div class="note">Only if the unit's web page asks for a login. The password is stored encrypted and never shown. Leave it blank to keep the saved one.</div>
		<?php if (!empty($u['password'])) { ?><label class="in"><input type="checkbox" name="clearpass">Remove the saved password</label><?php } ?>
		<div><button type="button" onclick="utest(this)">Test connection</button></div>
		<div class="tres" hidden></div>
		<?php if ($id) { $sug = $HSUG[$id] ?? oev_host_base($u['name'], $id); ?>
		<label>Hostname on the unit</label>
		<input type="text" name="hostname" class="hin" value="<?=h($sug)?>" data-sug="<?=h($sug)?>" maxlength="<?=OEV_HOST_MAX?>" autocapitalize="off" autocorrect="off" spellcheck="false" style="color:#000;font-weight:600">
		<div class="note hcur">Reading the current hostname from the unit&hellip;</div>
		<div class="note hsug" hidden>Suggested: <span class="v"><?=h($sug)?></span> <a href="#" onclick="husesug(this);return false" style="color:#035a5a;font-weight:bold;margin-left:6px">Use suggestion</a></div>
		<div><button type="button" onclick="uhost(this)">Set hostname on unit</button></div>
		<div class="hres tres" hidden></div>
		<div class="note">The suggestion is made from the name (the rule is in Help). You can type any hostname; it is cleaned up by the same rule. Setting it restarts only the unit's WiFi module (offline for about 20 seconds; charging carries on).</div>
		<?php } ?>
		<input type="hidden" name="hw_max" value="<?=h($u['hw_max'] ?? '')?>">
		<?php $hw = (int)($u['hw_max'] ?? 0); $bv = (int)($u['breaker'] ?? 40); ?>
		<label>Max current (Amps)</label>
		<div style="display:flex;gap:10px;align-items:center"><div style="flex:0 0 90px"><input type="number" name="breaker" min="6" max="<?=$hw ?: 80?>" value="<?=h($bv)?>" oninput="ubrk(this)" required></div>
		<div class="brk" style="flex:1 1 auto;padding:0"><span class="brkt">Required minimum circuit breaker: <?=oev_v(min_breaker($bv) . ' A')?></span></div></div>
		<div class="note hwn"><?=$hw ? 'This unit allows up to ' . oev_v("$hw A") . ' (its hardware maximum).' : 'Press Test connection to read the most this unit allows.'?></div>
		<?php if ($hw && $bv > $hw) echo '<div class="warn">This is more than the unit allows (' . oev_v("$hw A") . '). Lower it to ' . oev_v("$hw A") . ' or less to save.</div>'; ?>
		<div class="note">The breaker size is the setting &times; 125% (chargers are a continuous load), rounded up to the next standard breaker size.</div>
		<label>Load group</label><select name="group"><option value="0">None</option><?php foreach ($groups as $g) echo '<option value="' . (int)$g['id'] . '"' . sel($g['id'], $u['loadgroupid'] ?? '') . '>' . h($g['name']) . '</option>'; ?></select>
		<div class="note">Joining a load group turns on MQTT on the unit and sets its login for the load balancer. Leaving the group turns MQTT off.</div>
		<label class="in"><input type="checkbox" name="enabled"<?=chk($u ? $u['enabled'] : 1)?>>Enabled</label><br>
		<label class="in"><input type="checkbox" name="voltmeter"<?=chk(!empty($u['voltmeter']))?>>This unit has its own voltage sensor</label>
		<div class="note">Tick only if a voltage sensor is fitted (a voltmeter add-on). Then its reading is used for kW instead of the Level 1 / Level 2 voltage in Preferences.</div>
		<div class="emrow"><label for="em<?=$p?>">Emoncms</label><select id="em<?=$p?>" name="emon" onchange="emx(this)"><?php foreach ($emon_names as $k => $t) echo '<option value="' . $k . '"' . sel($em, $k) . '>' . $t . '</option>'; ?></select></div>
		<div class="note">Internal logging: the unit sends its readings to this server every 30 seconds. This keeps pages fast and session reports detailed. The key is set up for you.</div>
		<div class="emx" style="<?=$em === 'external' ? '' : 'display:none'?>">
			<div class="warn">With an outside Emoncms server, this server can't get the unit's 30-second updates. Pages fall back to asking the unit directly, so they will be slower.</div>
			<label>External server</label><input type="text" name="ext_server" value="<?=h($u['emon_ext_server'] ?? '')?>" placeholder="https://emoncms.org" autocapitalize="off">
			<div class="row2"><div><label>Node</label><input type="text" name="ext_node" value="<?=h($u['emon_ext_node'] ?? '')?>"></div>
			<div><label>Write API key</label><input type="password" name="ext_key" value="" placeholder="<?=!empty($u['emon_ext_key']) ? '(saved)' : ''?>" autocomplete="new-password"></div></div>
		</div>
		<div class="emnone note" style="<?=$em === 'none' ? '' : 'display:none'?>">None: this server reads the unit directly every minute instead.</div>
		<br><button type="submit" class="go"><?=$id ? 'Save' : 'Add unit'?></button></form>
		<?php return ob_get_clean();
	}
	$HSUG = oev_host_suggest($units);
	echo '<div class="note" style="margin:2px 0 8px 0"><a href="names.php" style="color:#035a5a;font-weight:bold">View all unit names (table / CSV)</a></div>';
	echo '<div class="note">Changes are used right away by the pages, and by the load balancer within a minute.</div>';
	foreach ($units as $u) {
		$id = (int)$u['id'];
		echo '<details class="card" id="u' . $id . '"' . ((isset($_GET['msg']) && strpos($_SERVER['REQUEST_URI'], "#u$id") !== false) ? ' open' : '') . '><summary>' . h($u['name']) . ' <span>&middot; ' . ($u['enabled'] ? '' : 'disabled &middot; ') . ($u['loadgroupid'] ? oev_v($gname[(int)$u['loadgroupid']] ?? 'group ' . $u['loadgroupid']) : 'no group') . '</span></summary>';
		echo unit_form($u, $groups, $CS, $emon_names);
		foreach ($jobs[$id] ?? array() as $j) {
			$w = $j['status'] === 'queued' || $j['status'] === 'running' ? 'waiting to run (within a minute)' : h($j['msg']);
			echo '<div class="job ' . h($j['status']) . '"><b>' . h($jk[$j['kind']] ?? $j['kind']) . ($j['kind'] === 'emon' ? ' (' . h($emon_names[$j['arg']] ?? $j['arg']) . ')' : '') . ', ' . oev_v(ago($j['done_at'] ?: $j['created_at'])) . ':</b> ' . $w . '</div>';
		}
		echo '<form method="post" action="settings.php?s=units" onsubmit="return confirm(\'Remove ' . h(addslashes($u['name'])) . ' from this site? Nothing is changed on the unit.\')">' . $CS . '<input type="hidden" name="a" value="unit_delete"><input type="hidden" name="id" value="' . $id . '"><button type="submit" class="del">Remove unit</button></form>';
		echo '</details>';
	}
	echo '<details class="card" id="add"><summary>Add a unit</summary>' . unit_form(null, $groups, $CS, $emon_names) . '</details>';
	?><script>function emx(r){ r.form.querySelector('.emx').style.display = r.value === 'external' ? '' : 'none'; r.form.querySelector('.emnone').style.display = r.value === 'none' ? '' : 'none'; }
	var BRK = [15, 20, 25, 30, 35, 40, 45, 50, 60, 70, 80, 90, 100, 110, 125, 150, 175, 200];
	function minBrk(a){ var n = a * 1.25; for (var i = 0; i < BRK.length; i++) if (BRK[i] >= n - 1e-9) return BRK[i]; return Math.ceil(n); }
	function ubrk(i){ var a = parseInt(i.value, 10), t = i.form.querySelector('.brkt'); t.innerHTML = (a >= 6 && a <= 80) ? 'Required minimum circuit breaker: <span class="v">' + minBrk(a) + ' A</span>' : 'Enter 6 to <span class="v">' + (i.max || 80) + ' A</span>'; }
	function utest(b){
		var f = b.form, out = f.querySelector('.tres'), fd = new FormData(f); fd.set('a', 'unit_test');
		out.hidden = false; out.className = 'tres'; out.textContent = 'Checking the unit\u2026 (up to 15 seconds)'; b.disabled = true;
		fetch('settings.php?s=units', {method: 'POST', body: fd, credentials: 'same-origin'}).then(function(r){ return r.json(); }).then(function(j){
			out.className = 'tres ' + (j.ok ? 'ok' : 'bad'); out.innerHTML = (j.ok ? '\u2714 ' : '\u2716 ') + (j.html || '');   // server-escaped, values in <span class="v">
			if (j.ok && j.hw) { var i = f.querySelector('input[name=breaker]'); i.max = j.hw; f.querySelector('input[name=hw_max]').value = j.hw;
				f.querySelector('.hwn').innerHTML = 'This unit allows up to <span class="v">' + parseInt(j.hw, 10) + ' A</span> (its hardware maximum).';
				if (!i.value || parseInt(i.value, 10) > j.hw) { i.value = j.hw; out.insertAdjacentHTML('beforeend', ' Max current set to <span class="v">' + parseInt(j.hw, 10) + ' A</span>.'); }
				ubrk(i); }
		}).catch(function(){ out.className = 'tres bad'; out.textContent = '\u2716 The test did not finish. Try again.'; }).then(function(){ b.disabled = false; });
	}
	function uhost(b){
		var f = b.form, out = f.querySelector('.hres'), hn = f.querySelector('input[name=hostname]').value;
		if (!confirm('Set the hostname on this unit to "' + hn + '" (cleaned up by the rule in Help)?\n\nOnly its WiFi module restarts: it is offline for about 20 seconds, and charging carries on.')) return;
		var fd = new FormData(f); fd.set('a', 'unit_hostname'); fd.set('ajax', '1');
		out.hidden = false; out.className = 'hres tres'; out.textContent = 'Setting the hostname and restarting the WiFi module\u2026 (up to 2 minutes)'; b.disabled = true;
		fetch('settings.php?s=units', {method: 'POST', body: fd, credentials: 'same-origin'}).then(function(r){ return r.json(); }).then(function(j){
			out.className = 'hres tres ' + (j.ok ? 'ok' : 'bad'); out.innerHTML = (j.ok ? '\u2714 ' : '\u2716 ') + (j.html || '');   // server-escaped
			hread(f, true);
		}).catch(function(){ out.className = 'hres tres bad'; out.textContent = '\u2716 No reply. Check the unit in a minute.'; }).then(function(){ b.disabled = false; });
	}
	// prefill the hostname box with the unit's current hostname (read live when the unit is opened)
	function hshow(f, host){
		var i = f.querySelector('.hin'), cur = f.querySelector('.hcur'), sg = f.querySelector('.hsug'), sug = i.getAttribute('data-sug');
		if (host) { if (!i.dataset.edited) i.value = host; cur.textContent = 'This is the hostname the unit has now.'; sg.hidden = (host === sug); }
		else { if (!i.dataset.edited) i.value = sug; cur.textContent = "Couldn't read the current hostname from the unit, so the suggestion is filled in."; sg.hidden = true; }
	}
	function hread(f, again){
		var i = f.querySelector('.hin'); if (!i || (f.dataset.hread && !again)) return; f.dataset.hread = 1;
		if (again) delete i.dataset.edited;
		var fd = new FormData(); fd.set('a', 'host_read'); fd.set('id', f.querySelector('input[name=id]').value); fd.set('csrf', f.querySelector('input[name=csrf]').value);
		fetch('settings.php?s=units', {method: 'POST', body: fd, credentials: 'same-origin'}).then(function(r){ return r.json(); }).then(function(j){ hshow(f, j && j.ok ? String(j.host) : ''); }).catch(function(){ hshow(f, ''); });
	}
	function husesug(a){ var f = a.closest('form'), i = f.querySelector('.hin'); i.value = i.getAttribute('data-sug'); i.dataset.edited = 1; }
	document.querySelectorAll('.hin').forEach(function(i){
		i.addEventListener('input', function(){ i.dataset.edited = 1; });
		var d = i.closest('details'); if (d) { d.addEventListener('toggle', function(){ if (d.open) hread(i.form); }); if (d.open) hread(i.form); }
	});
	if (location.hash) { var d = document.querySelector(location.hash); if (d && d.tagName === 'DETAILS') d.open = true; }</script><?php
}
/* ----------------------------- GROUPS ----------------------------- */
if ($sec === 'groups') {
	echo '<div class="note">A load group is a set of units fed from the same breaker panel or main breaker (each unit can still have its own branch breaker). The group\'s capacity is the limit for that panel or feeder, and the load balancer keeps the units\' total under it.</div>';
	echo '<div class="warn">Units in a load group have MQTT managed by this system: it is set up when a unit joins and turned off when it leaves. It can\'t be used for anything else on those units. Units not in a group can use MQTT any way you like (set it up on the unit\'s own page).</div>';
	echo '<div class="note">Use 999 A for "unlimited" when a group is only there to choose which units a user can see.</div>';
	foreach ($groups as $g) {
		$gid = (int)$g['id']; $in = array(); foreach ($units as $u) if ((int)$u['loadgroupid'] === $gid) $in[] = $u['name'];
		?><div class="card" id="g<?=$gid?>"><form method="post" action="settings.php?s=groups"><?=$CS?><input type="hidden" name="a" value="group_save"><input type="hidden" name="id" value="<?=$gid?>">
		<div class="row2"><div style="flex:2"><label>Name</label><input type="text" name="name" value="<?=h($g['name'])?>" required></div><div><label>Capacity (A)</label><input type="number" name="cap" min="6" max="999" value="<?=(int)$g['max_load']?>"></div></div>
		<div class="note">Units: <?=$in ? oev_v(implode(', ', $in)) : 'none yet'?></div>
		<button type="submit" class="go">Save</button></form>
		<form method="post" action="settings.php?s=groups" onsubmit="return confirm('Delete the load group <?=h(addslashes($g['name']))?>?')"><?=$CS?><input type="hidden" name="a" value="group_delete"><input type="hidden" name="id" value="<?=$gid?>"><button type="submit" class="del"<?=$in ? ' disabled style="opacity:.45;cursor:not-allowed"' : ''?>>Delete group</button>
		<div class="note"><?=$in ? 'To delete this group, first set each of its units to Load group "None" under Units. Logins limited to this group lose it when it is deleted.' : 'No units are in this group, so it can be deleted.'?></div></form>
		</div><?php
	}
	?><details class="card"><summary>Add a load group</summary><form method="post" action="settings.php?s=groups"><?=$CS?><input type="hidden" name="a" value="group_add">
	<div class="row2"><div style="flex:2"><label>Name</label><input type="text" name="name" required></div><div><label>Capacity (A)</label><input type="number" name="cap" min="6" max="999" value="80"></div></div>
	<div class="note">Then add units to it under Units.</div><button type="submit" class="go">Add group</button></form></details><?php
}
/* ----------------------------- PREFERENCES ----------------------------- */
if ($sec === 'prefs') {
	$hb = (int)$S['heartbeat_min']; $hbu = ($hb >= 60 && $hb % 60 === 0) ? 'h' : 'm'; $hbv = $hbu === 'h' ? $hb / 60 : $hb;
	?><div class="card"><form method="post" action="settings.php?s=prefs"><?=$CS?><input type="hidden" name="a" value="prefs_save">
	<h2 style="margin-top:2px">Site name</h2>
	<input type="text" name="site_name" value="<?=h(oev_site_name())?>" maxlength="60" placeholder="<?=h(OEV_SITE_NAME_DEFAULT)?>">
	<div class="note">Shown in page titles, under the logo, and in the subject and from-name of every email. Blank = <?=oev_v(OEV_SITE_NAME_DEFAULT)?>.</div>
	<h2>Site web address</h2>
	<input type="url" name="site_url" value="<?=h($S['site_url'])?>" maxlength="200" placeholder="<?=h(oev_site_url_detect())?>" autocapitalize="off" spellcheck="false">
	<div class="note">Used for the link in every email, view-only links, and the Emoncms server address given to units set to Internal logging. Blank = this page's address (<?=oev_v(oev_site_url_detect())?>). Units already set up keep their address until their Emoncms setting is saved again.</div>
	<h2>Voltage</h2>
	<div class="note">The units only report their service level (Level 1 or Level 2), so power (kW) is worked out from these voltages. A unit with its own voltage sensor uses its reading instead.</div>
	<div class="row2"><div><label>Level 1 (V)</label><input type="number" name="volts_l1" min="90" max="260" value="<?=h($S['volts_l1'])?>"></div><div><label>Level 2 (V)</label><input type="number" name="volts_l2" min="90" max="260" value="<?=h($S['volts_l2'])?>"></div></div>
	<div class="note">Usual: 120 / 240. Commercial 3-phase: 208 for Level 2.</div>
	<h2>Temperature</h2>
	<label class="in"><input type="radio" name="temp_unit" value="F"<?=chk($S['temp_unit'] !== 'C')?>>&deg;F</label><label class="in"><input type="radio" name="temp_unit" value="C"<?=chk($S['temp_unit'] === 'C')?>>&deg;C</label>
	<h2>Time zone</h2>
	<select name="timezone"><option value="">Server default (<?=h(OEV_SERVER_TZ)?>)</option><?php foreach (DateTimeZone::listIdentifiers() as $z) echo '<option' . sel($z, $S['timezone']) . '>' . h($z) . '</option>'; ?></select>
	<div class="note">Used for every time shown, schedules and the units' clocks. Now: <?=oev_v(date('D g:i a T'))?>.</div>
	<button type="submit" class="go">Save</button></form></div>

	<div class="card" id="mail"><form method="post" action="settings.php?s=prefs"><?=$CS?>
	<h2 style="margin-top:2px">Email notifications</h2>
	<label class="in"><input type="checkbox" name="notify_on"<?=chk($S['notify_on'] === '1')?>>Send notifications</label>
	<div class="note">Who gets errors, reboots and finished sessions is set for each user under Users. Plug-in reminders go only to the addresses on each reminder below.</div>
	<label>Error if a unit is not heard from for</label>
	<div class="row2"><div><input type="number" name="heartbeat_val" min="1" max="10080" value="<?=h($hbv)?>"></div><div><select name="heartbeat_unit"><option value="m"<?=sel($hbu, 'm')?>>minutes</option><option value="h"<?=sel($hbu, 'h')?>>hours</option></select></div></div>
	<h2>Mail server (SMTP)</h2>
	<div class="note">Use any mail service you have an account with. Mailjet works well and has a free plan (server in-v3.mailjet.com, port 587, TLS; the username and password are its API key and secret key).</div>
	<div class="note">Port 587 goes with TLS, 465 with SSL.</div><label>Server</label><input type="text" name="smtp_host" value="<?=h($S['smtp_host'])?>" autocapitalize="off">
	<div class="row2"><div><label>Port</label><input type="number" name="smtp_port" value="<?=h($S['smtp_port'])?>"></div><div><label>Security</label><select name="smtp_secure"><option value="tls"<?=sel($S['smtp_secure'], 'tls')?>>TLS</option><option value="ssl"<?=sel($S['smtp_secure'], 'ssl')?>>SSL</option><option value="none"<?=sel($S['smtp_secure'], 'none')?>>None</option></select></div></div>
	<label>Username</label><input type="text" name="smtp_user" value="<?=h($S['smtp_user'])?>" autocapitalize="off" autocomplete="off">
	<label>Password</label><input type="password" name="smtp_pass" value="" placeholder="<?=$S['smtp_pass'] !== '' ? '(saved: leave blank to keep it)' : ''?>" autocomplete="new-password">
	<?php if ($S['smtp_pass'] !== '') { ?><label class="in"><input type="checkbox" name="smtp_clearpass">Remove the saved password</label><?php } ?>
	<label>From address</label><input type="email" name="smtp_from" value="<?=h($S['smtp_from'])?>" autocapitalize="off">
	<label>From name</label><input type="text" name="smtp_from_name" value="<?=h($S['smtp_from_name'])?>" placeholder="<?=h(oev_site_name())?>">
	<div class="note">Blank = the site name (<?=oev_v(oev_site_name())?>).</div>
	<button type="submit" name="a" value="mail_save" class="go">Save</button><button type="submit" name="a" value="mail_check">Check login</button><button type="submit" name="a" value="mail_test">Send test email</button>
	<div class="note">Check login connects and logs in without sending. The test email goes to <?=oev_v($me['email'])?>.</div>
	</form></div>

	<div class="card" id="rem"><h2 style="margin-top:2px">Plug-in reminders</h2>
	<div class="note">An email if no car is plugged into the unit by the set time (checked for 15 minutes after it, once a day). It goes only to the addresses in "Send to" (a phone's text-message address works too).</div>
	<?php foreach ($db->query('SELECT * FROM reminders ORDER BY at_time')->fetchAll() as $r) { ?>
		<form method="post" action="settings.php?s=prefs" style="border-top:1px solid #ddd;padding-top:4px;margin-top:8px"><?=$CS?><input type="hidden" name="id" value="<?=(int)$r['id']?>">
		<div class="row2"><div style="flex:1.3"><label>Unit</label><select name="unit"><?php foreach ($units as $u) echo '<option value="' . (int)$u['id'] . '"' . sel($u['id'], $r['unit_id']) . '>' . h($u['name']) . '</option>'; ?></select></div><div><label>Plug in by</label><input type="time" name="at" value="<?=h($r['at_time'])?>"></div></div>
		<label>Send to</label><input type="text" name="extra" value="<?=h($r['extra_to'])?>" placeholder="email addresses, comma between them" autocapitalize="off" required>
		<label class="in"><input type="checkbox" name="enabled"<?=chk($r['enabled'])?>>On</label>
		<br><button type="submit" name="a" value="rem_save" class="go">Save</button><button type="submit" name="a" value="rem_delete" class="del" onclick="return confirm('Remove this reminder?')">Remove</button></form>
	<?php } ?>
	<details style="margin-top:8px"><summary style="cursor:pointer;color:#035a5a;font-weight:bold">Add a reminder</summary><form method="post" action="settings.php?s=prefs"><?=$CS?><input type="hidden" name="a" value="rem_add">
	<div class="row2"><div style="flex:1.3"><label>Unit</label><select name="unit"><?php foreach ($units as $u) echo '<option value="' . (int)$u['id'] . '">' . h($u['name']) . '</option>'; ?></select></div><div><label>Plug in by</label><input type="time" name="at" value="21:00"></div></div>
	<label>Send to</label><input type="text" name="extra" placeholder="email addresses, comma between them" autocapitalize="off" required>
	<button type="submit" class="go">Add reminder</button></form></details></div>
	<?php
}
/* ----------------------------- USERS ----------------------------- */
if ($sec === 'users') {
	function user_form($u, $groups, $CS, $me){
		$id = $u ? (int)$u['id'] : 0; $ug = $id ? oev_user_groups($id) : array(); $all = $u ? (int)$u['scope_all'] : 1;
		ob_start(); ?>
		<form method="post" action="settings.php?s=users"><?=$CS?><input type="hidden" name="a" value="<?=$id ? 'user_save' : 'user_add'?>"><input type="hidden" name="id" value="<?=$id?>">
		<div class="row2"><div><label>First name</label><input type="text" name="first_name" value="<?=h($u['first_name'] ?? '')?>" maxlength="60"></div><div><label>Last name</label><input type="text" name="last_name" value="<?=h($u['last_name'] ?? '')?>" maxlength="60"></div></div>
		<label>Email</label><input type="email" name="email" value="<?=h($u['email'] ?? '')?>" autocapitalize="off" required>
		<label><?=$id ? 'New password' : 'Password'?></label><input type="password" name="password" value="" placeholder="<?=$id ? 'leave blank to keep' : 'at least 8 characters'?>" autocomplete="new-password"<?=$id ? '' : ' required'?>>
		<label>Can</label>
		<label class="in"><input type="radio" name="role" value="viewer"<?=chk(($u['role'] ?? 'viewer') === 'viewer')?> onchange="rsx(this)">View only</label><label class="in"><input type="radio" name="role" value="admin"<?=chk(($u['role'] ?? '') === 'admin')?> onchange="rsx(this)">View and change (admin)</label>
		<div class="scx"><label>Sees</label><noscript><div class="note">Admins always see all load groups; this choice is for view-only logins.</div></noscript>
		<label class="in"><input type="radio" name="scope" value="all"<?=chk($all)?> onchange="gsx(this)">All load groups</label><label class="in"><input type="radio" name="scope" value="some"<?=chk(!$all)?> onchange="gsx(this)">Only these load groups:</label>
		<div class="gsx" style="margin-left:22px;<?=$all ? 'display:none' : ''?>"><?php foreach ($groups as $g) echo '<label class="in"><input type="checkbox" name="groups[]" value="' . (int)$g['id'] . '"' . chk(in_array((int)$g['id'], $ug, true)) . '>' . h($g['name']) . '</label>'; ?>
		<div class="note">Units that are not in a load group are only shown to users who see all load groups.</div></div></div>
		<label>Emails about</label>
		<?php foreach (array('errors' => 'Errors', 'reboots' => 'Reboots', 'sessions' => 'Finished sessions') as $k => $t) echo '<label class="in"><input type="checkbox" name="n_' . $k . '"' . chk(!empty($u['n_' . $k])) . '>' . $t . '</label>'; ?>
		<div class="note">Plug-in reminders are set on each reminder (Preferences), not here.</div>
		<button type="submit" class="go"><?=$id ? 'Save' : 'Add login'?></button></form>
		<?php return ob_get_clean();
	}
	echo '<div class="note">Logins stay signed in on each device until they log out. View-only logins see everything in their load groups but can\'t press any buttons or change settings.</div>';
	foreach ($db->query("SELECT * FROM users ORDER BY coalesce(nullif(first_name, ''), email), last_name")->fetchAll() as $u) {
		$id = (int)$u['id'];
		echo '<details class="card" id="p' . $id . '"><summary>' . h(oev_user_name($u)) . ' <span>&middot; ' . oev_v($u['role'] === 'admin' ? 'admin' : 'view only') . ' &middot; ' . oev_v(($u['role'] === 'admin' || $u['scope_all']) ? 'all groups' : 'some groups') . '</span></summary>';
		echo user_form($u, $groups, $CS, $me);
		echo '<div class="note">Last log in: ' . oev_v(ago($u['last_login_at'])) . '</div>';
		if ($id !== (int)$me['id']) echo '<form method="post" action="settings.php?s=users" onsubmit="return confirm(\'Remove the login for ' . h(addslashes($u['email'])) . '?\')">' . $CS . '<input type="hidden" name="a" value="user_delete"><input type="hidden" name="id" value="' . $id . '"><button type="submit" class="del">Remove login</button></form>';
		echo '</details>';
	}
	echo '<details class="card" id="addu"><summary>Add a login</summary>' . user_form(null, $groups, $CS, $me) . '</details>';
	?><script>function gsx(r){ r.form.querySelector('.gsx').style.display = r.value === 'some' ? '' : 'none'; }
	function rsx(r){ var f = r.form, a = f.querySelector('input[name=role][value=admin]').checked; f.querySelector('.scx').style.display = a ? 'none' : ''; }   // admins always see all load groups
	document.querySelectorAll('input[name=role][value=admin]').forEach(rsx);
	if (location.hash) { var d = document.querySelector(location.hash); if (d && d.tagName === 'DETAILS') d.open = true; }</script><?php
}
/* ----------------------------- VIEW-ONLY LINK ----------------------------- */
if ($sec === 'link') {
	echo '<div class="note">A view-only link shows only what is happening right now: the home page and each unit\'s live status, without a login. It does not show session reports, graphs, history, energy totals or logs, and nobody can press buttons or change settings with it. Turn a link off at any time.</div>';
	$links = $db->query('SELECT * FROM public_links WHERE revoked_at IS NULL ORDER BY id')->fetchAll();
	if (!$links) echo '<div class="card">No view-only links yet.</div>';
	foreach ($links as $l) {
		$url = oev_site_url() . '?view=' . oev_dec($l['token_enc']);
		?><div class="card"><b><?=h($l['label'])?></b><div class="note">Made <?=oev_v(date('n/j/Y', (int)$l['created_at']))?> &middot; last used <?=oev_v(ago($l['last_used_at']))?></div>
		<div class="lnk"><?=h($url)?></div>
		<button type="button" onclick="cp(this, <?=h(json_encode($url))?>)">Copy link</button>
		<form method="post" action="settings.php?s=link" style="display:inline" onsubmit="return confirm('Turn this link off? Anyone using it will be shut out.')"><?=$CS?><input type="hidden" name="a" value="link_revoke"><input type="hidden" name="id" value="<?=(int)$l['id']?>"><button type="submit" class="del">Turn off</button></form></div><?php
	}
	?><details class="card"><summary>Make a new link</summary><form method="post" action="settings.php?s=link"><?=$CS?><input type="hidden" name="a" value="link_add">
	<label>Label (for you)</label><input type="text" name="label" placeholder="e.g. Garage tablet"><button type="submit" class="go">Make link</button></form></details>
	<script>function cp(b, t){ (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function(){ b.textContent = 'Copied'; }, function(){ prompt('Copy this link:', t); }); }</script><?php
}
/* ----------------------------- SYSTEM STATUS ----------------------------- */
if ($sec === 'system') {
	require_once __DIR__ . '/sys_lib.php';
	$checks = oev_sys_checks(true); $bad = 0; foreach ($checks as $c) if ($c['ok'] === false) $bad++;
	echo '<div class="' . ($bad ? 'warn' : 'msg') . '">' . ($bad ? oev_v($bad) . ' problem' . ($bad > 1 ? 's' : '') . ' found. Details below.' : 'Everything is running.') . ' Checked ' . oev_v(date('g:i:s a T')) . '.</div>';
	echo '<div class="card">';
	foreach ($checks as $c) {
		$ic = $c['ok'] === true ? array('ok', '&#10003;') : ($c['ok'] === false ? array('bad', '!') : array('na', '&ndash;'));
		echo '<div class="sys"><span class="ic ' . $ic[0] . '">' . $ic[1] . '</span><div><b>' . h($c['title']) . ':</b> ' . ($c['ok'] === true ? 'OK. ' : ($c['ok'] === false ? 'Problem. ' : '')) . $c['text']   /* HTML from sys_lib.php, values already escaped */
			. ($c['seen'] ? '<br><span class="note">Last seen ' . oev_v(date('n/j g:i:s a T', (int)$c['seen'])) . '</span>' : '') . '</div></div>';
	}
	echo '</div>';
	echo '<div class="note">These checks run on their own every minute. When one turns into a problem for 2 minutes in a row, users who get Errors are emailed (if notifications are on), and again when it is OK. The jobs and notifications task sends those emails, so if that task itself stops, no email can go out: the red dot on your initials still shows it.</div>';
	echo '<p><a href="settings.php?s=system">Check again</a></p>';
}
?>
<p><a href="./">&larr; Home</a></p>
</div></div></body></html>
