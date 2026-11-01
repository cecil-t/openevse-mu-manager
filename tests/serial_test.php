<?php
// php tests/serial_test.php
require_once __DIR__ . '/../util_lib.php';
$f = 0;
foreach (array('Y73595FF0E2D12' => true, 'ABCDEF0123' => true, '12' => false, '220' => false, '0' => false, '' => false, '6 16 12 12' => false) as $v => $want)
	if (oev_serial_ok((string)$v) !== $want) { echo "FAIL $v\n"; $f++; }
echo $f ? "$f failed\n" : "ok\n"; exit($f ? 1 : 0);
