<?php
/******************************************************************************
 * unitlist.php - Unit List: every unit at a glance (admins only: shows MAC addresses).
 * Read live from the units when the page opens (GET /config and /status only;
 * nothing is written). unitlist.php?csv=1 downloads the same data as CSV.
 * Suggested hostnames: settings_lib.php oev_host_suggest() (rule in Help).
 ******************************************************************************/
require_once __DIR__ . '/auth_lib.php';
oev_require('admin');
require_once __DIR__ . '/include_v5.php';
require_once __DIR__ . '/collector/session_logic.php';   // sess_rapi()
require_once __DIR__ . '/util_lib.php';   // h(), ago(), oev_sess_ro()
@set_time_limit(90);

$db = oev_db();
$rows = $db->query('SELECT id, name, evseurl, loadgroupid, emon_mode, breaker FROM evses ORDER BY id')->fetchAll();
$sug = oev_host_suggest($rows);
$nodes = array();
try { $s = oev_sess_ro(3); $nodes = $s->query('SELECT unit_id, node FROM emon_nodes WHERE key_hash IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR); $s = null; } catch (Exception $e) {}
$arp = array();
foreach ((@file('/proc/net/arp', FILE_IGNORE_NEW_LINES) ?: array()) as $l) { $p = preg_split('/\s+/', trim($l)); if (isset($p[3]) && preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $p[3]) && $p[3] !== '00:00:00:00:00:00') $arp[$p[0]] = strtoupper($p[3]); }

// the browser is on the units' own network: REMOTE_ADDR (the app trusts no proxy headers) is a private IPv4 address in the same /24 as the unit's IP
function nm_same_net($client, $ip){
	$f = FILTER_FLAG_IPV4;
	if (!is_string($client) || !is_string($ip) || filter_var($client, FILTER_VALIDATE_IP, $f) !== $client || filter_var($ip, FILTER_VALIDATE_IP, $f) !== $ip) return false;
	if (filter_var($client, FILTER_VALIDATE_IP, $f | FILTER_FLAG_NO_PRIV_RANGE) !== false) return false;   // public or Tailscale (100.64/10): not private
	return (ip2long($client) & 0xFFFFFF00) === (ip2long($ip) & 0xFFFFFF00);
}
// the unit's own web page, from its Address (Settings > Units): scheme, host and port only, never a login
function nm_web($evseurl){
	$p = parse_url((string)$evseurl); if (!is_array($p) || empty($p['host'])) return '';
	$sc = strtolower($p['scheme'] ?? 'http'); if ($sc !== 'http' && $sc !== 'https') return '';
	$h = $p['host']; if (filter_var(trim($h, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) $h = '[' . trim($h, '[]') . ']';
	return $sc . '://' . $h . (isset($p['port']) ? ':' . (int)$p['port'] : '') . '/';
}
// one unit, read only -> plain values (+ what this site expects)
function nm_read($u, $sug, $nodes, $arp){
	$id = (int)$u['id'];
	$o = array('id' => $id, 'name' => $u['name'], 'ip' => '', 'host' => '', 'sug' => $sug, 'mac' => '', 'serial' => '', 'node' => '', 'node_exp' => '', 'topic' => '', 'topic_exp' => '', 'wifi' => '', 'ctl' => '', 'max' => '', 'pilot' => '', 'vol' => '', 'err' => '');
	if (!empty($u['breaker'])) $o['max'] = (string)(int)$u['breaker'];   // MU Manager Max current (Settings > Units)
	$h = (string)parse_url($u['evseurl'], PHP_URL_HOST); $o['ip'] = $h; $o['web'] = nm_web($u['evseurl']);
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
	if (!oev_serial_ok($o['serial']) && (int)$o['ctl'] >= 8) {   // missing or a crossed boot reply in the module: ask the controller ($GI)
		$bad = $o['serial']; $g = sess_rapi($u, '$GI');
		$o['serial'] = (is_string($g) && oev_serial_ok($g)) ? trim($g) : ($bad === '' ? '' : 'invalid (' . $bad . ')');
	}
	$o['node'] = !empty($c['emoncms_enabled']) ? (string)($c['emoncms_node'] ?? '') : 'off';
	if (isset($c['max_current_soft']) && $c['max_current_soft'] !== '') $o['pilot'] = (string)(int)$c['max_current_soft'];   // saved pilot, not the LB's temporary one
	if (isset($st['pilot']) && $st['pilot'] !== '') $o['vol'] = (string)(int)$st['pilot'];   // live (volatile) pilot
	$o['topic'] = !empty($c['mqtt_enabled']) ? (string)($c['mqtt_topic'] ?? '') : 'off';
	return $o;
}
$units = evse_units(array_map(function($r){ return (int)$r['id']; }, $rows));   // logins decrypted in memory only
$data = array(); $t0 = microtime(true);
foreach ($rows as $r) { $id = (int)$r['id']; $u = isset($units[$id]) ? array_merge($units[$id], array('loadgroupid' => $r['loadgroupid'], 'emon_mode' => $r['emon_mode'], 'breaker' => $r['breaker'])) : $r; $data[] = nm_read($u, $sug[$id] ?? '', $nodes, $arp); }
$read_at = time();

/* ---------- CSV ---------- */
if (!empty($_GET['csv'])) {
	$fn = 'openevse-unit-list-' . date('Y-m-d-Hi', $read_at) . '.csv';
	header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $fn . '"'); header('Cache-Control: no-store');
	$out = fopen('php://output', 'w');
	$safe = function($v){ $v = (string)$v; return preg_match('/^[=+@\t\r]/', $v) ? "'" . $v : $v; };   // no spreadsheet formulas
	fputcsv($out, array('#', 'Name', 'IP', 'Hostname', 'Suggested hostname', 'MAC', 'Controller Serial', 'Max A', 'Pilot', 'Volatile', 'Emoncms node', 'Expected node', 'MQTT topic', 'Expected topic', 'WiFi fw', 'Controller fw', 'Read error', 'Read at'), ',', '"', '');
	foreach ($data as $d) fputcsv($out, array_map($safe, array($d['id'], $d['name'], $d['ip'], $d['host'], $d['sug'], $d['mac'], $d['serial'], $d['max'], $d['pilot'], $d['vol'], $d['node'], $d['node_exp'], $d['topic'], $d['topic_exp'], $d['wifi'], $d['ctl'], $d['err'], date('Y-m-d H:i:s T', $read_at))), ',', '"', '');
	fclose($out); exit;
}

/* ---------- page ---------- */
$title = 'Unit List'; $wq = '';
$OEV_WIDE = 1; include __DIR__ . '/_head.php';
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
.nm table{border-collapse:collapse;width:100%;background:#fff;font-size:15px}
.nm th,.nm td{border:1px solid #ddd;padding:4px 6px;text-align:left;vertical-align:top;white-space:nowrap}
.nm th{background:#f2f2f2;font-weight:bold;color:#222}
.nm td.num{text-align:right}
.nm td.sm{font-size:13px}
.nm .nm-w{font-size:14px;color:#8a5a00;margin-top:2px}
.nm .nm-x{color:#999}
@media screen and (min-width:700px){.nm table,.nm td.sm{font-size:13px}.nm .nm-w{font-size:12px}.nm th,.nm td{padding:4px}}   /* desktop: the whole table in the small font so it fits the card */
.nm .nm-err{color:#a11}
.nm-foot{margin:8px 0 0 0;color:#555}
</style>
<div class="nm pgcard">
<div class="nm-top"><h2>Unit List</h2><a class="btn" href="unitlist.php?csv=1">Download CSV</a><a class="lnk" href="settings.php?s=units">Settings &gt; Units</a><span>Read from the units at <?=h(date('g:i a T', $read_at))?></span></div>
<p style="margin:0 0 8px 0;color:#555">Unit names link to each unit's own web page when you're on the same network as the units.</p>
<div class="nm-wrap"><table>
<tr><th>#</th><th>Name</th><th>IP</th><th>Hostname</th><th>MAC</th><th>Controller Serial</th><th>Max A</th><th>Pilot</th><th>Volatile</th><th>Node*</th><th>MQTT topic</th><th>WiFi fw</th><th>Cntrl fw</th></tr>
<?php foreach ($data as $d) {
	$nm = ($d['web'] ?? '') !== '' && nm_same_net($_SERVER['REMOTE_ADDR'] ?? '', $d['ip']) ? '<a class="lnk" href="' . h($d['web']) . '" target="_blank" rel="noopener noreferrer">' . h($d['name']) . '</a>' : h($d['name']);
	echo '<tr><td class="num">' . (int)$d['id'] . '</td><td>' . $nm . '</td><td>' . $cell($d['ip']) . '</td>';
	if ($d['err'] !== '') { echo '<td colspan="10" class="nm-err">Could not read the unit (' . h($d['err']) . ')</td></tr>'; continue; }
	echo '<td' . (strlen($d['host']) > 20 ? ' class="sm"' : '') . '>' . $cell($d['host']) . '</td>';   // hostname hints live on Settings > Units only
	echo '<td class="sm">' . $cell($d['mac']) . '</td><td class="sm">' . $cell($d['serial']) . '</td>';
	echo '<td class="num">' . $cell($d['max']) . '</td><td class="num">' . $cell($d['pilot']) . '</td><td class="num">' . ($d['vol'] === '' ? $dash : '<span' . ($d['vol'] === $d['pilot'] ? ' style="opacity:.4" title="Same as Pilot: no temporary setting"' : '') . '>(' . h($d['vol']) . ')</span>') . '</td>';
	echo '<td class="num">' . $cell($d['node']) . ($d['node_exp'] !== '' && $d['node'] !== $d['node_exp'] ? $warn('expected: ' . $d['node_exp']) : '') . '</td>';
	echo '<td>' . $cell($d['topic']) . ($d['topic_exp'] !== '' && $d['topic'] !== $d['topic_exp'] ? $warn('expected: ' . $d['topic_exp']) : '') . '</td>';
	echo '<td>' . $cell($d['wifi']) . '</td><td>' . $cell($d['ctl']) . '</td></tr>';
} ?>
</table></div>
<div class="nm-foot">* Node: the Emoncms node number this unit posts its data to (normally its unit number).<br>Max A: the Max current setting in MU Manager (Settings &gt; Units). This is the maximum the interface will allow the Pilot to be set to.<br>Pilot: the unit's saved pilot current (not the load balancer's temporary setting).<br>Volatile: the live current in use right now (the pilot setting, or a volatile setting overridden by the load balancer which is not saved; a reboot clears it).<br>Read only: nothing is changed on the units.</div>
</div>
</div></body></html>
