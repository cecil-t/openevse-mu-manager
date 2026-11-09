<?php
// php tests/reminder_days_test.php — weekday mask for plug-in reminders (no live DB/mail).
$f = 0; $n = 0; function ck($m, $ok){ global $f, $n; $n++; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $m . "\n"; }
$app = dirname(__DIR__);
require_once "$app/settings_lib.php";

ck('OEV_REM_DAYS_ALL is 127', defined('OEV_REM_DAYS_ALL') && OEV_REM_DAYS_ALL === 127);
ck('missing/empty row days → 127', oev_rem_days_get(array()) === 127 && oev_rem_days_get(array('days' => null)) === 127 && oev_rem_days_get(array('days' => '')) === 127);
ck('zero/corrupt days → 127 (legacy safe)', oev_rem_days_get(array('days' => 0)) === 127);
ck('norm list Mon-Wed = 14', oev_rem_days_norm(array('1','2','3')) === 14);
ck('norm int 62 kept', oev_rem_days_norm(62) === 62);
ck('norm empty / no valid → null', oev_rem_days_norm(array()) === null && oev_rem_days_norm(array('9','x')) === null && oev_rem_days_norm(0) === null);
ck('norm ignores forged day indexes', oev_rem_days_norm(array('1', 99, 'foo', '2')) === 6);
ck('label Daily/Weekdays/Weekends/range/list',
	oev_rem_days_label(127) === 'Daily' && oev_rem_days_label(62) === 'Weekdays'
	&& oev_rem_days_label(65) === 'Weekends' && oev_rem_days_label(14) === 'Mon-Wed'
	&& oev_rem_days_label(21) === 'Sun,Tue,Thu');   // 1+4+16=21

// Weekday gating (date('w'): 0=Sun … 6=Sat)
ck('weekdays active Wed, not Sun', oev_rem_days_active(62, 3) && !oev_rem_days_active(62, 0));
ck('weekends active Sat+Sun only', oev_rem_days_active(65, 0) && oev_rem_days_active(65, 6) && !oev_rem_days_active(65, 1));
ck('Daily active every w', oev_rem_days_active(127, 0) && oev_rem_days_active(127, 3) && oev_rem_days_active(127, 6));

// Midnight edge: Sunday 23:50 vs Monday 00:10 — mask weekdays (Mon-Fri) must flip with date('w',$now)
$sun = strtotime('2026-11-08 23:50:00'); // known Sunday
$mon = strtotime('2026-11-09 00:10:00'); // Monday
ck('fixture Sun/Mon timestamps', (int)date('w', $sun) === 0 && (int)date('w', $mon) === 1);
ck('weekdays: silent late Sunday', !oev_rem_days_active(62, (int)date('w', $sun)));
ck('weekdays: active just after Monday midnight', oev_rem_days_active(62, (int)date('w', $mon)));
ck('Sun-only: active late Sunday, silent Monday 00:10', oev_rem_days_active(1, (int)date('w', $sun)) && !oev_rem_days_active(1, (int)date('w', $mon)));

// Schema + write on temp DB
$tmp = sys_get_temp_dir() . '/oev_rem_days_' . getmypid();
@mkdir($tmp); $dbf = "$tmp/cfg.db";
$c = new PDO('sqlite:' . $dbf); $c->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$c->exec('CREATE TABLE reminders (id INTEGER PRIMARY KEY, unit_id INTEGER NOT NULL, at_time TEXT NOT NULL, extra_to TEXT, enabled INTEGER DEFAULT 1, user_id INTEGER)');
ck('days col absent initially', !oev_reminders_have_days($c));
ck('days_col adds DEFAULT 127', oev_reminders_days_col($c) === true && oev_reminders_have_days($c));
ck('days_col idempotent', oev_reminders_days_col($c) === false);
$c->exec("INSERT INTO reminders (unit_id, at_time, extra_to, enabled, user_id) VALUES (4, '21:00', '', 1, 2)");
// SQLite fills DEFAULT for ADD COLUMN on existing rows
$d = (int)$c->query('SELECT days FROM reminders WHERE id = 1')->fetchColumn();
ck('existing row got days=127 after ADD COLUMN', $d === 127);

