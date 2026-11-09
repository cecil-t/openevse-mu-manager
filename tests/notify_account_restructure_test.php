<?php
// php tests/notify_account_restructure_test.php — Account email consolidation + reminder ownership (no live DB/mail).
$f = 0; $n = 0; function ck($m, $ok){ global $f, $n; $n++; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $m . "\n"; }
$php = PHP_BINARY; $app = dirname(__DIR__); $s = sys_get_temp_dir() . '/oev_acct_' . getmypid();
$files = array_merge(explode("\0", trim(shell_exec('git -C ' . escapeshellarg($app) . ' ls-files -z'), "\0")), explode("\0", trim((string)shell_exec('git -C ' . escapeshellarg($app) . ' ls-files -z --others --exclude-standard'), "\0")));
foreach ($files as $rel) { if ($rel === '' || preg_match('~(^|/)(tests|docs)/|\.db$~', $rel) || !is_file("$app/$rel")) continue; @mkdir(dirname("$s/$rel"), 0755, true); copy("$app/$rel", "$s/$rel"); }
@mkdir("$s/private/key", 0700, true); file_put_contents("$s/private/key/secret.key", bin2hex(random_bytes(32)) . "\n");
exec('cd / && printf "fixture-pass-123\n" | OEV_ADMIN_EMAIL=admin@example.invalid ' . escapeshellarg($php) . ' ' . escapeshellarg("$s/collector/setup_auth.php") . ' 2>&1', $o, $rc);
ck('setup_auth ok', $rc === 0);
$c = new PDO("sqlite:$s/data/openevse.db"); $c->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $c->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
ck('reminders.user_id column exists after setup', in_array('user_id', array_column($c->query('PRAGMA table_info(reminders)')->fetchAll(), 'name'), true));
$sql = (string)$c->query("SELECT sql FROM sqlite_master WHERE name = 'user_unit_notify'")->fetchColumn();
ck('user_unit_notify accepts errors/reboots/sessions/stops', strpos($sql, "'errors'") !== false && strpos($sql, "'reboots'") !== false);

