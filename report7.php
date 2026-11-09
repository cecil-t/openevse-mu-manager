<?php
/******************************************************************************
 * report7.php — shared Last-7-days data endpoint.
 *  - ?json=1 → JSON for the chart embed (sessions.php top + live refresh)
 *  - HTML → redirect to Session Reports (chart lives there now)
 ******************************************************************************/
$R7_JSON = isset($_GET['json']);
require_once __DIR__ . '/auth_lib.php'; oev_require('view', $R7_JSON);
require_once __DIR__ . '/report7_lib.php';

$D = r7_data();
if ($R7_JSON) {
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	echo json_encode($D);
	exit;
}
header('Location: sessions.php#r7overview', true, 302);
exit;
