<?php
/******************************************************************************
 * Email notifications (Settings > Preferences + each user's choices). CLI only,
 * as http, every minute (root task "OpenEVSE jobs and notifications").
 * Kinds (each user picks which they get; only for units they can see):
 *  - errors:    a unit reports an error state (the states that turn the page red:
 *               GFCI, no ground, stuck relay, over temperature, ...); no word from a
 *               unit for longer than the heartbeat setting; load balancer down;
 *               the back-end checks in sys_lib.php (MQTT broker, MQTT listener,
 *               load balancer, collector, units' readings, stuck jobs) after 2 runs
 *               in a row. "OK again" is sent when it clears.
 *  - reboots:   a unit restarted (from the collector's reboot events)
 *  - sessions:  a charging session ended (car unplugged), with kWh and time
 *  - reminders: Plug-in reminders: a unit has no car plugged in by its set time.
 *               Sent only to the reminder's own "Send to" addresses (not to users).
 * At most one email per unit per kind per hour. State: collector/notify_state.json.
 ******************************************************************************/
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('SESS_DIR', __DIR__);
define('OEV_SITE', dirname(__DIR__));
chdir(OEV_SITE);
require OEV_SITE . '/include_v5.php';
require OEV_SITE . '/include_lb.php';
require OEV_SITE . '/sessions_lib.php';
require OEV_SITE . '/mail_lib.php';
require OEV_SITE . '/sys_lib.php';
$TEST = in_array('--dry', $argv);   // print what would be sent, send nothing, keep state

