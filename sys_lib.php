<?php
/******************************************************************************
 * sys_lib.php - back-end system check (Settings > System status, the dot on the
 * admin avatar, and "system problem" error emails from collector/notifier.php).
 * Each check: key, title, ok (true / false / null = not used here), text, seen.
 * text is HTML: values read from a real source are in <span class="v"> (shown
 * in black); emails use strip_tags(). Fixed words are plain literals.
 * Sources (no unit calls):
 *  - private/status/system.json: written every minute by the
 *    root task "OpenEVSE jobs and notifications" (jobs_root.sh): container states
 *    of the MQTT broker and the load balancer, and when that task last ran.
 *  - loadbalancer/run/heartbeat.json: the load balancer (coordinator) heartbeat,
 *    its MQTT connection and the last MQTT message from each unit.
 *  - sessions.db unit_state: when the collector last read a unit, and when the
 *    units' 30-second readings last arrived.
 *  - with $full: a quick TCP connect to the broker (MQTT_BIND:MQTT_PORT from loadbalancer/.env, 1 s).
 ******************************************************************************/
require_once __DIR__ . '/util_lib.php';   // oev_sess_ro()
define('OEV_SYS_STATUS_FILE', __DIR__ . '/private/status/system.json');

function oev_sys_checks($full = false){
	$site = __DIR__; $now = time(); $o = array();
	$add = function($k, $t, $ok, $txt, $seen = null) use (&$o){ $o[$k] = array('key' => $k, 'title' => $t, 'ok' => $ok, 'text' => $txt, 'seen' => $seen); };
	$st = @json_decode((string)@file_get_contents(OEV_SYS_STATUS_FILE), true); if (!is_array($st)) $st = null;
	$stage = $st ? $now - (int)$st['time'] : null;
	$stok = $st && $stage <= 300;
	$hb = @json_decode((string)@file_get_contents($site . '/loadbalancer/run/heartbeat.json'), true); if (!is_array($hb) || empty($hb['time'])) $hb = null;
	$hbage = $hb ? $now - (int)$hb['time'] : null; $hbok = $hb && $hbage <= 90;
	$grouped = 0; $units = array();
	try {
		$db = new PDO('sqlite:' . $site . '/openevse.db', null, null, array(PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY));
		foreach ($db->query('SELECT id, enabled, loadgroupid, emon_mode FROM evses', PDO::FETCH_ASSOC) as $r) { $units[(int)$r['id']] = $r; if ($r['enabled'] && $r['loadgroupid']) $grouped++; }
		$stuck = (int)$db->query("SELECT count(*) FROM jobs WHERE status = 'queued' AND created_at < " . ($now - 300))->fetchColumn();
		$db = null;
	} catch (Exception $e) { $stuck = 0; }
	$cst = function($name) use ($st){ return $st['containers'][$name] ?? ''; };

	// 1. jobs + notifications task (also what reports the container states)
	if (!$st) $add('jobs', 'Jobs and notifications task', false, 'Has never reported. Unit setup changes and emails will not happen.', null);
	elseif (!$stok) $add('jobs', 'Jobs and notifications task', false, 'Not running: last ran ' . oev_sys_ago($stage) . '. Unit setup changes and emails are on hold.', (int)$st['time']);
	elseif ($stuck) $add('jobs', 'Jobs and notifications task', false, "Running, but " . oev_sys_v($stuck) . " unit setup job(s) have waited more than 5 minutes.", (int)$st['time']);
	else $add('jobs', 'Jobs and notifications task', true, 'Running every minute.', (int)$st['time']);

	// 2. MQTT broker (Mosquitto container)
	$bs = $cst('openevse-mosquitto'); $tcp = null;
	if ($full) { $ip = ''; $port = 1883; foreach (@file($site . '/loadbalancer/.env', FILE_IGNORE_NEW_LINES) ?: array() as $l) { if (preg_match('/^MQTT_BIND=(\S+)/', $l, $m)) $ip = $m[1]; if (preg_match('/^MQTT_PORT=(\d+)/', $l, $m)) $port = (int)$m[1]; }
		if ($ip !== '') { $s = @fsockopen($ip, $port, $en, $es, 1.0); $tcp = (bool)$s; if ($s) fclose($s); } }
	if (!$grouped && $bs === '') $add('broker', 'MQTT broker (Mosquitto)', null, 'Not needed: no units are in a load group.');
	elseif ($tcp === false) $add('broker', 'MQTT broker (Mosquitto)', false, 'Not accepting connections' . ($bs !== '' ? " (container: " . oev_sys_v($bs) . ")" : '') . '. The load balancer falls back to its ' . oev_sys_v((int)($hb['poll_sec'] ?? 20) . '-second') . ' poll.', $stok ? (int)$st['time'] : null);
	elseif (!$stok && $tcp === null) $add('broker', 'MQTT broker (Mosquitto)', null, 'Unknown: the jobs task that checks it is not running.');
	elseif ($stok && strpos($bs, 'running') !== 0) $add('broker', 'MQTT broker (Mosquitto)', false, 'Not running' . ($bs !== '' ? " (container: " . oev_sys_v($bs) . ")" : ' (container missing)') . '. The load balancer falls back to its ' . oev_sys_v((int)($hb['poll_sec'] ?? 20) . '-second') . ' poll.', (int)$st['time']);
	else $add('broker', 'MQTT broker (Mosquitto)', true, 'Running' . ($tcp ? ' and accepting connections' : '') . '.', $stok ? (int)$st['time'] : null);

	// 3. load balancer (coordinator container + heartbeat)
	$cs = $cst('openevse-lb-coordinator');
	if (!$hb) $add('coordinator', 'Load balancer (coordinator)', $grouped ? false : null, $grouped ? 'No heartbeat found. Grouped units are not balanced.' : 'Not needed: no units are in a load group.');
	elseif (!$hbok) $add('coordinator', 'Load balancer (coordinator)', $grouped ? false : null, 'Not running: last heartbeat ' . oev_sys_ago($hbage) . ($cs !== '' ? " (container: " . oev_sys_v($cs) . ")" : '') . '. Units keep charging at their saved current, but new cars are not balanced.', (int)$hb['time']);
	else $add('coordinator', 'Load balancer (coordinator)', true, 'Running (' . oev_sys_v(($hb['mode'] ?? '') === 'live' ? 'live' : 'dry run') . '), heartbeat ' . oev_sys_ago($hbage) . '.', (int)$hb['time']);

	// 4. MQTT listener: the load balancer's MQTT connection + last message from a unit
	$ms = 0; $hu = array();
	if ($hb) { foreach ((array)$hb['groups'] as $g) foreach ((array)$g['units'] as $u) $hu[] = $u; foreach ((array)($hb['monitor'] ?? array()) as $u) $hu[] = $u; }
	foreach ($hu as $u) $ms = max($ms, (int)($u['mqtt_seen'] ?? 0));
	if (!$hbok) $add('listener', 'MQTT listener', null, 'Waiting for the load balancer.', $ms ?: null);
	elseif (empty($hb['mqtt'])) $add('listener', 'MQTT listener', false, 'The load balancer is not connected to the broker. It still works, but reacts on its ' . oev_sys_v((int)($hb['poll_sec'] ?? 20) . '-second') . ' poll instead of within seconds.', $ms ?: null);
	elseif ($ms && $now - $ms > 600) $add('listener', 'MQTT listener', false, 'Connected, but no unit has sent an MQTT message for ' . oev_sys_ago($now - $ms, false) . '.', $ms);
	else $add('listener', 'MQTT listener', true, 'Connected' . ($ms ? '; last message from a unit ' . oev_sys_ago($now - $ms) : '') . '.', $ms ?: null);

	// 5. collector + 6. units' readings arriving (the receiver)
	$lp = 0; $lpush = 0;
	try {
		$sdb = oev_sess_ro(3, $site);
		foreach ($sdb->query('SELECT unit_id, last_poll_at, last_push_at FROM unit_state', PDO::FETCH_ASSOC) as $r) {
			$u = $units[(int)$r['unit_id']] ?? null; if (!$u || !$u['enabled']) continue;
			$lp = max($lp, (int)$r['last_poll_at']);
			if (($u['emon_mode'] ?: 'internal') === 'internal') $lpush = max($lpush, (int)$r['last_push_at']);
		}
		$sdb = null;
	} catch (Exception $e) {}
	$cl = (int)@filemtime($site . '/collector/collector.log'); $cseen = max($lp, $cl);
	if (!$cseen) $add('collector', 'Data collector', false, 'Has not run yet.');
	elseif ($now - $cseen > 600) $add('collector', 'Data collector', false, 'Not running: last ran ' . oev_sys_ago($now - $cseen) . '. Session reports and unit emails may be missing.', $cseen);
	else $add('collector', 'Data collector', true, 'Running (last ' . oev_sys_ago($now - $cseen) . ').', $cseen);
	$internal = 0; foreach ($units as $u) if ($u['enabled'] && ($u['emon_mode'] ?: 'internal') === 'internal') $internal++;
	if (!$internal) $add('receiver', "Units' 30-second readings", null, 'Not used: no unit has Internal logging.');
	elseif (!$lpush || $now - $lpush > 300) $add('receiver', "Units' 30-second readings", false, 'None arriving' . ($lpush ? ' since ' . oev_sys_ago($now - $lpush) : '') . '. Pages fall back to asking the units directly.', $lpush ?: null);
	else $add('receiver', "Units' 30-second readings", true, 'Arriving (last ' . oev_sys_ago($now - $lpush) . ').', $lpush);
	return $o;
}
function oev_sys_v($x){ return '<span class="v">' . htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8') . '</span>'; }
function oev_sys_ago($s, $ago = true){ $s = max(0, (int)$s); $t = $s < 120 ? $s . ($s == 1 ? ' second' : ' seconds') : ($s < 7200 ? round($s / 60) . ' minutes' : ($s < 172800 ? round($s / 3600) . ' hours' : round($s / 86400) . ' days')); return oev_sys_v($t) . ($ago ? ' ago' : ''); }
// problems only (for the avatar dot): cheap, no network
function oev_sys_problems(){ $p = array(); foreach (oev_sys_checks(false) as $k => $c) if ($c['ok'] === false) $p[$k] = $c; return $p; }
