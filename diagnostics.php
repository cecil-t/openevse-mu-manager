<?php
/******************************************************************************
 * diagnostics.php — faults, thermal, WiFi / drop-offs, monthly health.
 * View auth (not public). Owns the monthly thermal/WiFi health view
 * (sessions redirects here). Per-unit reporting lives on Settings → System status.
 ******************************************************************************/
require_once __DIR__ . '/auth_lib.php'; oev_require('view');
$__vis = oev_visible_unit_ids();
$SC = ($__vis === null) ? '' : ' AND unit_id IN (' . ($__vis ? implode(',', $__vis) : '0') . ')';
require __DIR__ . '/sessions_lib.php';
require_once __DIR__ . '/evse_prefs.php';
require_once __DIR__ . '/util_lib.php';
$wq = '';
$title = 'Diagnostics';
$OEV_WIDE = 1; include __DIR__ . '/_head.php';
?>
<style>
.card{background:#eee;border:1px solid #ccc;border-radius:8px;padding:8px 10px;margin:8px 0;color:#555;font-size:15px}
.card b{color:#333}.card .r{float:right}.card .sm{font-size:13px;color:#777}
.tag{display:inline-block;font-size:11px;padding:1px 5px;border-radius:4px;background:#ddd;color:#444;margin:2px 2px 0 0}
.tag.f{background:#f99;color:#600}.tag.o{background:#9cf;color:#035}
h4{margin:14px 0 4px 0;color:#333}
.kv{display:flex;justify-content:space-between;border-bottom:1px dotted #ccc;padding:2px 0;gap:10px}
.kv span{white-space:nowrap}.kv span:first-child{flex:0 0 auto;color:#888}.kv span:last-child{overflow:hidden;text-overflow:ellipsis;text-align:right;min-width:0}
form.flt select,form.flt input{font-size:15px;margin:2px 0;max-width:100%}
.ev{border-left:3px solid #aaa;padding:2px 0 2px 7px;margin:5px 0;font-size:14px}
.ev.fault,.ev.fault_count{border-color:#e33}.ev.thermal{border-color:#f90}.ev.reach,.ev.reboot{border-color:#888;border-left-style:dashed}
.sec{background:rgba(246,246,246,.95);border:1px solid #ccc;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.18);padding:6px 10px;margin:10px 0;color:#444}
.sec>h4:first-child{margin-top:2px}.sec>p{margin:4px 0}.sec .card{background:#fff;margin:6px 0}
.sm{font-size:13px;color:#777}.err{color:#b11}
</style>
<?php
if (!is_file(SESS_DB_PATH)) { echo '<div class="sec">No session data yet. The collector has not run.</div>'.oev_home_footer().'</div></body></html>'; exit; }
try { $db = sess_db(true); } catch (Throwable $e) { echo '<div class="sec">Session database unavailable.</div>'.oev_home_footer().'</div></body></html>'; exit; }

function fdt($ts, $fmt = 'D m/d g:ia'){ return $ts ? date($fmt, (int)$ts) : '-'; }
$units = $db->query("SELECT * FROM unit_state WHERE 1$SC ORDER BY unit_name")->fetchAll();
define('SESS_THERMAL_C_PAGE', 65);

$unit = isset($_GET['unit']) && ctype_digit((string)$_GET['unit']) ? (int)$_GET['unit'] : 0;
$from = (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])) ? $_GET['from'] : date('Y-m-d', strtotime('-90 days'));
$to   = (isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']))   ? $_GET['to']   : date('Y-m-d');
$f = strtotime($from . ' 00:00:00'); $t = strtotime($to . ' 23:59:59');
$uw = ($unit ? " AND unit_id = $unit" : '') . $SC;

$faults = $db->query("SELECT * FROM events WHERE kind IN ('fault','fault_count','thermal') AND ts BETWEEN $f AND $t$uw ORDER BY ts DESC LIMIT 40")->fetchAll();
/* WiFi summary: fixed 7d / 30d / all-time windows (not the form date range). Flag if reboots in 7d >= threshold. */
$WIFI_FLAG_7D = 5;
$t7 = time() - 7 * 86400; $t30 = time() - 30 * 86400;
$wifi_rows = array();
foreach ($db->query("SELECT unit_id,
	sum(CASE WHEN kind = 'reboot' AND ts >= $t7 THEN 1 ELSE 0 END) rb7,
	sum(CASE WHEN kind = 'reboot' AND ts >= $t30 THEN 1 ELSE 0 END) rb30,
	sum(CASE WHEN kind = 'reboot' THEN 1 ELSE 0 END) rball,
	sum(CASE WHEN kind = 'reach' AND reason IN ('unreachable', 'push stopped') AND ts >= $t7 THEN 1 ELSE 0 END) gap7
	FROM events WHERE kind IN ('reboot', 'reach') AND 1$SC" . ($unit ? " AND unit_id = $unit" : '') . "
	GROUP BY unit_id") as $w) $wifi_rows[(int)$w['unit_id']] = $w;

/* Monthly all-time thermal / WiFi */
$M = "strftime('%Y-%m', ts, 'unixepoch', 'localtime')";
$rows = array();
foreach ($db->query("SELECT $M m, unit_id,
		avg(CASE WHEN session_id IS NULL THEN temp_c END) idle_avg, max(CASE WHEN session_id IS NULL THEN temp_c END) idle_max,
		avg(CASE WHEN state = 3 THEN temp_c END) chg_avg, max(CASE WHEN state = 3 THEN temp_c END) chg_max,
		avg(srssi) rssi_avg, min(srssi) rssi_min
		FROM samples WHERE (temp_c IS NOT NULL OR srssi IS NOT NULL)$uw GROUP BY m, unit_id") as $x) $rows[$x['m']][$x['unit_id']] = $x;
foreach ($db->query("SELECT $M m, unit_id,
		sum(CASE WHEN kind = 'thermal' AND reason = 'thermal' THEN 1 ELSE 0 END) thermal,
		sum(CASE WHEN kind = 'fault' AND to_val = 10 THEN 1 ELSE 0 END) overtemp,
		sum(CASE WHEN kind = 'reach' AND reason IN ('unreachable', 'push stopped') THEN 1 ELSE 0 END) drops,
		sum(CASE WHEN kind = 'reboot' THEN 1 ELSE 0 END) reboots
		FROM events WHERE 1$uw GROUP BY m, unit_id") as $x) $rows[$x['m']][$x['unit_id']] = array_merge(isset($rows[$x['m']][$x['unit_id']]) ? $rows[$x['m']][$x['unit_id']] : array(), $x);
krsort($rows);
$names = array(); foreach ($units as $u) $names[$u['unit_id']] = $u['unit_name'];
$t1 = function($v){ return temp_num($v); };
// Lifetime fault counters from collector high-water (unit_state) — not live /status (false 0s after WiFi restart)
$life = array();
foreach ($units as $u) {
	if ($unit && (int)$u['unit_id'] !== $unit) continue;
	$life[] = $u;
}
// WiFi lines: every in-scope unit (zeros when no events yet), sorted by unit_state name
$wifi_list = array();
foreach ($units as $u) {
	if ($unit && (int)$u['unit_id'] !== $unit) continue;
	$uid = (int)$u['unit_id'];
	$w = $wifi_rows[$uid] ?? array('rb7'=>0,'rb30'=>0,'rball'=>0,'gap7'=>0);
	$wifi_list[] = array(
		'unit_id' => $uid,
		'name' => (string)$u['unit_name'],
		'rb7' => (int)$w['rb7'], 'rb30' => (int)$w['rb30'], 'rball' => (int)$w['rball'],
		'gap7' => (int)$w['gap7'],
		'flag' => ((int)$w['rb7'] >= $WIFI_FLAG_7D),
	);
}
usort($wifi_list, function($a, $b){ return strnatcasecmp($a['name'], $b['name']); });

?>
<div class="sec"><h3 style="margin:4px 0 6px 0;color:#333">Diagnostics</h3>
<p class="sm">Faults, thermal events, WiFi drop-offs, and monthly health. Energy history stays on <a href="sessions.php">Session Reports</a>. Per-unit reporting and collector / system health for admins: Settings → System status.</p></div>

<form class="flt card" method="get" id="diagfilt">
	<select name="unit"><option value="0">All units</option>
	<?php foreach ($units as $u) echo '<option value="' . (int)$u['unit_id'] . '"' . ($unit == $u['unit_id'] ? ' selected' : '') . '>' . h($u['unit_name']) . '</option>'; ?>
	</select><br>
	<input type="date" name="from" value="<?=h($from)?>"> to <input type="date" name="to" value="<?=h($to)?>">
	<button type="submit">Show</button>
</form>
<p class="sec sm">Recent logs use the date range above (default last 90 days). Monthly temperatures below are all-time.</p>

<div class="sec"><h4>Faults &amp; thermal</h4>
<div class="card" style="background:#fff"><b>Lifetime fault counts</b>
<p class="sm" style="margin:2px 0 6px 0">Controller counters kept by the collector (survive WiFi restarts). Not a live /status read.</p>
<?php if (!$life) echo '<p class="sm">No units in scope.</p>';
$fc = function($v){ return ($v === null || $v === '') ? "\u{2014}" : (string)(int)$v; };
foreach ($life as $u) {
	$nm = $names[(int)$u['unit_id']] ?? $u['unit_name'];
	echo '<div class="kv"><span>' . h($nm) . '</span><span>GFCI ' . $fc($u['gfci_count']) . ' &middot; No ground ' . $fc($u['nognd_count']) . ' &middot; Stuck ' . $fc($u['stuck_count']) . '</span></div>';
} ?>
</div>
<?php if (!$faults) echo '<p class="sm">None logged in this range.</p>'; ?>
<?php foreach ($faults as $e) { ?>
	<div class="ev <?=h($e['kind'])?>"><b><?=fdt($e['ts'])?></b> <?=h($e['unit_name'])?>: <?=h($e['detail'])?>
	<br><span class="sm"><?=$e['temp_c'] !== null ? temp_txt($e['temp_c']) : ''?><?=$e['session_id'] ? ' &middot; <a href="sessions.php?id=' . (int)$e['session_id'] . '">session</a>' : ' &middot; not in a session'?></span></div>
<?php } ?></div>

<div class="sec"><h4>WiFi drop-offs &amp; reboots</h4>
<p class="sm">Per-unit WiFi restart counts from the collector (not a live /status read). Flagged when &ge; <?=$WIFI_FLAG_7D?> restarts in the last 7 days. Gaps = times the collector could not reach the unit.</p>
<?php if (!$wifi_list) echo '<p class="sm">No units in scope.</p>'; ?>
<?php foreach ($wifi_list as $w) {
	$bits = array((int)$w['rb7'] . ' in 7d', (int)$w['rb30'] . ' in 30d', (int)$w['rball'] . ' all');
	if ((int)$w['gap7'] > 0) $bits[] = (int)$w['gap7'] . ' gap' . ($w['gap7'] == 1 ? '' : 's') . ' (7d)';
	$right = implode(' &middot; ', $bits);
	if ($w['flag']) $right = '<span class="err">' . $right . ' &middot; high</span>';
	echo '<div class="kv"><span>' . h($w['name']) . '</span><span>' . $right . '</span></div>';
} ?>
</div>

<div class="sec" id="monthly"><h4>Monthly temperatures &amp; thermal</h4>
<p class="sm">Per month: thermal throttling, over-temperature faults, WiFi drop-offs, temperature (<?=temp_sym()?>, hottest sensor) avg / max while idle (ambient) and while charging, and WiFi signal where the unit reports it (v5 only). Unit filter above applies; dates do not.</p>
<?php if (!$rows) echo '<p class="sm">No data yet.</p>'; ?>
<?php foreach ($rows as $m => $us_) { ksort($us_); ?>
	<div class="card" style="background:#fff"><b><?=h(date('F Y', strtotime($m . '-01')))?></b>
	<?php foreach ($us_ as $uid => $x) { $th = (int)($x['thermal'] ?? 0); $ot = (int)($x['overtemp'] ?? 0); $dr = (int)($x['drops'] ?? 0); ?>
		<div style="border-top:1px dotted #ddd;padding:4px 0;margin-top:4px"><b><?=h($names[$uid] ?? "unit $uid")?></b>
			<span class="r"><?=$th ? "<span class='tag f'>$th thermal</span>" : '<span class="tag">0 thermal</span>'?><?=$ot ? "<span class='tag f'>$ot over-temp</span>" : ''?><?=$dr ? "<span class='tag'>$dr drop-off" . ($dr == 1 ? '' : 's') . "</span>" : ''?><?=!empty($x['reboots']) ? "<span class='tag'>" . (int)$x['reboots'] . " reboot" . ($x['reboots'] == 1 ? '' : 's') . "</span>" : ''?></span><br>
			<span class="sm">idle <?=$t1($x['idle_avg'] ?? null)?> / <?=$t1($x['idle_max'] ?? null)?> &middot; charging <?=$t1($x['chg_avg'] ?? null)?> / <?=$t1($x['chg_max'] ?? null)?><?=isset($x['rssi_avg']) && $x['rssi_avg'] !== null ? ' &middot; WiFi avg ' . round($x['rssi_avg']) . ' dBm (worst ' . (int)$x['rssi_min'] . ')' : ''?></span>
		</div>
	<?php } ?></div>
<?php } ?>
<p class="sm">avg / max <?=temp_sym()?>. Throttling starts at <?=temp_txt(SESS_THERMAL_C_PAGE)?> ambient.</p></div>

<?=oev_home_footer()?>
</div></body></html>
