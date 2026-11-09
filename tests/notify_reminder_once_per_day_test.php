<?php
// php tests/notify_reminder_once_per_day_test.php — isolated: once-a-day rem key is per reminder id
// (14:00 fire must not block a separate 21:00). Captured transport; no SMTP.
$f = 0; $n = 0; function ck($m, $ok){ global $f, $n; $n++; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $m . "\n"; }

$src = file_get_contents(__DIR__ . '/../collector/notifier.php');
ck('source: owned reminders by existence (user_id set)', strpos($src, 'user_id IS NOT NULL') !== false);
ck('source: daily key includes reminder id', strpos($src, "\$dk = 'rem' . \$rm['id'] . '|' . date('Ymd')") !== false);
ck('source: notify key includes reminder id', strpos($src, "notify('reminders', \$id, 'rem' . \$rm['id'] . date('Ymd')") !== false);
ck('source: 15-minute window is +900s after that row\'s time', strpos($src, '$now >= $t + 900') !== false);

// Simulate the reminder loop body with controllable $now and captured sends (mirrors notifier.php).
$sent = array();
$st = array('init' => 1, 'rem' => array(), 'last' => array());
$ymd = '20261108';
$day = strtotime($ymd . ' 00:00:00');
$units = array(4 => array('id' => 4, 'name' => 'U4', 'enabled' => 1, 'loadgroupid' => 1));
$ou = array('id' => 2, 'email' => 'alice@example.invalid', 'n_reminders' => 1, 'role' => 'viewer', 'scope_all' => 1, 'groups' => array(1));
$rems = array(
	array('id' => 101, 'unit_id' => 4, 'at_time' => '14:00', 'enabled' => 1, 'user_id' => 2),
	array('id' => 202, 'unit_id' => 4, 'at_time' => '21:00', 'enabled' => 1, 'user_id' => 2),
);
$usx = array(4 => array('last_ok_at' => $day + 13 * 3600, 'last_push_at' => $day + 13 * 3600, 'connected' => 0));

$run = function($now) use (&$st, &$sent, $rems, $units, $ou, &$usx, $ymd) {
	foreach ($rems as $rm) {
		$id = (int)$rm['unit_id'];
		if (!preg_match('/^(\d{1,2}):(\d{2})$/', (string)$rm['at_time'], $m) || empty($units[$id]['enabled'])) continue;
		if (empty($ou['n_reminders']) || empty($ou['email'])) continue;
		$t = mktime((int)$m[1], (int)$m[2], 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
		$dk = 'rem' . $rm['id'] . '|' . date('Ymd', $now);
		if ($now < $t || $now >= $t + 900 || !empty($st['rem'][$dk])) continue;
		$r = $usx[$id] ?? null;
		if (!$r || $now - max((int)$r['last_ok_at'], (int)$r['last_push_at']) > 600) continue;
		$st['rem'][$dk] = 1;
		if (!empty($r['connected'])) continue;
		$key = 'rem' . $rm['id'] . date('Ymd', $now);
		$k = "reminders|$id|$key";
		if (isset($st['last'][$k]) && $now - $st['last'][$k] < 3600) continue;
		$st['last'][$k] = $now;
		$sent[] = array('at' => $rm['at_time'], 'id' => (int)$rm['id'], 'to' => $ou['email'], 'dk' => $dk, 'key' => $k);
	}
};

// 14:05 — afternoon reminder should fire
$usx[4]['last_ok_at'] = $usx[4]['last_push_at'] = $day + 14 * 3600 + 5 * 60;
$run($day + 14 * 3600 + 5 * 60);
ck('14:05 sends the 14:00 reminder once', count($sent) === 1 && $sent[0]['at'] === '14:00' && $sent[0]['id'] === 101);
ck('14:00 daily key is rem101|Ymd', $sent[0]['dk'] === 'rem101|' . $ymd);

// same window again — same reminder suppressed
$n1 = count($sent);
$run($day + 14 * 3600 + 10 * 60);
ck('same 14:00 reminder not re-sent same day', count($sent) === $n1);

// 21:05 — evening reminder must still send (independent daily key)
$usx[4]['last_ok_at'] = $usx[4]['last_push_at'] = $day + 21 * 3600 + 5 * 60;
$run($day + 21 * 3600 + 5 * 60);
ck('21:05 still sends separate 21:00 after 14:00 already fired', count($sent) === 2 && $sent[1]['at'] === '21:00' && $sent[1]['id'] === 202);
ck('21:00 daily key is rem202|Ymd (not shared with 14:00)', $sent[1]['dk'] === 'rem202|' . $ymd && $sent[1]['dk'] !== $sent[0]['dk']);
ck('both captured to owner email; no SMTP', $sent[0]['to'] === 'alice@example.invalid' && $sent[1]['to'] === 'alice@example.invalid');

// keys present in state independently
ck('state keeps both rem daily marks', !empty($st['rem']['rem101|' . $ymd]) && !empty($st['rem']['rem202|' . $ymd]));

echo $f ? "$f of $n FAILED\n" : "all $n passed\n";
exit($f ? 1 : 0);
