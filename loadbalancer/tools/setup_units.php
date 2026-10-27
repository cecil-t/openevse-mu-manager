<?php
/******************************************************************************
 * One-off: turn on MQTT on units, pointing at the load balancer's broker.
 *   sudo -u <web user> php tools/setup_units.php <broker LAN IP> <unit id> [...]
 * Each unit gets its own login u<id> and topic openevse/u<id>. Logins are made
 * (once) in ../secrets/mqtt_users.json; nothing secret is printed. Only MQTT
 * settings are changed (2.x keeps its divert feed fields as they were).
 ******************************************************************************/
$LB = dirname(__DIR__);
require $LB . '/tools/common.php';
$broker = $argv[1] ?? ''; $ids = array_map('intval', array_slice($argv, 2));
if (!filter_var($broker, FILTER_VALIDATE_IP) || !$ids) die("usage: setup_units.php <broker ip> <unit id> [...]\n");
$users = lb_users_load($LB);
$port = 1883; foreach (@file($LB . '/.env', FILE_IGNORE_NEW_LINES) ?: array() as $l) if (preg_match('/^MQTT_PORT=(\d+)/', $l, $m)) $port = (int)$m[1];   // MQTT_PORT in .env
$units = evse_units($ids);
foreach ($ids as $id) {
	if (!isset($units[$id])) { echo "u$id: not found\n"; continue; }
	$u = $units[$id]; $user = "u$id"; $topic = "openevse/u$id";
	if (empty($users[$user])) $users[$user] = bin2hex(random_bytes(12));
	lb_users_save($LB, $users);
	$c = evse_http($u, 'GET', 'config');
	if (!$c['ok']) { echo "u$id {$u['name']}: config read failed ({$c['code']}), skipped\n"; continue; }
	$ver = (string)$c['json']['version'];
	if ((int)ltrim($ver, 'vV') >= 4) {   // v4/v5 JSON API
		$r = evse_http($u, 'POST', 'config', ['mqtt_enabled'=>true, 'mqtt_protocol'=>'mqtt', 'mqtt_server'=>$broker, 'mqtt_port'=>$port,
			'mqtt_topic'=>$topic, 'mqtt_user'=>$user, 'mqtt_pass'=>$users[$user], 'mqtt_retained'=>false]);
		$ok = $r['ok'];
		echo "u$id {$u['name']} v$ver: " . ($ok ? 'saved' : "FAILED ({$r['code']})") . "\n";
	} else {   // WiFi 2.x: POST /savemqtt (its reply echoes the password: never printed)
		$f = ['enable'=>'true', 'server'=>$broker, 'port'=>$port, 'topic'=>$topic, 'user'=>$user, 'pass'=>$users[$user],
			'solar'=>(string)($c['json']['mqtt_solar'] ?? ''), 'grid_ie'=>(string)($c['json']['mqtt_grid_ie'] ?? '')];
		list($ok, $code) = evse_post_2x($u, 'savemqtt', $f); unset($f);
		echo "u$id {$u['name']} v$ver: " . ($ok ? 'saved' : "FAILED (HTTP $code)") . "\n";
	}
	// read back (passwords masked)
	$c2 = evse_http($u, 'GET', 'config');
	if ($c2['ok']) printf("  now: enabled=%s server=%s port=%s topic=%s user=%s\n", var_export($c2['json']['mqtt_enabled'] ?? null, true),
		$c2['json']['mqtt_server'] ?? '?', $c2['json']['mqtt_port'] ?? '?', $c2['json']['mqtt_topic'] ?? '?', $c2['json']['mqtt_user'] ?? '?');
}
