<?php
/******************************************************************************
 * Unit setup jobs queued by Settings (openevse.db `jobs`). CLI only, as http.
 * Run every minute by the root task "OpenEVSE jobs and notifications"
 * (private/jobs_root.sh):
 *   php jobs.php --logins    make MQTT logins for queued "mqtt_on" jobs; if one is
 *                             new, touch secrets/reload.flag (the root script then
 *                             rebuilds Mosquitto's password file and reloads it)
 *   php jobs.php --run        carry out the queued jobs on the units
 * Kinds: emon (arg none|internal|external), mqtt_on, mqtt_off.
 * Only Emoncms or MQTT settings on the unit are written. Nothing secret is printed or logged.
 ******************************************************************************/
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('SESS_DIR', __DIR__);
define('OEV_SITE', dirname(__DIR__));
define('OEV_LB', OEV_SITE . '/loadbalancer');
chdir(OEV_SITE);
require OEV_SITE . '/include_v5.php';
require OEV_SITE . '/sessions_lib.php';
require_once OEV_SITE . '/settings_lib.php';            // oev_site_url()
require_once OEV_LB . '/tools/users_lib.php';           // lb_users_load(), lb_users_save()
// The units' Emoncms server = this site's emoncms/ folder (Settings > Preferences > Site web address).
// v5 checks certificates (https); 2.8.2 gets it without a scheme and sends plain HTTP (its HTTPS pins a SHA1 fingerprint).
function emon_server($v5){ $u = oev_site_url(); if ($u === '') return ''; $u .= 'emoncms'; return $v5 ? $u : preg_replace('#^https?://#', '', $u); }

function jlog($m){ @file_put_contents(SESS_DIR . '/jobs.log', date('Y-m-d H:i:s') . ' ' . $m . "\n", FILE_APPEND | LOCK_EX); }
function jdb(){ static $d = null; if (!$d) $d = new PDO('sqlite:' . OEV_SITE . '/openevse.db', null, null, array(PDO::ATTR_TIMEOUT => 10, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)); return $d; }
function broker_port(){ foreach (@file(OEV_LB . '/.env', FILE_IGNORE_NEW_LINES) ?: array() as $l) if (preg_match('/^MQTT_PORT=(\d+)/', $l, $m)) return (int)$m[1]; return 1883; }   // MQTT_PORT in .env, default 1883
function broker_ip(){ foreach (@file(OEV_LB . '/.env', FILE_IGNORE_NEW_LINES) ?: array() as $l) if (preg_match('/^MQTT_BIND=(\S+)/', $l, $m)) return $m[1]; return ''; }
function finish($id, $ok, $msg){ jdb()->prepare("UPDATE jobs SET status = ?, msg = ?, done_at = ? WHERE id = ?")->execute(array($ok ? 'done' : 'failed', $msg, time(), $id)); jlog("job $id " . ($ok ? 'done' : 'FAILED') . ": $msg"); }

function is_v5($c){ return (int)ltrim((string)$c['version'], 'vV') >= 4; }

function job_emon($u, $mode){
	$id = (int)$u['id'];
	$r = evse_http($u, 'GET', 'config'); if (!$r['ok']) return array(false, "Could not read the unit's settings (HTTP {$r['code']}). It will need to be saved again.");
	$c = $r['json']; $v5 = is_v5($c);
	$sdb = sess_db();
	// node = the unit number. Only if another unit still holds that node: keep this unit's current node (or the lowest free one)
	$node = (string)$id;
	$held = $sdb->query("SELECT unit_id FROM emon_nodes WHERE node = " . $sdb->quote($node))->fetchColumn();
	if ($held !== false && (int)$held !== $id) {
		$node = $sdb->query("SELECT node FROM emon_nodes WHERE unit_id = $id")->fetchColumn();
		if ($node === false) { $used = $sdb->query("SELECT node FROM emon_nodes")->fetchAll(PDO::FETCH_COLUMN); $n = 1; while (in_array((string)$n, $used, true)) $n++; $node = (string)$n; }
	}
	if ($mode === 'internal') {
		if (emon_server($v5) === '') return array(false, 'The site web address is not known yet: set it in Settings > Preferences (or open Settings once in a browser), then save again.');
		$key = bin2hex(random_bytes(16));
		if ($v5) { $w = evse_http($u, 'POST', 'config', array('emoncms_enabled' => true, 'emoncms_server' => emon_server(true), 'emoncms_node' => $node, 'emoncms_apikey' => $key, 'emoncms_fingerprint' => '')); $ok = $w['ok'] && $w['code'] == 200; $code = $w['code']; unset($w); }
		else list($ok, $code) = evse_post_2x($u, 'saveemoncms', array('enable' => 'true', 'server' => emon_server(false), 'node' => $node, 'apikey' => $key, 'fingerprint' => ''));
		if ($ok) {   // the new node and key first, then drop the unit's old node (history is kept by unit, not by node)
			$sdb->prepare("INSERT OR REPLACE INTO emon_nodes (node, unit_id, key_hash, created_at) VALUES (?,?,?,?)")->execute(array($node, $id, hash('sha256', $key), time()));
			$sdb->prepare("DELETE FROM emon_nodes WHERE unit_id = ? AND node != ?")->execute(array($id, $node));
		}
		unset($key);
		return array($ok, $ok ? "Internal logging set up: the unit sends its readings to this server every 30 seconds (node $node)." : "The unit did not accept the Emoncms settings (HTTP $code).");
	}
	if ($mode === 'external') {
		$srv = trim((string)$u['emon_ext_server']); $en = trim((string)$u['emon_ext_node']); $ek = (string)$u['emon_ext_key'];
		if ($srv === '') return array(false, 'No external server was entered.');
		if ($v5) { $w = evse_http($u, 'POST', 'config', array('emoncms_enabled' => true, 'emoncms_server' => $srv, 'emoncms_node' => $en, 'emoncms_apikey' => $ek, 'emoncms_fingerprint' => '')); $ok = $w['ok'] && $w['code'] == 200; $code = $w['code']; unset($w); }
		else list($ok, $code) = evse_post_2x($u, 'saveemoncms', array('enable' => 'true', 'server' => preg_replace('#^https?://#', '', $srv), 'node' => $en, 'apikey' => $ek, 'fingerprint' => ''));
		if ($ok) $sdb->prepare("UPDATE emon_nodes SET key_hash = NULL WHERE unit_id = ?")->execute(array($id));
		return array($ok, $ok ? 'External Emoncms server set on the unit. This server now reads the unit directly every minute.' : "The unit did not accept the Emoncms settings (HTTP $code).");
	}
	// none
	if ($v5) { $w = evse_http($u, 'POST', 'config', array('emoncms_enabled' => false)); $ok = $w['ok'] && $w['code'] == 200; $code = $w['code']; unset($w); }
	else list($ok, $code) = evse_post_2x($u, 'saveemoncms', array('enable' => 'false', 'server' => (string)$c['emoncms_server'], 'node' => (string)$c['emoncms_node'], 'apikey' => '_DUMMY_PASSWORD', 'fingerprint' => (string)$c['emoncms_fingerprint']));
	if ($ok) $sdb->prepare("UPDATE emon_nodes SET key_hash = NULL WHERE unit_id = ?")->execute(array($id));
	return array($ok, $ok ? 'Emoncms output turned off on the unit. This server now reads the unit directly every minute.' : "The unit did not accept the change (HTTP $code).");
}

