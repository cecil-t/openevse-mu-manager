<?php
// php tests/sys_versions_test.php — Settings > System status: software versions + database sizes (fixtures)
$f = 0; function ck($n, $ok){ global $f; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . "\n"; }
require_once __DIR__ . '/../sys_lib.php';
$d = sys_get_temp_dir() . '/oev_sysv_' . getmypid(); @mkdir("$d/loadbalancer/coordinator", 0777, true); @mkdir("$d/data");
$by = function($rows){ $o = array(); foreach ($rows as $r) $o[$r['name']] = $r; return $o; };

// present sources
file_put_contents("$d/VERSION", "productversion=\"7.2.2\"\nbuildnumber=\"72806\"\nsmallfixnumber=\"3\"\n");
file_put_contents("$d/loadbalancer/docker-compose.yml", "services:\n  mosquitto:\n    image: eclipse-mosquitto:2\n");
file_put_contents("$d/loadbalancer/coordinator/Dockerfile", "FROM php:8.3-cli-alpine\nRUN x\n");
file_put_contents("$d/system.json", '{"time":1,"containers":{"openevse-mosquitto":"running healthy","openevse-lb-coordinator":"running healthy"}}');
$v = $by(oev_sys_versions($d, array('server_software' => 'Apache/2.4.62 (Unix) OpenSSL/3.0 /var/www/secret', 'dsm_file' => "$d/VERSION", 'status_file' => "$d/system.json")));
ck('web server tokens only, no paths', $v['Web server']['value'] === 'Apache/2.4.62 OpenSSL/3.0');
ck('PHP is runtime version', strpos($v['PHP']['value'], PHP_VERSION) === 0);
ck('SQLite from in-memory PDO', preg_match('/^3\.\d+\.\d+$/', (string)$v['SQLite']['value']) === 1);
ck('DSM version', $v['Synology DSM']['value'] === '7.2.2-72806 Update 3');
ck('mosquitto image tag', $v['Mosquitto (MQTT broker)']['value'] === 'eclipse-mosquitto:2' && strpos($v['Mosquitto (MQTT broker)']['note'], 'running healthy') !== false);
ck('coordinator base image', $v['Load balancer runtime']['value'] === 'php:8.3-cli-alpine');

// unknown / missing sources -> null (rendered "Not reported"), never guessed
$v = $by(oev_sys_versions("$d/none", array('server_software' => null, 'dsm_file' => "$d/nope", 'status_file' => "$d/nope", 'sqlite' => null, 'kernel' => null)));
foreach (array('Web server', 'SQLite', 'Synology DSM', 'Linux kernel', 'Mosquitto (MQTT broker)', 'Load balancer runtime') as $k) ck("$k unknown -> null", $v[$k]['value'] === null);
ck('container state not reported', strpos($v['Mosquitto (MQTT broker)']['note'], 'not reported') !== false);
ck('garbage server string -> null', oev_sys_tok('<script>') === null);

// database sizes: main + -wal/-shm separately, missing file -> null, relative names only
file_put_contents("$d/data/sessions.db", str_repeat('x', 4096)); file_put_contents("$d/data/sessions.db-wal", str_repeat('x', 2048)); file_put_contents("$d/data/sessions.db-shm", str_repeat('x', 32768));
$s = oev_sys_dbsizes($d, "$d/data/sessions.db");
ck('sessions main size only', $s[0]['size'] === 4096 && $s[0]['file'] === 'data/sessions.db');
ck('wal/shm separate', $s[0]['side'] === array('-wal' => 2048, '-shm' => 32768));
ck('openevse.db missing -> null', $s[1]['size'] === null && $s[1]['side'] === array() && $s[1]['file'] === 'openevse.db');
file_put_contents("$d/openevse.db", str_repeat('x', 3 * 1048576));
$s = oev_sys_dbsizes($d, "$d/data/sessions.db");
ck('openevse.db present', $s[1]['size'] === 3 * 1048576);
ck('outside path shows basename only', oev_sys_dbsizes($d, '/tmp/zz/elsewhere.db')[0]['file'] === 'elsewhere.db');
ck('bytes format', oev_sys_bytes(512) === '512 B' && oev_sys_bytes(2048) === '2 KiB' && oev_sys_bytes(3 * 1048576) === '3 MiB');
foreach (array("data/sessions.db","data/sessions.db-wal","data/sessions.db-shm","openevse.db","VERSION","system.json","loadbalancer/docker-compose.yml","loadbalancer/coordinator/Dockerfile") as $x) @unlink("$d/$x");
echo $f ? "$f FAILED\n" : "ALL PASS\n"; exit($f ? 1 : 0);