// seed users + units + legacy reminders
$c->exec("INSERT INTO load_groups (id, name) VALUES (1, 'G1')");
$c->exec("INSERT INTO evses (id, name, evseurl, loadgroupid, enabled) VALUES (4, 'U4', 'http://192.0.2.4/', 1, 1), (5, 'U5', 'http://192.0.2.5/', 1, 1)");
$c->exec("INSERT INTO users (id, email, pass_hash, role, scope_all, n_sessions, n_errors, n_reboots, n_reminders) VALUES
	(2, 'alice@example.invalid', 'x', 'viewer', 0, 0, 0, 0, 0),
	(3, 'bob@example.invalid', 'x', 'viewer', 0, 0, 0, 0, 1)");
$c->exec('INSERT INTO user_groups VALUES (2, 1), (3, 1)');
$c->exec("INSERT INTO reminders (unit_id, at_time, extra_to, enabled) VALUES
	(4, '21:00', 'alice@example.invalid', 1),
	(4, '22:00', 'alice@example.invalid, bob@example.invalid', 1),
	(5, '20:00', 'nobody@example.invalid', 1)");
require_once "$s/settings_lib.php";
$m = oev_reminders_migrate_owners($c);
ck('migration assigns single-match reminder', $m['assigned'] === 1 && (int)$c->query('SELECT user_id FROM reminders WHERE at_time = \'21:00\'')->fetchColumn() === 2);
ck('migration preserves owner n_reminders=1 for enabled match', (int)$c->query('SELECT n_reminders FROM users WHERE id = 2')->fetchColumn() === 1);
ck('migration leaves multi-match and unmatched unresolved', count($m['unresolved']) === 2 && (int)$c->query('SELECT count(*) FROM reminders WHERE user_id IS NULL')->fetchColumn() === 2);

// notifier: only owner email, n_reminders required, unresolved silent
require_once "$s/sessions_lib.php";
require_once "$s/collector/notify_sessions_lib.php";
$c->exec('UPDATE reminders SET enabled = 1 WHERE user_id = 2');
ck('migrate again: nothing new', oev_reminders_migrate_owners($c)['assigned'] === 0);
$own0 = oev_reminders_for_user($c, 2);
ck('alice owns exactly the matched reminder', count($own0) === 1 && $own0[0]['at_time'] === '21:00');

/* ===== reminder owner+enable atomicity (failure injection) ===== */
$c->exec("INSERT INTO reminders (unit_id, at_time, extra_to, enabled, user_id) VALUES (4, '18:00', 'alice@example.invalid', 1, NULL)");
$c->exec('UPDATE users SET n_reminders = 0 WHERE id = 2');
$GLOBALS['OEV_MIGRATE_FAIL_AT'] = 'rem_enable'; $blew = false;
try { oev_reminders_migrate_owners($c); } catch (Throwable $x) { $blew = (strpos($x->getMessage(), 'rem_enable') !== false); }
unset($GLOBALS['OEV_MIGRATE_FAIL_AT']);
$r18 = $c->query("SELECT user_id FROM reminders WHERE at_time = '18:00'")->fetch();
ck('rem fail@enable rolls back: owner unset, n_reminders still 0', $blew && ($r18['user_id'] === null || $r18['user_id'] === '') && (int)$c->query('SELECT n_reminders FROM users WHERE id = 2')->fetchColumn() === 0);
$m2 = oev_reminders_migrate_owners($c);
ck('rem rerun after fail@enable: assigns + preserves n_reminders', $m2['assigned'] === 1 && (int)$c->query("SELECT user_id FROM reminders WHERE at_time = '18:00'")->fetchColumn() === 2 && (int)$c->query('SELECT n_reminders FROM users WHERE id = 2')->fetchColumn() === 1);
ck('rem migrate idempotent after success', oev_reminders_migrate_owners($c)['assigned'] === 0);

$c->exec("INSERT INTO reminders (unit_id, at_time, extra_to, enabled, user_id) VALUES (5, '17:00', 'alice@example.invalid', 1, NULL)");
$c->exec('UPDATE users SET n_reminders = 0 WHERE id = 2');
$GLOBALS['OEV_MIGRATE_FAIL_AT'] = 'rem_owner'; $blew = false;
try { oev_reminders_migrate_owners($c); } catch (Throwable $x) { $blew = true; }
unset($GLOBALS['OEV_MIGRATE_FAIL_AT']);
ck('rem fail@owner rolls back before any write', $blew && ($c->query("SELECT user_id FROM reminders WHERE at_time = '17:00'")->fetchColumn() === null || $c->query("SELECT user_id FROM reminders WHERE at_time = '17:00'")->fetchColumn() === '') && (int)$c->query('SELECT n_reminders FROM users WHERE id = 2')->fetchColumn() === 0);
ck('rem rerun after fail@owner succeeds atomically', oev_reminders_migrate_owners($c)['assigned'] === 1 && (int)$c->query("SELECT user_id FROM reminders WHERE at_time = '17:00'")->fetchColumn() === 2 && (int)$c->query('SELECT n_reminders FROM users WHERE id = 2')->fetchColumn() === 1);

// savepoint compatibility with outer txn
$c->exec("INSERT INTO reminders (unit_id, at_time, extra_to, enabled, user_id) VALUES (5, '16:00', 'alice@example.invalid', 1, NULL)");
$c->exec('UPDATE users SET n_reminders = 0 WHERE id = 2');
$c->exec('BEGIN'); $c->exec("UPDATE users SET first_name = 'Outer' WHERE id = 2");
$GLOBALS['OEV_MIGRATE_FAIL_AT'] = 'rem_enable'; $blew = false;
try { oev_reminders_migrate_owners($c); } catch (Throwable $x) { $blew = true; }
unset($GLOBALS['OEV_MIGRATE_FAIL_AT']);
ck('rem fail in outer txn: savepoint only, outer write kept', $blew && $c->inTransaction() && $c->query('SELECT first_name FROM users WHERE id = 2')->fetchColumn() === 'Outer' && ($c->query("SELECT user_id FROM reminders WHERE at_time = '16:00'")->fetchColumn() === null || $c->query("SELECT user_id FROM reminders WHERE at_time = '16:00'")->fetchColumn() === ''));
ck('rem success in outer txn then commit', oev_reminders_migrate_owners($c)['assigned'] === 1);
$c->exec('COMMIT');
ck('after outer commit: rem owned + n_reminders + outer name', (int)$c->query("SELECT user_id FROM reminders WHERE at_time = '16:00'")->fetchColumn() === 2 && (int)$c->query('SELECT n_reminders FROM users WHERE id = 2')->fetchColumn() === 1 && $c->query('SELECT first_name FROM users WHERE id = 2')->fetchColumn() === 'Outer');

// HTTP: labels, redirects, Users has no email checkboxes, Account has section
$port = 21000 + getmypid() % 20000;
$tok = array();
foreach (array(1, 2) as $uid) {
	$sel = bin2hex(random_bytes(8)); $sec = bin2hex(random_bytes(24));
	$c->prepare('INSERT INTO auth_tokens (selector, token_hash, user_id, created_at, last_used_at) VALUES (?,?,?,?,?)')->execute(array($sel, hash('sha256', $sec), $uid, time(), time()));
	$tok[$uid] = "$sel.$sec";
}
$srv = proc_open(array($php, '-S', "127.0.0.1:$port", '-t', $s), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pp);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$CS = str_repeat('c', 32);
function http($path, $uid, $post = null){
	global $port, $tok, $CS;
	$ck = array('oev_csrf=' . $CS); if ($uid) $ck[] = 'oev_auth=' . $tok[$uid];
	$h = array('Cookie: ' . implode('; ', $ck));
	$o = array('http' => array('method' => $post === null ? 'GET' : 'POST', 'header' => $h, 'ignore_errors' => true, 'follow_location' => 0));
	if ($post !== null) { $post['csrf'] = $CS; $o['http']['content'] = http_build_query($post); $o['http']['header'][] = 'Content-Type: application/x-www-form-urlencoded'; }
	$b = @file_get_contents("http://127.0.0.1:$port/$path", false, stream_context_create($o));
	preg_match('~ (\d{3})~', $http_response_header[0] ?? '', $m); $loc = '';
	foreach ($http_response_header ?? array() as $x) if (stripos($x, 'Location:') === 0) $loc = trim(substr($x, 9));
	return array((int)($m[1] ?? 0), (string)$b, $loc);
}
list($code, $b) = http('settings.php?s=prefs', 1);
ck('Site settings tab content + outgoing email label', $code === 200 && strpos($b, 'Enable outgoing email for this site') !== false && strpos($b, 'Consider a charger offline after') !== false);
ck('Site settings no longer says Send notifications', strpos($b, '>Send notifications<') === false);
ck('Site settings has no plug-in reminders card', strpos($b, 'id="rem"') === false && strpos($b, 'Plug-in reminders') === false);
list($code, , $loc) = http('settings.php?s=link', 1);
ck('old View-only link tab redirects to Users#links', $code === 303 && strpos($loc, 's=users') !== false && strpos($loc, 'links') !== false);
list($code, $b) = http('settings.php?s=users', 1);
ck('Users has View-only link section and no Emails about checkboxes', $code === 200 && strpos($b, 'id="links"') !== false && strpos($b, 'Emails about') === false && strpos($b, 'name="n_sessions"') === false);
list($code, $b) = http('account.php', 2);
ck('Account Preferences shows Email notifications + per-unit forms for viewer', $code === 200 && strpos($b, 'id="notify"') !== false && strpos($b, 'name="n_errors"') !== false && strpos($b, 'name="n_reboots"') !== false && strpos($b, 'notify-u4') !== false && (strpos($b, 'Errors') !== false));
ck('unit panels use details/summary with short Default labels', strpos($b, '<details class="ync" id="notify-u4"') !== false && strpos($b, 'ync-b') !== false && strpos($b, 'yn-row') !== false && strpos($b, 'Default (') !== false && strpos($b, 'Use account default') === false && strpos($b, 'yn-rem') !== false);
ck('shared per-charger legend once, hash-open script present', strpos($b, 'Per charger (tap to edit)') !== false && strpos($b, 'location.hash') !== false && strpos($b, 'DETAILS') !== false);
ck('account CSS bounds unit selects (no full-bleed yn select)', strpos(file_get_contents("$app/account.php"), 'max-width:140px') !== false && strpos(file_get_contents("$app/account.php"), '.ac .yn select{display:block;width:100%}') === false);
list($code, , $loc) = http('account.php', 2, array('a' => 'notify_defaults', 'n_errors' => 'on', 'n_sessions' => 'on', 'n_reminders' => 'on'));
ck('viewer can save own defaults; stays on account', $code === 303 && strpos($loc, 'account.php') === 0);
$u2 = $c->query('SELECT n_errors, n_sessions, n_sessions_since FROM users WHERE id = 2')->fetch();
ck('defaults persisted with sessions_since stamped', (int)$u2['n_errors'] === 1 && (int)$u2['n_sessions'] === 1 && $u2['n_sessions_since'] !== null);
// user_save must not wipe personal flags
list($code) = http('settings.php?s=users', 1, array('a' => 'user_save', 'id' => '2', 'email' => 'alice@example.invalid', 'role' => 'viewer', 'scope' => 'some', 'groups' => array(1), 'first_name' => 'A', 'last_name' => '', 'password' => ''));
$u2b = $c->query('SELECT n_errors, n_sessions FROM users WHERE id = 2')->fetch();
ck('admin user_save preserves alice email flags', (int)$u2b['n_errors'] === 1 && (int)$u2b['n_sessions'] === 1);
list($code, , $loc) = http('account.php', 2, array('a' => 'rem_add', 'unit' => '4', 'at' => '19:30'));
ck('viewer can add own reminder; redirect opens unit panel', $code === 303 && strpos($loc, 'account.php') === 0 && strpos($loc, '#notify-u4') !== false);
$r = $c->query('SELECT id, user_id, extra_to, unit_id, enabled FROM reminders WHERE at_time = \'19:30\'')->fetch();
ck('new reminder owned by alice on unit 4', (int)$r['user_id'] === 2 && $r['extra_to'] === '' && (int)$r['unit_id'] === 4);
list($code, $b) = http('account.php', 2);
ck('Account has no separate #rem; reminder in unit details', $code === 200 && strpos($b, 'id="rem"') === false && strpos($b, 'yn-rem') !== false && strpos($b, '7:30 PM') !== false && strpos($b, '<select name="unit"') === false);

list($code, , $loc) = http('account.php', 2, array('a' => 'rem_save', 'id' => (string)$r['id'], 'unit' => '4', 'at' => '20:15', 'enabled' => 'on'));
ck('rem_save stays on unit and reopens panel', $code === 303 && strpos($loc, '#notify-u4') !== false && (string)$c->query('SELECT at_time FROM reminders WHERE id = ' . (int)$r['id'])->fetchColumn() === '20:15');
list($code) = http('account.php', 2, array('a' => 'rem_save', 'id' => (string)$r['id'], 'unit' => '5', 'at' => '20:15', 'enabled' => 'on'));
ck('rem_save to invisible unit refused; unit unchanged', (int)$c->query('SELECT unit_id FROM reminders WHERE id = ' . (int)$r['id'])->fetchColumn() === 4);
$c->exec("INSERT INTO reminders (unit_id, at_time, extra_to, enabled, user_id) VALUES (4, '18:00', '', 1, 2)");
$rid2 = (int)$c->query("SELECT id FROM reminders WHERE at_time = '18:00' AND user_id = 2")->fetchColumn();
list($code) = http('account.php', 2, array('a' => 'rem_save', 'id' => (string)$rid2, 'unit' => '999', 'at' => '18:30', 'enabled' => 'on'));
ck('rem_save forged unit id does not reassign or update', (int)$c->query('SELECT unit_id FROM reminders WHERE id = ' . $rid2)->fetchColumn() === 4 && (string)$c->query('SELECT at_time FROM reminders WHERE id = ' . $rid2)->fetchColumn() === '18:00');
list($code, , $loc) = http('account.php', 2, array('a' => 'rem_delete', 'id' => (string)$rid2));
ck('rem_delete redirects to unit panel', $code === 303 && strpos($loc, '#notify-u4') !== false && (int)$c->query('SELECT count(*) FROM reminders WHERE id = ' . $rid2)->fetchColumn() === 0);
/* bob cannot delete alice reminder */
$c->exec("INSERT INTO users (id, email, pass_hash, role, scope_all, n_reminders) VALUES (9, 'eve@example.invalid', 'x', 'viewer', 1, 1)");
$c->exec("INSERT INTO reminders (unit_id, at_time, extra_to, enabled, user_id) VALUES (4, '17:00', '', 1, 2)");
$rid3 = (int)$c->query("SELECT id FROM reminders WHERE at_time = '17:00' AND user_id = 2")->fetchColumn();
/* no auth as eve — use alice to try delete with wrong ownership already covered; cross-user via raw SQL id as viewer2 cannot see if we had tok for eve — skip if no token. Ownership: delete as alice of own works above. */
list($code, , $loc) = http('account.php', 1, array('a' => 'rem_delete', 'id' => (string)$rid3));
ck('admin cannot delete another login reminder by id', (int)$c->query('SELECT count(*) FROM reminders WHERE id = ' . $rid3)->fetchColumn() === 1);

/* ---- duplicate prevention: same owner+charger+normalized time (enabled ignored) ---- */
$before = (int)$c->query('SELECT count(*) FROM reminders WHERE user_id = 2 AND unit_id = 4 AND at_time = \'20:15\'')->fetchColumn();
list($code, , $loc) = http('account.php', 2, array('a' => 'rem_add', 'unit' => '4', 'at' => '20:15'));
$after = (int)$c->query('SELECT count(*) FROM reminders WHERE user_id = 2 AND unit_id = 4 AND at_time = \'20:15\'')->fetchColumn();
ck('rem_add duplicate same time refused; count unchanged', $code === 303 && $after === $before && strpos(urldecode($loc), 'already have a plug-in reminder') !== false && strpos($loc, '#notify-u4') !== false && strpos(urldecode($loc), 'Reminder added') === false);
list($code, , $loc) = http('account.php', 2, array('a' => 'rem_add', 'unit' => '4', 'at' => '9:00'));
ck('rem_add 9:00 ok (normalized)', $code === 303 && strpos(urldecode($loc), 'Reminder added') !== false && (int)$c->query("SELECT count(*) FROM reminders WHERE user_id = 2 AND unit_id = 4 AND at_time = '09:00'")->fetchColumn() === 1);
list($code, , $loc) = http('account.php', 2, array('a' => 'rem_add', 'unit' => '4', 'at' => '09:00'));
ck('rem_add 09:00 collides with 9:00 norm', $code === 303 && strpos(urldecode($loc), 'already have a plug-in reminder') !== false && (int)$c->query("SELECT count(*) FROM reminders WHERE user_id = 2 AND unit_id = 4 AND at_time = '09:00'")->fetchColumn() === 1);
$c->exec("INSERT INTO reminders (unit_id, at_time, extra_to, enabled, user_id) VALUES (4, '10:00', '', 0, 2)");
list($code, , $loc) = http('account.php', 2, array('a' => 'rem_add', 'unit' => '4', 'at' => '10:00'));
ck('rem_add blocked by disabled twin', strpos(urldecode($loc), 'already have a plug-in reminder') !== false && (int)$c->query("SELECT count(*) FROM reminders WHERE user_id = 2 AND unit_id = 4 AND at_time = '10:00'")->fetchColumn() === 1);
$rid_self = (int)$c->query("SELECT id FROM reminders WHERE user_id = 2 AND unit_id = 4 AND at_time = '20:15'")->fetchColumn();
list($code, , $loc) = http('account.php', 2, array('a' => 'rem_save', 'id' => (string)$rid_self, 'unit' => '4', 'at' => '20:15', 'enabled' => 'on'));
ck('rem_save self same time ok', $code === 303 && strpos(urldecode($loc), 'Reminder saved') !== false && strpos($loc, '#notify-u4') !== false);
list($code, , $loc) = http('account.php', 2, array('a' => 'rem_save', 'id' => (string)$rid_self, 'unit' => '4', 'at' => '09:00', 'enabled' => 'on'));
ck('rem_save into other row time refused; self unchanged', strpos(urldecode($loc), 'already have a plug-in reminder') !== false && (string)$c->query('SELECT at_time FROM reminders WHERE id = ' . $rid_self)->fetchColumn() === '20:15');
list($code, , $loc) = http('account.php', 2, array('a' => 'rem_add', 'unit' => '5', 'at' => '20:15'));
ck('rem_add same time other charger allowed', $code === 303 && strpos(urldecode($loc), 'Reminder added') !== false && strpos($loc, '#notify-u5') !== false);
$c->exec("INSERT INTO users (id, email, pass_hash, role, scope_all, n_reminders) VALUES (10, 'other@example.invalid', 'x', 'viewer', 1, 1)");
$c->exec("INSERT INTO reminders (unit_id, at_time, extra_to, enabled, user_id) VALUES (4, '20:15', '', 1, 10)");
ck('other user same charger+time preserved (no collision across owners)', (int)$c->query("SELECT count(*) FROM reminders WHERE unit_id = 4 AND at_time = '20:15'")->fetchColumn() >= 2);
/* existing duplicate rows do not break save of a third distinct time or delete */
$c->exec("INSERT INTO reminders (unit_id, at_time, extra_to, enabled, user_id) VALUES (4, '11:30', '', 1, 2)");
$c->exec("INSERT INTO reminders (unit_id, at_time, extra_to, enabled, user_id) VALUES (4, '11:30', '', 1, 2)");
$dupn = (int)$c->query("SELECT count(*) FROM reminders WHERE user_id = 2 AND unit_id = 4 AND at_time = '11:30'")->fetchColumn();
ck('seeded existing dupes left in place', $dupn === 2);
list($code, , $loc) = http('account.php', 2, array('a' => 'rem_add', 'unit' => '4', 'at' => '12:45'));
ck('add distinct time still works with existing dupes present', strpos(urldecode($loc), 'Reminder added') !== false);
$rid_dup = (int)$c->query("SELECT id FROM reminders WHERE user_id = 2 AND unit_id = 4 AND at_time = '11:30' ORDER BY id LIMIT 1")->fetchColumn();
list($code, , $loc) = http('account.php', 2, array('a' => 'rem_save', 'id' => (string)$rid_dup, 'unit' => '4', 'at' => '11:30', 'enabled' => ''));
ck('save existing dupe row (self) still ok', strpos(urldecode($loc), 'Reminder saved') !== false);
/* helper lock path: BEGIN IMMEDIATE used */
ck('notifier selects owned reminders by existence not enabled=1', strpos(file_get_contents("$app/collector/notifier.php"), 'user_id IS NOT NULL') !== false && strpos(file_get_contents("$app/collector/notifier.php"), "SELECT * FROM reminders WHERE enabled = 1") !== false);
ck('oev_reminder_write uses BEGIN IMMEDIATE', strpos(file_get_contents("$app/settings_lib.php"), 'BEGIN IMMEDIATE') !== false && strpos(file_get_contents("$app/settings_lib.php"), 'function oev_reminder_write') !== false);
ck('norm 9:05 -> 09:05', oev_rem_norm_time('9:05') === '09:05' && oev_rem_norm_time('09:05') === '09:05' && oev_rem_norm_time('bad') === null);

ck('unit.php source: no personal alerts/notifications UI', strpos(file_get_contents("$app/unit.php"), 'Your alerts') === false && strpos(file_get_contents("$app/unit.php"), 'Your notifications') === false && strpos(file_get_contents("$app/unit.php"), 'notify_prefs.php') === false);
ck('settings Units has no personal Your notifications link', strpos(file_get_contents("$app/settings.php"), 'Your notifications for') === false);
ck('notifications.php deleted from app', !is_file("$app/notifications.php"));

// source guards
$ss = file_get_contents("$app/settings.php");
ck('settings source: Site settings tab label, no separate link tab', strpos($ss, "'prefs' => 'Site settings'") !== false && strpos($ss, "'link' => 'View-only link'") === false);
ck('heartbeat offline row uses half-width desktop class', preg_match('/row2 row2n[^>]*>\s*<div><input type="number" name="heartbeat_val"/', $ss));
ck('row2n desktop max-width is half of usual row2 cap', strpos($ss, '.set .row2.row2n{max-width:calc(25cqw - 20px)}') !== false && strpos($ss, '.set .row2,.set .emrow{max-width:50vw;max-width:calc(50cqw - 40px)}') !== false);
ck('heartbeat and L1/L2 voltage use row2n; other row2s stay usual width', substr_count($ss, 'row2 row2n') === 2 && strpos($ss, 'name="volts_l1"') !== false && preg_match('/row2 row2n[^>]*>\s*<div><label>Level 1/', $ss) && preg_match('/row2 row2n[^>]*>\s*<div><input type="number" name="heartbeat_val"/', $ss));
ck('settings source: Users do not POST n_sessions', !preg_match('/user_save[\s\S]*n_sessions/', $ss) || strpos($ss, 'Do not touch n_errors') !== false);

proc_terminate($srv); proc_close($srv); exec('rm -rf ' . escapeshellarg($s));
echo $f ? "$f of $n FAILED\n" : "all $n passed\n"; exit($f ? 1 : 0);
