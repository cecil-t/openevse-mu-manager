<?php
// READ CONNECTIONS FROM openevse.db AND LOAD $e DICTIONARIES with connection information INTO $evses DICTIONARY
// (enabled units only). Unit passwords are stored encrypted and decrypted here (secret_lib.php);
// on the web, only the units the logged-in user may see are loaded (auth_lib.php scope).
// Nothing is printed: opening this file in a browser shows nothing.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/settings_lib.php';

$dbh = new PDO('sqlite:' . __DIR__ . '/openevse.db');
$rs = $dbh->query('SELECT * FROM evses WHERE enabled ORDER BY name');
while ($row = $rs->fetch(PDO::FETCH_ASSOC)) {
	if (PHP_SAPI !== 'cli' && function_exists('oev_unit_visible') && !oev_unit_visible($row)) continue;
	$evses[$row['id']] = oev_unit_row($row);
}
$dbh = null;
