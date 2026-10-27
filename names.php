<?php
/******************************************************************************
 * names.php - all unit names at a glance (admins only: shows MAC addresses).
 * Read live from the units when the page opens (GET /config and /status only;
 * nothing is written). names.php?csv=1 downloads the same data as CSV.
 * Suggested hostnames: settings_lib.php oev_host_suggest() (rule in Help).
 ******************************************************************************/
require_once __DIR__ . '/auth_lib.php';
oev_require('admin');
require_once __DIR__ . '/include_v5.php';
require_once __DIR__ . '/collector/session_logic.php';   // sess_rapi()
require_once __DIR__ . '/util_lib.php';   // h(), ago(), oev_sess_ro()
@set_time_limit(90);

$db = oev_db();
$rows = $db->query('SELECT id, name, evseurl, loadgroupid, emon_mode FROM evses ORDER BY id')->fetchAll();
$sug = oev_host_suggest($rows);
$nodes = array();
try { $s = oev_sess_ro(3); $nodes = $s->query('SELECT unit_id, node FROM emon_nodes WHERE key_hash IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR); $s = null; } catch (Exception $e) {}
$arp = array();
foreach ((@file('/proc/net/arp', FILE_IGNORE_NEW_LINES) ?: array()) as $l) { $p = preg_split('/\s+/', trim($l)); if (isset($p[3]) && preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $p[3]) && $p[3] !== '00:00:00:00:00:00') $arp[$p[0]] = strtoupper($p[3]); }

// one unit, read only -> plain values (+ what this site expects)
function nm_read($u, $sug, $nodes, $arp){
	$id = (int)$u['id'];
	$o = array('id' => $id, 'name' => $u['name'], 'ip' => '', 'host' => '', 'sug' => $sug, 'mac' => '', 'serial' => '', 'node' => '', 'node_exp' => '', 'topic' => '', 'topic_exp' => '', 'wifi' => '', 'ctl' => '', 'err' => '');
	$h = (string)parse_url($u['evseurl'], PHP_URL_HOST); $o['ip'] = $h;
	if (($u['emon_mode'] ?: 'internal') === 'internal' && isset($nodes[$id])) $o['node_exp'] = (string)$nodes[$id];
	if ($u['loadgroupid']) $o['topic_exp'] = 'openevse/u' . $id;
	$r = evse_http($u, 'GET', 'config');
	if (!$r['ok'] || !is_array($r['json'])) { $o['err'] = $r['code'] ? 'HTTP ' . $r['code'] : 'no answer'; return $o; }
	$c = $r['json']; $x = evse_http($u, 'GET', 'status'); $st = ($x['ok'] && is_array($x['json'])) ? $x['json'] : array();
	if (!empty($st['ipaddress'])) $o['ip'] = (string)$st['ipaddress'];
	elseif (!filter_var($h, FILTER_VALIDATE_IP)) $o['ip'] = (string)gethostbyname($h);
	$o['host'] = (string)($c['hostname'] ?? '');
	$o['mac'] = strtoupper((string)($st['macaddress'] ?? '')) ?: ($arp[$o['ip']] ?? '');
	$o['wifi'] = (string)($c['version'] ?? ''); $o['ctl'] = (string)($c['firmware'] ?? '');
	$o['serial'] = (string)($c['evse_serial'] ?? '');
	if ($o['serial'] === '' && (int)$o['ctl'] >= 8) { $g = sess_rapi($u, '$GI'); if (is_string($g) && preg_match('/^[0-9A-F]{6,}$/i', trim($g))) $o['serial'] = trim($g); }
	$o['node'] = !empty($c['emoncms_enabled']) ? (string)($c['emoncms_node'] ?? '') : 'off';
	$o['topic'] = !empty($c['mqtt_enabled']) ? (string)($c['mqtt_topic'] ?? '') : 'off';
	return $o;
}
$units = evse_units(array_map(function($r){ return (int)$r['id']; }, $rows));   // logins decrypted in memory only
$data = array(); $t0 = microtime(true);
foreach ($rows as $r) { $id = (int)$r['id']; $u = isset($units[$id]) ? array_merge($units[$id], array('loadgroupid' => $r['loadgroupid'], 'emon_mode' => $r['emon_mode'])) : $r; $data[] = nm_read($u, $sug[$id] ?? '', $nodes, $arp); }
$read_at = time();

/* ---------- CSV ---------- */
if (!empty($_GET['csv'])) {
	$fn = 'openevse-unit-names-' . date('Y-m-d-Hi', $read_at) . '.csv';
	header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $fn . '"'); header('Cache-Control: no-store');
	$out = fopen('php://output', 'w');
	$safe = function($v){ $v = (string)$v; return preg_match('/^[=+@\t\r]/', $v) ? "'" . $v : $v; };   // no spreadsheet formulas
	fputcsv($out, array('#', 'Name', 'IP', 'Hostname', 'Suggested hostname', 'MAC', 'Controller Serial', 'Emoncms node', 'Expected node', 'MQTT topic', 'Expected topic', 'WiFi fw', 'Controller fw', 'Read error', 'Read at'), ',', '"', '');
	foreach ($data as $d) fputcsv($out, array_map($safe, array($d['id'], $d['name'], $d['ip'], $d['host'], $d['sug'], $d['mac'], $d['serial'], $d['node'], $d['node_exp'], $d['topic'], $d['topic_exp'], $d['wifi'], $d['ctl'], $d['err'], date('Y-m-d H:i:s T', $read_at))), ',', '"', '');
	fclose($out); exit;
}

