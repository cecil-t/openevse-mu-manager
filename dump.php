<?php
require_once __DIR__ . '/auth_lib.php'; oev_require('view');   // login gate (auth_lib.php)
include __DIR__ . '/include_v5.php';
$units = evse_units(evse_with_param());
$unitid = isset($_GET['unitid']) ? $_GET['unitid'] : '';
if (!ctype_digit((string)$unitid) || !isset($units[(int)$unitid])) die('Missing or invalid unit id.');
$e = evse_status($units[(int)$unitid]);
header('Content-Type: text/plain; charset=utf-8');
echo is_array($e) ? print_r($e, true) : "Connection error: $e\n";
