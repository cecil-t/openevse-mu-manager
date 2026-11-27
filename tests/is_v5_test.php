<?php
// php tests/is_v5_test.php
// A unit is v5 if the WiFi version major is 4+ or /config has max_current_soft
// (local/dev builds such as local_pr1395_... have no leading version number).
require_once __DIR__ . '/../evse_v5.php';
$f = 0; function ck($n, $ok){ global $f; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . "\n"; }

ck('local build with max_current_soft is v5', evse_config_is_v5(array('version' => 'local_pr1395_8b4779b2', 'max_current_soft' => 12)) === true);
ck('stock v5 version is v5', evse_config_is_v5(array('version' => 'v5.1.5-seqid8_modified', 'max_current_soft' => 48)) === true);
ck('v4 version without max_current_soft is v5', evse_config_is_v5(array('version' => '4.2.0')) === true);
ck('2.x config is not v5', evse_config_is_v5(array('version' => '2.9.1')) === false);
ck('2.8.2 config is not v5', evse_config_is_v5(array('version' => '2.8.2', 'firmware' => '7.1.3')) === false);
ck('empty is not v5', evse_config_is_v5(array()) === false);
ck('non-array is not v5', evse_config_is_v5(null) === false);

echo $f ? "$f failed\n" : "ok\n"; exit($f ? 1 : 0);
