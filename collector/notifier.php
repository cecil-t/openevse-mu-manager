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
 *  - sessions:  Session summary: once per plug-in, after unplug (kWh and time)
 *  - stops:     Charging stopped: once per plug-in, the first real stop while still
 *               plugged in (limit, schedule, manual, fault, vehicle stopped drawing);
 *               not load-balancer pauses. Off for everyone until each user turns it on.
 *  - reminders: Plug-in reminders: a unit has no car plugged in by its set time.
 *               Sent only to the reminder's own "Send to" addresses (not to users).
 * Errors/reboots/reminders: at most one email per unit per kind per hour (sessions/stops: once per plug-in, see the lib). State: collector/notify_state.json.
 ******************************************************************************/
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('SESS_DIR', __DIR__);
define('OEV_SITE', dirname(__DIR__));
chdir(OEV_SITE);
if (is_file(OEV_SITE . '/data/cfg_db_maint')) { @file_put_contents(SESS_DIR . '/notify.log', date('Y-m-d H:i:s') . " INFO config DB maintenance; notifier skipped\n", FILE_APPEND | LOCK_EX); exit(0); }
if (is_file(OEV_SITE . '/private/sess_writers_pause')) { @file_put_contents(SESS_DIR . '/notify.log', date('Y-m-d H:i:s') . " INFO writers paused; notifier skipped\n", FILE_APPEND | LOCK_EX); exit(0); }
require OEV_SITE . '/include_v5.php';
require OEV_SITE . '/include_lb.php';
require OEV_SITE . '/sessions_lib.php';
require OEV_SITE . '/mail_lib.php';
require OEV_SITE . '/sys_lib.php';
require __DIR__ . '/notify_sessions_lib.php';
// one notifier at a time (the every-minute task, a manual run, --dry): a second one exits at once
$lockh = fopen(SESS_DIR . '/notifier.lock', 'c');
if (!$lockh || !flock($lockh, LOCK_EX | LOCK_NB)) { @file_put_contents(SESS_DIR . '/notify.log', date('Y-m-d H:i:s') . " INFO another notifier run is busy; skipped\n", FILE_APPEND | LOCK_EX); exit(0); }
$TEST = in_array('--dry', $argv);   // print what would be sent, send nothing, keep state

$S = oev_settings();
$stf = SESS_DIR . '/notify_state.json';
$raw = @file_get_contents($stf);
$st = @json_decode((string)$raw, true);
if (!is_array($st)) {
	if ($raw !== false && trim((string)$raw) !== '') {   // damaged: keep it for a look, start over like a first run (records the present, mails nothing old)
		$bad = $stf . '.bad-' . date('Ymd-His'); @rename($stf, $bad);
		@file_put_contents(SESS_DIR . '/notify.log', date('Y-m-d H:i:s') . " ERROR notify_state.json unreadable; moved to " . basename($bad) . ", starting over (queued emails in it are lost)\n", FILE_APPEND | LOCK_EX);
	}
	$st = array();
}
// atomic save (notify_sessions_lib.php ns_write_atomic). A failed save throws: the run stops there (fail closed:
// nothing more is sent this run, the previous state file stays intact) and the error is logged.
function ns_save_state(){
	global $st, $stf, $TEST;
	if ($TEST) return;
	$j = json_encode($st); if ($j === false) throw new RuntimeException('state not encodable: ' . json_last_error_msg());
	ns_write_atomic($stf, $j);
}
set_exception_handler(function($x){ @file_put_contents(SESS_DIR . '/notify.log', date('Y-m-d H:i:s') . ' ERROR notifier stopped: ' . $x->getMessage() . "\n", FILE_APPEND | LOCK_EX); fwrite(STDERR, 'notifier stopped: ' . $x->getMessage() . "\n"); exit(1); });
$now = time();
$odb = oev_cfg_db_open(array(PDO::ATTR_TIMEOUT => 10, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
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

/* ---- session summary (unplugged) and charging stopped (still plugged in): collector/notify_sessions_lib.php ---- */
// once per plug-in each, per recipient, retried on a failed send; not subject to the one-per-hour rule below
$nsend = function($kind){ return function($email, $subject, $body) use ($kind){
	global $TEST, $odb, $sent;
	$body .= "\n\n-- " . oev_site_name() . ", " . date('D M j, g:i a T') . "\n" . oev_site_url();
	if ($TEST) { echo "WOULD SEND [$kind] to 1 recipient: $subject\n"; return true; }
	$r = oev_mail(array($email), $subject, $body); $sent++;   // the queue entry is saved before and after this call
	try { $odb->prepare('INSERT INTO notifications_sent (evse_id, type) VALUES (?, ?)')->execute(array(0, $kind . ': ' . $subject . ($r === true ? '' : ' (FAILED)'))); } catch (Exception $e) {}
	@file_put_contents(SESS_DIR . '/notify.log', date('Y-m-d H:i:s') . " $kind: $subject -> 1 recipient" . ($r === true ? '' : ' FAILED: ' . $r) . "\n", FILE_APPEND | LOCK_EX);
	return $r;
}; };
$nlog = function($m){ @file_put_contents(SESS_DIR . '/notify.log', date('Y-m-d H:i:s') . " INFO $m\n", FILE_APPEND | LOCK_EX); };
if (ns_baseline($st, $sdb, $now, 'ns_save_state')) $nlog('session emails: baseline (nothing before now is mailed)');
ns_stops($sdb, $users, $units, $st, $now, $on, $nsend('stops'), 'ns_save_state', $nlog);       // a closed session's stop is decided before its summary
ns_summaries($sdb, $users, $units, $st, $now, $on, $nsend('sessions'), 'ns_save_state', $nlog);

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
ns_save_state();
if ($TEST && $first) echo "first run: would only record the current state\n";
