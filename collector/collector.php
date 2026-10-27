<?php
/******************************************************************************
 * OpenEVSE session collector. CLI only. Run every minute as the web user (cron, see
 * README.md):
 *   sudo -u <web user> php <site folder>/collector/collector.php
 * Options: -v (print per-unit summary), --maint (force maintenance),
 *          --all (poll every unit now), --unit=N (only unit N),
 *          --clock (check unit clocks now instead of every 10 min)
 * Log: collector/collector.log
 *
 * Push units (2.x WiFi with Emoncms output pointed at <site>/emoncms/, see
 * push_logic.php) send their readings every 30 s; this run only polls them when
 * sess_poll_reason() says so: a push showed a change, pushes went quiet, every
 * 2 min while plugged in, or a 15-min slow check. Units that don't push are
 * polled every run. v5 units push too (over HTTPS, with fulljson).
 ******************************************************************************/
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('SESS_DIR', __DIR__);
define('SESS_WEB', dirname(__DIR__));   // the site folder
$verbose = in_array('-v', $argv);
$all = in_array('--all', $argv);
$only = 0; foreach ($argv as $a) if (preg_match('/^--unit=(\d+)$/', $a, $m)) $only = (int)$m[1];
$started = time();

function flushlog(){
	$f = SESS_DIR . '/collector.log';
	if (is_file($f) && filesize($f) > 2 * 1024 * 1024) @rename($f, $f . '.1');
	$out = '';
	foreach ($GLOBALS['SESS_LOG'] as $l) $out .= date('Y-m-d H:i:s', $l[0]) . ' ' . strtoupper($l[1]) . ' ' . $l[2] . "\n";
	if ($out !== '') file_put_contents($f, $out, FILE_APPEND | LOCK_EX);
	$GLOBALS['SESS_LOG'] = array();
}

$lock = fopen(SESS_DIR . '/collector.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
	file_put_contents(SESS_DIR . '/collector.log', date('Y-m-d H:i:s') . " WARN previous run still active, skipped\n", FILE_APPEND | LOCK_EX);
	exit(0);
}

try {
	require SESS_WEB . '/include_v5.php';
	require SESS_WEB . '/sessions_lib.php';
	require SESS_DIR . '/session_logic.php';
	$db = sess_db();
	$units = evse_units();
	$push_units = $db->query("SELECT unit_id, node FROM emon_nodes WHERE key_hash IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR);
	foreach ($units as $unit) {
		if ($only && (int)$unit['id'] !== $only) continue;
		if (time() - $started > 45) { slog('warn', 'run took over 45 s, skipping remaining units'); break; }
		try {
			$uid = (int)$unit['id'];
			$us = $db->query("SELECT * FROM unit_state WHERE unit_id = $uid")->fetch();
			$is_push = isset($push_units[$uid]);   // 2.x (HTTP) and v5 (HTTPS) units alike
			if ($is_push) sess_check_push_stale($db, $unit, time());
			$us = $db->query("SELECT * FROM unit_state WHERE unit_id = $uid")->fetch();
			$why = ($all || $only) ? 'forced' : sess_poll_reason($us, $is_push, time());
			if ($why === '') {
				if ($verbose) echo str_pad($unit['name'], 20) . " push  skip (last push " . (time() - (int)$us['last_push_at']) . " s ago)\n";
				continue;
			}
			list($r, $res, $api) = sess_poll_one($db, $unit, $why);
			if ($verbose) {
				echo str_pad($unit['name'], 20) . ' ' . str_pad($api, 5) . ' ' . str_pad("[$why]", 20) . ' ';
				echo $r['ok'] ? sprintf("state=%s conn=%s chg=%s amps=%s pilot=%s cmax=%s temp=%sC life=%s kWh sess=%s Wh volts=%s session=%s\n",
					$r['state'], $res['connected'], $res['charging'], var_export($r['amps'], true), $r['pilot'], var_export($r['cmax'], true), $r['temp_c'],
					$res['lifetime_wh'] !== null ? round($res['lifetime_wh'] / 1000, 2) : 'null', $r['session_wh'] !== null ? round($r['session_wh']) : 'null', $r['volts'] ?: '-', $res['session_id'] ?: '-')
					: "FAIL {$r['error']}\n";
			}
		} catch (Throwable $e) {
			if (isset($db) && $db->inTransaction()) sess_rollback($db);
			slog('error', "{$unit['name']}: " . $e->getMessage());
		}
	}
	// unit clocks: checked every 10 min per unit, synced when 1 s or more off (clock_logic.php)
	require_once SESS_DIR . '/clock_logic.php';
	clk_cols($db);
	$clk_force = in_array('--clock', $argv);
	foreach ($units as $unit) {
		if ($only && (int)$unit['id'] !== $only) continue;
		if (time() - $started > 40) break;
		try {
			$o = clk_check_unit($db, $unit, time(), $clk_force);
			if ($verbose && $o) echo str_pad($o['name'], 20) . ' clock ' . (isset($o['before']) ? sprintf('%+.2f s (rtt %d ms)', $o['before'], $o['rtt']) : '?') . ($o['synced'] ? ' -> synced, now ' . ($o['after'] !== null ? sprintf('%+.2f s', $o['after']) : '?') : '') . ($o['note'] ? ' [' . $o['note'] . ']' : '') . "\n";
		} catch (Throwable $e) {
			if ($db->inTransaction()) sess_rollback($db);
			slog('error', "{$unit['name']}: clock: " . $e->getMessage());
		}
	}
	if (!$only) sess_maintenance($db, time(), in_array('--maint', $argv));
} catch (Throwable $e) {
	slog('error', 'collector: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
}
flushlog();
flock($lock, LOCK_UN);