$S = oev_settings();
$stf = SESS_DIR . '/notify_state.json';
$st = @json_decode((string)@file_get_contents($stf), true); if (!is_array($st)) $st = array();
$now = time();
$odb = new PDO('sqlite:' . OEV_SITE . '/openevse.db', null, null, array(PDO::ATTR_TIMEOUT => 10, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
$sdb = sess_db();
$units = array(); foreach ($odb->query('SELECT id, name, enabled, loadgroupid FROM evses') as $u) $units[(int)$u['id']] = $u;
$users = $odb->query('SELECT * FROM users')->fetchAll();
foreach ($users as &$u) $u['groups'] = array_map('intval', $odb->query('SELECT group_id FROM user_groups WHERE user_id = ' . (int)$u['id'])->fetchAll(PDO::FETCH_COLUMN)); unset($u);

function nm($id){ global $units; return isset($units[$id]) ? $units[$id]['name'] : "unit $id"; }
// recipients for a kind (+ unit id, 0 = system-wide)
function rcpt($kind, $uid){
	global $users, $units;
	$o = array();
	foreach ($users as $u) {
		if (empty($u['n_' . $kind])) continue;
		if ($u['role'] === 'admin') $u['scope_all'] = 1;   // admins always see everything
		if ($uid && empty($u['scope_all'])) { $g = (int)($units[$uid]['loadgroupid'] ?? 0); if (!$g || !in_array($g, $u['groups'], true)) continue; }
		if (!$uid && empty($u['scope_all']) && $kind !== 'errors') continue;
		$o[] = $u['email'];
	}
	return $o;
}
$sent = 0;
function notify($kind, $uid, $key, $subject, $body, $extra = array()){
	global $st, $now, $TEST, $odb, $sent;
	$k = "$kind|$uid|$key";
	if (isset($st['last'][$k]) && $now - $st['last'][$k] < 3600) return;   // one per unit per kind per hour
	$to = ($kind === 'reminders') ? $extra : array_merge(rcpt($kind, $uid), $extra);   // reminders: only their own Send to list
	if (!$to) return;
	$body .= "\n\n-- " . oev_site_name() . ", " . date('D M j, g:i a T') . "\n" . oev_site_url();
	if ($TEST) { echo "WOULD SEND [$kind] to " . count($to) . " recipient(s): $subject\n"; return; }
	$r = oev_mail($to, $subject, $body);
	$st['last'][$k] = $now; $sent++;
	try { $odb->prepare('INSERT INTO notifications_sent (evse_id, type) VALUES (?, ?)')->execute(array($uid, $kind . ': ' . $subject . ($r === true ? '' : ' (FAILED)'))); } catch (Exception $e) {}
	@file_put_contents(SESS_DIR . '/notify.log', date('Y-m-d H:i:s') . " $kind u$uid: $subject -> " . count($to) . ' recipient(s)' . ($r === true ? '' : ' FAILED: ' . $r) . "\n", FILE_APPEND | LOCK_EX);
}

$on = ($S['notify_on'] === '1') && oev_mail_ready();
$first = empty($st['init']);   // first run: remember where things are, send nothing about the past
$us = $sdb->query('SELECT * FROM unit_state')->fetchAll(); $usx = array(); foreach ($us as $r) $usx[(int)$r['unit_id']] = $r;

/* ---- errors: unit error states + heartbeat ---- */
$hbmin = max(2, (int)$S['heartbeat_min']);
foreach ($units as $id => $u) {
	if (!$u['enabled'] || !isset($usx[$id])) continue;
	$r = $usx[$id];
	$seen = max((int)$r['last_ok_at'], (int)$r['last_push_at']);
	// error state the unit reports (red on the pages), only from a fresh reading
	$code = $r['state'] === null ? null : (int)$r['state'];
	$err = ($code !== null && $now - $seen < 600 && !in_array($code, array(0, 1, 2, 3, 254, 255), true)) ? evse_state_text($code) : '';
	$was = $st['err'][$id] ?? '';
	if ($err !== '' && $was === '') { if ($on && !$first) notify('errors', $id, 'state', nm($id) . ': ' . $err, nm($id) . " reports: $err.\nCheck the unit and the car."); }
	elseif ($err === '' && $was !== '' && $code !== null) { if ($on && !$first) notify('errors', $id, 'state_ok', nm($id) . ': OK again', nm($id) . " no longer reports \"$was\". It now shows: " . evse_state_text($code) . '.'); }
	$st['err'][$id] = $err;
	// heartbeat
	$quiet = $seen ? $now - $seen : null;
	$down = $quiet !== null && $quiet > $hbmin * 60;
	$wasd = !empty($st['quiet'][$id]);
	if ($down && !$wasd) { if ($on && !$first) notify('errors', $id, 'quiet', nm($id) . ': no word for ' . round($quiet / 60) . ' minutes', 'This server has not heard from ' . nm($id) . ' since ' . date('g:i a', $seen) . ".\nThe unit may be off, or its WiFi may be down."); }
	elseif (!$down && $wasd) { if ($on && !$first) notify('errors', $id, 'quiet_ok', nm($id) . ': back online', nm($id) . ' is reporting again.'); }
	$st['quiet'][$id] = $down ? 1 : 0;
}
/* ---- errors: our own system (sys_lib.php; same lines as Settings > System status) ---- */
$checks = oev_sys_checks(true);
foreach ($checks as $k => $c) {
	if ($k === 'jobs' && strpos($c['text'], 'waited') === false) continue;   // this task is running (it is the one running this): only stuck jobs count
	$bad = ($c['ok'] === false);
	$n = $bad ? (int)($st['sysn'][$k] ?? 0) + 1 : 0; $st['sysn'][$k] = $n;
	$is = $n >= 2; $was = !empty($st['sys'][$k]);
	$txt = html_entity_decode(strip_tags($c['text']), ENT_QUOTES, 'UTF-8');   // sys_lib text is HTML (values marked for the page)
	if ($is && !$was) { if ($on && !$first) notify('errors', 0, $k, 'System problem: ' . $c['title'], $c['title'] . ': ' . $txt . "\n\nSee Settings > System status."); }
	elseif (!$bad && $was) { if ($on && !$first) notify('errors', 0, $k . '_ok', $c['title'] . ': OK again', $c['title'] . ': ' . $txt); }
	if ($is || !$bad) $st['sys'][$k] = $is ? 1 : 0;
}
unset($st['sys']['lb']);   // old keys (before System status)

/* ---- reboots ---- */
$last = (int)($st['reboot_id'] ?? 0);
$maxid = (int)$sdb->query("SELECT coalesce(max(id), 0) FROM events")->fetchColumn();
if (!$first && $on) foreach ($sdb->query("SELECT * FROM events WHERE kind = 'reboot' AND id > $last ORDER BY id")->fetchAll() as $e)
	notify('reboots', (int)$e['unit_id'], 'reboot', nm((int)$e['unit_id']) . ' restarted', nm((int)$e['unit_id']) . ' restarted at about ' . date('g:i a', (int)$e['ts']) . '.' . ($e['detail'] ? "\n" . $e['detail'] : ''));
$st['reboot_id'] = $maxid;

/* ---- session completion (car unplugged) ---- */
$last = (int)($st['session_id'] ?? 0);
$rows = $sdb->query("SELECT * FROM sessions WHERE open = 0 AND unplug_at IS NOT NULL AND id > $last ORDER BY id")->fetchAll();
if (!$first && $on) foreach ($rows as $s) {
	$k = ($s['kwh'] !== null) ? number_format((float)$s['kwh'], 1) . ' kWh' : 'unknown kWh';
	notify('sessions', (int)$s['unit_id'], 'session' . $s['id'], nm((int)$s['unit_id']) . ": session done, $k",
		nm((int)$s['unit_id']) . "\nPlugged in: " . date('D g:i a', (int)$s['plug_in_at']) . "\nUnplugged: " . date('D g:i a', (int)$s['unplug_at']) . "\nEnergy: $k\nCharging time: " . sess_fmt_dur((int)$s['charge_seconds']));
}
$mx = (int)$sdb->query("SELECT coalesce(max(id), 0) FROM sessions WHERE open = 0")->fetchColumn();
$st['session_id'] = max($last, $mx);

/* ---- plug-in reminders ---- */
foreach ($odb->query('SELECT * FROM reminders WHERE enabled = 1')->fetchAll() as $rm) {
	$id = (int)$rm['unit_id']; if (!preg_match('/^(\d{1,2}):(\d{2})$/', (string)$rm['at_time'], $m) || empty($units[$id]['enabled'])) continue;
	$t = mktime((int)$m[1], (int)$m[2], 0);
	$dk = 'rem' . $rm['id'] . '|' . date('Ymd');
	if ($now < $t || $now >= $t + 900 || !empty($st['rem'][$dk])) continue;   // 15-minute window, once a day
	$r = $usx[$id] ?? null;
	if (!$r || $now - max((int)$r['last_ok_at'], (int)$r['last_push_at']) > 600) continue;   // no fresh reading: can't tell
	$st['rem'][$dk] = 1;
	if (!empty($r['connected'])) continue;
	$extra = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)$rm['extra_to'])), function($e){ return filter_var($e, FILTER_VALIDATE_EMAIL); }));
	if ($on && $extra) notify('reminders', $id, 'rem' . $rm['id'] . date('Ymd'), nm($id) . ': not plugged in', nm($id) . ' has no car plugged in, and the plug-in reminder time is ' . date('g:i a', $t) . '.', $extra);
}
if (isset($st['rem'])) foreach (array_keys($st['rem']) as $k) if (substr($k, -8) < date('Ymd', $now - 3 * 86400)) unset($st['rem'][$k]);
if (isset($st['last'])) foreach ($st['last'] as $k => $t) if ($now - $t > 86400) unset($st['last'][$k]);

$st['init'] = 1; $st['ran_at'] = $now;
if (!$TEST) { file_put_contents("$stf.tmp", json_encode($st)); rename("$stf.tmp", $stf); }
elseif ($first) echo "first run: would only record the current state\n";
