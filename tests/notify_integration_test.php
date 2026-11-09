<?php
// php tests/notify_integration_test.php — real setup_auth.php + real collector/notifier.php on a throwaway copy of the
// app (synthetic DBs and key; SMTP not set up, so no email can go out). Checks the new users columns, the
// notifier's first run (baseline) and later runs, and that a closed session is recorded once.
$f = 0; function ck($n, $ok){ global $f; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . "\n"; }
$php = PHP_BINARY; $app = dirname(__DIR__); $s = sys_get_temp_dir() . '/oev_notify_' . getmypid();
$files = array_merge(explode("\0", trim(shell_exec('git -C ' . escapeshellarg($app) . ' ls-files -z'), "\0")), explode("\0", trim((string)shell_exec('git -C ' . escapeshellarg($app) . ' ls-files -z --others --exclude-standard'), "\0")));
foreach ($files as $rel) { if ($rel === '' || preg_match('~(^|/)(tests|docs)/|\.db$~', $rel) || !is_file("$app/$rel")) continue; @mkdir(dirname("$s/$rel"), 0755, true); copy("$app/$rel", "$s/$rel"); }
@mkdir("$s/private/key", 0700, true); file_put_contents("$s/private/key/secret.key", bin2hex(random_bytes(32)) . "\n");
exec('cd / && printf "fixture-pass-123\n" | OEV_ADMIN_EMAIL=admin@example.invalid ' . escapeshellarg($php) . ' ' . escapeshellarg("$s/collector/setup_auth.php") . ' 2>&1', $o, $rc);
ck('setup_auth ok', $rc === 0); if ($rc) echo implode("\n", $o), "\n";
ck('setup_auth adds the Charging stopped columns', strpos(implode("\n", $o), 'users.n_stops added') !== false);
ck('setup_auth adds the per-unit notifications table (empty)', strpos(implode("\n", $o), 'user_unit_notify') !== false);
$c = new PDO("sqlite:$s/data/openevse.db"); $u = $c->query('SELECT * FROM users')->fetch(PDO::FETCH_ASSOC);
ck('first admin: Charging stopped off', (int)$u['n_stops'] === 0 && $u['n_stops_since'] === null);
$o = array(); exec('cd / && printf "x\n" | OEV_SKIP_ADMIN=1 ' . escapeshellarg($php) . ' ' . escapeshellarg("$s/collector/setup_auth.php") . ' 2>&1', $o, $rc);
ck('setup_auth again: ok, nothing added', $rc === 0 && strpos(implode("\n", $o), 'n_stops added') === false && strpos(implode("\n", $o), 'user_unit_notify added') === false);
$c = new PDO("sqlite:$s/data/openevse.db"); ck('per-unit table empty after setup (everyone on account defaults)', (int)$c->query('SELECT count(*) FROM user_unit_notify')->fetchColumn() === 0);
$c->exec("INSERT INTO user_unit_notify (user_id, unit_id, kind, mode, since, updated_at) VALUES (1, 1, 'sessions', 'off', NULL, 1)");   // the notifier must load it without error
$c->exec("UPDATE users SET n_sessions = 1, n_stops = 1, n_stops_since = 1 WHERE id = 1"); $c = null;
$d = new PDO("sqlite:$s/data/sessions.db"); $now = time();
$d->exec("INSERT INTO sessions (unit_id, unit_name, plug_in_at, unplug_at, kwh, charge_seconds, open) VALUES (1, 'A', " . ($now - 9000) . ', ' . ($now - 8000) . ', 1.0, 600, 0)');
$d = null;
$run = function($dry = false) use ($php, $s){ $o = array(); exec('cd / && ' . escapeshellarg($php) . ' ' . escapeshellarg("$s/collector/notifier.php") . ($dry ? ' --dry' : '') . ' 2>&1', $o, $rc); return array($rc, implode("\n", $o)); };
list($rc, $o) = $run(true); ck('notifier --dry runs', $rc === 0); if ($rc) echo "$o\n";
list($rc, $o) = $run(); ck('notifier first run ok', $rc === 0); if ($rc) echo "$o\n";
$st = json_decode((string)@file_get_contents("$s/collector/notify_state.json"), true);
ck('state: baseline recorded, old session done', !empty($st['ns_v']) && (int)$st['sum_floor'] >= 1 && (int)$st['session_id'] >= 1);
$d = new PDO("sqlite:$s/data/sessions.db");
$d->exec("INSERT INTO sessions (unit_id, unit_name, plug_in_at, unplug_at, kwh, charge_seconds, open) VALUES (1, 'A', " . ($now - 100) . ', ' . ($now + 5) . ', 1.0, 60, 0)'); $d = null;
list($rc, $o) = $run(); $st = json_decode((string)@file_get_contents("$s/collector/notify_state.json"), true);
ck('second run ok; new session handled (mail not set up: recorded, nothing sent)', $rc === 0 && (int)$st['sum_floor'] >= 2);
$log = (string)@file_get_contents("$s/collector/notify.log");
ck('log: baseline noted, no send attempted', strpos($log, 'baseline') !== false && strpos($log, 'recipient') === false);
ck('no temp state files left behind', !glob("$s/collector/notify_state.json.tmp*"));
// a second run while one is busy (lock held) exits without doing anything
$h = fopen("$s/collector/notifier.lock", 'c'); flock($h, LOCK_EX);
$before = file_get_contents("$s/collector/notify_state.json");
list($rc, $o) = $run(); ck('notifier while another run holds the lock: exits 0, skipped, state untouched', $rc === 0 && strpos((string)file_get_contents("$s/collector/notify.log"), 'busy; skipped') !== false && file_get_contents("$s/collector/notify_state.json") === $before);
flock($h, LOCK_UN); fclose($h);
$nb = substr_count((string)file_get_contents("$s/collector/notify.log"), 'busy; skipped');
list($rc, $o) = $run(); ck('lock released: runs again (no new "busy")', $rc === 0 && substr_count((string)file_get_contents("$s/collector/notify.log"), 'busy; skipped') === $nb && json_decode(file_get_contents("$s/collector/notify_state.json"), true)['ran_at'] >= time() - 5);
// state cannot be saved (folder not writable; fault injected from outside, no production change): run fails closed
$before = file_get_contents("$s/collector/notify_state.json"); chmod("$s/collector", 0555);
list($rc, $o) = $run(); chmod("$s/collector", 0755);
ck('state save fails: notifier exits non-zero, old state file intact and non-empty', $rc !== 0 && file_get_contents("$s/collector/notify_state.json") === $before && strlen($before) > 2);
ck('state save fails: error logged, no temp file left', strpos((string)file_get_contents("$s/collector/notify.log"), 'ERROR notifier stopped: state: cannot create') !== false && !glob("$s/collector/notify_state.json.tmp*"));
// damaged state: kept aside, fresh baseline (nothing old mailed), no crash
file_put_contents("$s/collector/notify_state.json", '{"broken');
list($rc, $o) = $run(); $st = json_decode((string)@file_get_contents("$s/collector/notify_state.json"), true);
ck('damaged state file: moved aside, new baseline, exit 0', $rc === 0 && glob("$s/collector/notify_state.json.bad-*") && !empty($st['ns_v']));
exec('rm -rf ' . escapeshellarg($s));
echo $f ? "$f failed\n" : "all passed\n"; exit($f ? 1 : 0);