function job_mqtt($u, $on){
	$id = (int)$u['id']; $user = "u$id"; $topic = "openevse/u$id";
	$users = lb_users_load(OEV_LB);
	if ($on && empty($users[$user])) return array(false, 'No MQTT login yet (made on the next run).');
	$broker = broker_ip(); if (!filter_var($broker, FILTER_VALIDATE_IP)) return array(false, 'Broker address not found in loadbalancer/.env.');
	$r = evse_http($u, 'GET', 'config'); if (!$r['ok']) return array(false, "Could not read the unit's settings (HTTP {$r['code']}).");
	$c = $r['json'];
	if (is_v5($c)) {
		$f = $on ? array('mqtt_enabled' => true, 'mqtt_protocol' => 'mqtt', 'mqtt_server' => $broker, 'mqtt_port' => broker_port(), 'mqtt_topic' => $topic, 'mqtt_user' => $user, 'mqtt_pass' => $users[$user], 'mqtt_retained' => false)
			: array('mqtt_enabled' => false);
		$w = evse_http($u, 'POST', 'config', $f); $ok = $w['ok'] && $w['code'] == 200; $code = $w['code']; unset($w, $f);
	} else {
		$f = array('enable' => $on ? 'true' : 'false', 'server' => $on ? $broker : (string)($c['mqtt_server'] ?? $broker), 'port' => broker_port(), 'topic' => $on ? $topic : (string)($c['mqtt_topic'] ?? $topic),
			'user' => $on ? $user : (string)($c['mqtt_user'] ?? $user), 'pass' => $users[$user] ?? '', 'solar' => (string)($c['mqtt_solar'] ?? ''), 'grid_ie' => (string)($c['mqtt_grid_ie'] ?? ''));
		list($ok, $code) = evse_post_2x($u, 'savemqtt', $f); unset($f);
	}
	unset($users);
	if (!$ok) return array(false, "The unit did not accept the MQTT settings (HTTP $code).");
	return array(true, $on ? "MQTT turned on: the unit reports to the load balancer as $user." : 'MQTT turned off on the unit.');
}

$db = jdb();
$mode = $argv[1] ?? '--run';
$q = $db->query("SELECT * FROM jobs WHERE status = 'queued' ORDER BY id")->fetchAll();
if (!$q) exit(0);
if ($mode === '--logins') {
	$users = lb_users_load(OEV_LB); $new = 0;
	foreach ($q as $j) if ($j['kind'] === 'mqtt_on') { $k = 'u' . (int)$j['unit_id']; if (empty($users[$k])) { $users[$k] = bin2hex(random_bytes(12)); $new++; } }
	if ($new) { lb_users_save(OEV_LB, $users); touch(OEV_LB . '/secrets/reload.flag'); jlog("$new new MQTT login(s) made; broker reload requested"); }
	exit(0);
}
foreach ($q as $j) {
	$db->prepare("UPDATE jobs SET status = 'running' WHERE id = ? AND status = 'queued'")->execute(array($j['id']));
	try {
		$us = evse_units(array((int)$j['unit_id']));
		if (!isset($us[(int)$j['unit_id']])) { finish($j['id'], false, 'Unit not found.'); continue; }
		$u = $us[(int)$j['unit_id']];
		if ($j['kind'] === 'emon') list($ok, $msg) = job_emon($u, (string)$j['arg']);
		elseif ($j['kind'] === 'mqtt_on') list($ok, $msg) = job_mqtt($u, true);
		elseif ($j['kind'] === 'mqtt_off') list($ok, $msg) = job_mqtt($u, false);
		else { $ok = false; $msg = 'Unknown job.'; }
		if (function_exists('evse_home_slow_clear')) evse_home_slow_clear($u);
		finish($j['id'], $ok, $msg);
	} catch (Throwable $e) { finish($j['id'], false, 'Error: ' . $e->getMessage()); }
}
