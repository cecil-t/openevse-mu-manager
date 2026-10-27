<?php
// The MQTT login file (secrets/mqtt_users.json, owner-only): one copy of the load/save code,
// used by collector/jobs.php and the tools here. $lb = the loadbalancer/ folder. Never prints a password.
function lb_users_load($lb){ $j = @json_decode((string)@file_get_contents("$lb/secrets/mqtt_users.json"), true); return is_array($j) ? $j : array(); }
function lb_users_save($lb, $u){ $f = "$lb/secrets/mqtt_users.json"; $t = "$f.tmp"; file_put_contents($t, json_encode($u, JSON_PRETTY_PRINT)); chmod($t, 0600); rename($t, $f); }
