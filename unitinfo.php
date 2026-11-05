<?php
// One unit's details for the home page's wide layout, read once when a row is opened
// (the same read-only status read unit.php does on load; never writes to the unit, no secrets).
require_once __DIR__ . '/auth_lib.php'; oev_require('public', true);   // login gate (auth_lib.php)
include __DIR__ . '/include_v5.php';
require_once __DIR__ . '/index_wide_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$units = evse_units(evse_with_param());
$id = $_GET['unitid'] ?? '';
if (!ctype_digit((string)$id) || !isset($units[(int)$id])) { http_response_code(404); echo json_encode(array('ok'=>false)); exit; }
$unit = $units[(int)$id];
$e = evse_status($unit);
if (!is_array($e)) { echo json_encode(array('ok'=>false)); exit; }
evse_unit_extras($unit, $e);
$v = function($x){ return ($x === null || $x === '' || $x === 'Error') ? '' : h($x); };
$o = array('ok'=>true, 'd'=>array());
$d = &$o['d'];
if (($e['api'] ?? '') == 'http') {
	$ov = (is_array($e['override'] ?? null) && $e['override']) ? $e['override'] : null; $ovt = 'None';
	if ($ov) { $s = $ov['state'] ?? ''; $ovt = ($s == 'disabled') ? 'Sleep' : (($s == 'active') ? 'Awake' : ($s !== '' ? ucfirst($s) : 'Custom')); if (isset($ov['auto_release']) && !$ov['auto_release']) $ovt .= '*'; }
	$d['ovr'] = h($ovt);
}
$d['amps'] = ($e['amps'] ?? null) === null ? '' : h($e['amps']) . ' amps';
if (isset($e['volts']) && $e['volts']) $d['volts'] = h($e['volts']) . ' volts';
$d['power'] = ($e['power'] ?? null) === null ? '' : h($e['power']) . ' kW';
if (isset($e['temp']) && is_numeric($e['temp'])) $d['temp'] = idx_temp_html(((float)$e['temp'] - 32) * 5 / 9);
if (isset($e['energy']) && $e['energy'] !== null) $d['energy'] = h($e['energy']) . ' kWh';
if (array_key_exists('check_diode', $e) && $e['check_diode'] !== null) {
	$c = ''; foreach (array('diode'=>'D','vent'=>'V','ground'=>'G','relay'=>'R','gfci'=>'F','temp'=>'T') as $k => $l) $c .= !empty($e['check_' . $k]) ? $l : '<span class="e">' . strtolower($l) . '</span>';
	$d['checks'] = '<span class="ok">' . $c . '</span>';
}
$d['time'] = $v($e['time'] ?? '');
$upt = evse_uptime_info($unit, $e); $d['uptime'] = $upt['text'] === "\u{2014}" ? '' : h($upt['text']);
$fw = trim((string)($e['firmware'] ?? '')); $wv = trim((string)($e['version'] ?? ''));
if ($fw !== '' || $wv !== '') $d['fw'] = ($fw !== '' ? h($fw) : '&mdash;') . ' / ' . ($wv !== '' ? h($wv) : '&mdash;');
$d['api'] = $v($e['api'] ?? '');
if (oev_is_admin()) {   // unit address: admins only
	$d['host'] = $v($e['hostname'] ?? '');
	if (!empty($e['ipaddress'])) $d['ip'] = h($e['ipaddress']);
}
$d['ssid'] = $v($e['ssid'] ?? '');
if (isset($e['srssi']) && $e['srssi'] !== null) $d['rssi'] = idx_bars_html($e['srssi']);
if (isset($e['timerstart']) && $e['timerstart'] !== '') $d['start'] = ($e['timerstart'] === 'None') ? 'None' : h($e['timerstart'] . ' / ' . $e['timerend']);
$lim = array();
foreach (array('chargelimit', 'timelimit') as $k) if (isset($e[$k]) && $e[$k] !== 'None' && $e[$k] !== '' && $e[$k] !== "\u{2014}") $lim[] = $e[$k];
if (isset($e['chargelimit'])) $d['lim'] = $lim ? h(implode(" \u{00B7} ", $lim)) : 'None';
if (isset($e['level']) && $e['level'] !== null) $d['level'] = 'L' . h($e['level']) . (isset($e['autolevel']) ? ' (' . h(strtolower($e['autolevel'])) . ')' : '');
$d = array_filter($d, function($x){ return $x !== ''; });   // unknown: keep what the page already shows
echo json_encode($o);
