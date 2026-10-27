<?php
/******************************************************************************
 * Make the broker logins: the coordinator's (lb) + one per unit already in
 * secrets/mqtt_users.json. Writes secrets/coordinator.env and prints the
 * mosquitto_passwd commands' input file (secrets/passwd.plain, deleted after
 * hashing, see README.md). Nothing secret is printed.
 *   sudo -u <web user> php tools/make_logins.php <unit id> [...]
 ******************************************************************************/
$LB = dirname(__DIR__);
require_once __DIR__ . '/users_lib.php';
$users = lb_users_load($LB);
if (empty($users['lb'])) $users['lb'] = bin2hex(random_bytes(16));
foreach (array_slice($argv, 1) as $id) { $k = 'u' . (int)$id; if (empty($users[$k])) $users[$k] = bin2hex(random_bytes(12)); }
lb_users_save($LB, $users);
file_put_contents("$LB/secrets/coordinator.env", "MQTT_USER=lb\nMQTT_PASS={$users['lb']}\n"); chmod("$LB/secrets/coordinator.env", 0600);
$plain = ''; foreach ($users as $k => $v) $plain .= "$k:$v\n";
file_put_contents("$LB/secrets/passwd.plain", $plain); chmod("$LB/secrets/passwd.plain", 0600);
echo 'logins: ' . implode(', ', array_keys($users)) . " (passwords in secrets/, not shown)\n";
