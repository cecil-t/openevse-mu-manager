<?php
// Handles the unit page buttons (POST only), for all units (v5 HTTP API and WiFi 2.x RAPI).
require_once __DIR__ . '/auth_lib.php'; oev_require('admin');   // login gate (auth_lib.php)
oev_csrf_check();
include __DIR__ . '/include_v5.php';
$with  = isset($_POST['with']) ? array_filter(array_map('intval', explode(',', $_POST['with']))) : array();
$units = evse_units($with);
$unitid = isset($_POST['unitid']) ? $_POST['unitid'] : '';
$back = function($msg) use ($unitid, $with){
	$u = 'unit.php?unitid=' . (int)$unitid . ($with ? '&with=' . implode(',', $with) : '') . '&msg=' . urlencode($msg);
	header('Location: ' . $u, true, 303); exit;
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die('POST only'); }
if (!ctype_digit((string)$unitid) || !isset($units[(int)$unitid])) { http_response_code(400); die('invalid unitid'); }
$unit = $units[(int)$unitid];
include_once __DIR__ . '/include_lb.php'; evse_home_slow_clear($unit);   // home page re-reads this unit's schedule / limit on its next load
$a = isset($_POST['action']) ? $_POST['action'] : '';
$v = isset($_POST['value']) ? $_POST['value'] : '';
switch ($a) {
	case 'sleep':          $r = evse_sleepwake($unit, 'sleep'); break;
	case 'wake':           $r = evse_sleepwake($unit, 'wake'); break;
	case 'enable':         $r = evse_sleepwake($unit, 'wake'); break;   // old form
	case 'button':         $r = evse_button($unit); break;             // old form, state-aware
	case 'clear_override': $r = evse_clear_override($unit); break;
	case 'current':        $r = evse_set_current($unit, isset($_POST['amps']) ? $_POST['amps'] : 0); break;
	case 'level':          $r = evse_set_level($unit, $v); break;
	case 'energylimit':    $r = evse_set_energy_limit($unit, $v); break;
	case 'timelimit':      $r = evse_set_time_limit($unit, isset($_POST['code']) ? $_POST['code'] : 0); break;
	case 'timer':          $r = evse_set_timer($unit, isset($_POST['start']) ? $_POST['start'] : '', isset($_POST['end']) ? $_POST['end'] : ''); break;
	case 'synctime':       $r = evse_sync_time($unit); break;
	case 'restart':        $r = evse_restart($unit, 'evse'); sleep(8); break;
	default:               $r = array('ok'=>false, 'msg'=>'Unknown action');
}
$back($r['msg']);