/* ---------- page ---------- */
$title = 'Unit names'; $wq = '';
include __DIR__ . '/_head.php';
$dash = '<span class="nm-x">&mdash;</span>';
$cell = function($v) use ($dash){ return ($v === '' || $v === null) ? $dash : h($v); };
$warn = function($want){ return '<div class="nm-w">&#9888; ' . h($want) . '</div>'; };
?>
<style>
@media screen and (min-width:1024px){ body{zoom:100%} }   /* this page shows a wide table: no desktop enlarging */
.nm{--nw:min(1400px,calc(100vw - 16px));width:var(--nw);box-sizing:border-box;margin:4px 0 20px calc(50% - var(--nw) / 2);padding:10px 12px;background:#fff;border-radius:8px;font:16px/1.4 Arial,sans-serif;color:#000;text-align:left}
.nm-top{display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px;margin:0 0 10px 0;color:#444}
.nm-top h2{margin:0;font-size:20px;color:#035a5a}
.nm-top a.btn{display:inline-block;padding:6px 14px;border-radius:5px;background:#00706f;color:#fff;text-decoration:none;font-weight:bold}
.nm a.lnk{color:#035a5a}
.nm-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.nm table{border-collapse:collapse;width:100%;background:#fff;font-size:16px}
.nm th,.nm td{border:1px solid #ddd;padding:6px 10px;text-align:left;vertical-align:top;white-space:nowrap}
.nm th{background:#f2f2f2;font-weight:bold;color:#222}
.nm td.num{text-align:right}
.nm .nm-w{font-size:14px;color:#8a5a00;margin-top:2px}
.nm .nm-x{color:#999}
.nm .nm-err{color:#a11}
.nm-foot{margin:8px 0 0 0;color:#555}
</style>
<div class="nm">
<div class="nm-top"><h2>Unit names</h2><a class="btn" href="names.php?csv=1">Download CSV</a><a class="lnk" href="settings.php?s=units">Settings &gt; Units</a><span>Read from the units at <?=h(date('g:i a T', $read_at))?></span></div>
<div class="nm-wrap"><table>
<tr><th>#</th><th>Name</th><th>IP</th><th>Hostname</th><th>MAC</th><th>Controller Serial</th><th>Emoncms node</th><th>MQTT topic</th><th>WiFi fw</th><th>Controller fw</th></tr>
<?php foreach ($data as $d) {
	echo '<tr><td class="num">' . (int)$d['id'] . '</td><td>' . h($d['name']) . '</td><td>' . $cell($d['ip']) . '</td>';
	if ($d['err'] !== '') { echo '<td colspan="7" class="nm-err">Could not read the unit (' . h($d['err']) . ')</td></tr>'; continue; }
	echo '<td>' . $cell($d['host']) . ($d['host'] !== $d['sug'] ? $warn('suggested: ' . $d['sug']) : '') . '</td>';
	echo '<td>' . $cell($d['mac']) . '</td><td>' . $cell($d['serial']) . '</td>';
	echo '<td>' . $cell($d['node']) . ($d['node_exp'] !== '' && $d['node'] !== $d['node_exp'] ? $warn('expected: ' . $d['node_exp']) : '') . '</td>';
	echo '<td>' . $cell($d['topic']) . ($d['topic_exp'] !== '' && $d['topic'] !== $d['topic_exp'] ? $warn('expected: ' . $d['topic_exp']) : '') . '</td>';
	echo '<td>' . $cell($d['wifi']) . '</td><td>' . $cell($d['ctl']) . '</td></tr>';
} ?>
</table></div>
<div class="nm-foot">Read only: nothing is changed on the units. &#9888; marks a value that differs from what this site suggests or expects. Hostnames can be set under Settings &gt; Units; the rule for suggested hostnames is in <a class="lnk" href="help.php#names">Help</a>.</div>
</div>
</div></body></html>