list($ok, $msg, $norm, $au) = oev_reminder_write($c, 2, 4, '20:00', 1, 0, array('1','2','3','4','5'));
ck('write weekdays ok', $ok && $norm === '20:00' && $au === 4);
$row = $c->query("SELECT days FROM reminders WHERE at_time = '20:00'")->fetch(PDO::FETCH_ASSOC);
ck('persisted weekdays mask 62', (int)$row['days'] === 62);

list($ok2, $msg2) = oev_reminder_write($c, 2, 4, '19:00', 1, 0, array());
ck('write with no days refused', !$ok2 && strpos($msg2, 'at least one day') !== false);

list($ok3, $msg3) = oev_reminder_write($c, 2, 4, '19:00', 1, 0, array('9', 'x'));
ck('write with only forged days refused', !$ok3 && strpos($msg3, 'at least one day') !== false);

list($ok4, $msg4) = oev_reminder_write($c, 2, 4, '20:00', 1, 0, array('0','6'));
ck('duplicate time still refused even with different days', !$ok4 && strpos($msg4, 'already have') !== false);

$rid = (int)$c->query("SELECT id FROM reminders WHERE at_time = '20:00'")->fetchColumn();
list($ok5, $msg5) = oev_reminder_write($c, 2, 4, '20:00', 1, $rid, array('0','6'));
ck('update same time to weekends ok', $ok5 && (int)$c->query("SELECT days FROM reminders WHERE id = $rid")->fetchColumn() === 65);

// UI helpers (library only — do not run notify_prefs page bootstrap)
if (!defined('UNP_LIB_ONLY')) define('UNP_LIB_ONLY', 1);
require_once "$app/notify_prefs.php";
ck('summary label includes days', unp_rem_summary_label(array('at_time'=>'21:00','days'=>62)) === '9:00 PM Weekdays');
$html = unp_rem_days_html(62, 'days[]', 't_');
ck('days html has 7 checkboxes; Mon-Fri checked, Sun/Sat not',
	substr_count($html, 'type="checkbox"') === 7
	&& strpos($html, 'value="0"') !== false && strpos($html, 'value="0" checked') === false
	&& strpos($html, 'value="1" checked') !== false && strpos($html, 'value="5" checked') !== false
	&& strpos($html, 'value="6" checked') === false);

// notifier source gate
$src = file_get_contents("$app/collector/notifier.php");
ck('notifier calls oev_rem_days_active with date(w)', strpos($src, 'oev_rem_days_active') !== false && strpos($src, "date('w', \$now)") !== false);

// Simulate notifier weekday skip (mirror loop fragment)
$sent = array();
$run = function($now, $days) use (&$sent) {
	$rm = array('id' => 9, 'unit_id' => 4, 'at_time' => '09:00', 'days' => $days, 'user_id' => 2);
	if (!preg_match('/^(\d{1,2}):(\d{2})$/', $rm['at_time'], $m)) return;
	$t = mktime((int)$m[1], (int)$m[2], 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
	if ($now < $t || $now >= $t + 900) return;
	if (!oev_rem_days_active(oev_rem_days_get($rm), (int)date('w', $now))) return;
	$sent[] = date('Y-m-d D', $now);
};
$sent = array();
$run(strtotime('2026-11-09 09:05:00'), 62); // Mon weekdays
$run(strtotime('2026-11-08 09:05:00'), 62); // Sun weekdays — skip
ck('sim: weekdays fires Monday 09:05 only', $sent === array('2026-11-09 Mon'));

@unlink($dbf); @rmdir($tmp);
echo $f ? "$f of $n FAILED\n" : "all $n passed\n";
exit($f ? 1 : 0);
