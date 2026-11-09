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
.card{background:#eee;border:1px solid #ccc;border-radius:8px;padding:8px 10px;margin:8px 0;color:#555;font-size:15px;box-sizing:border-box;max-width:100%}
.card b{color:#333}.card .r{float:right}.card .sm{font-size:13px;color:#777}
.tag{display:inline-block;font-size:11px;padding:1px 5px;border-radius:4px;background:#ddd;color:#444;margin:2px 2px 0 0}
.tag.f{background:#f99;color:#600}.tag.o{background:#9cf;color:#035}
h4{margin:14px 0 4px 0;color:#333}
.kv{display:flex;justify-content:space-between;border-bottom:1px dotted #ccc;padding:2px 0;gap:10px}
.kv span{white-space:nowrap}.kv span:first-child{flex:0 0 auto;color:#888}.kv span:last-child{overflow:hidden;text-overflow:ellipsis;text-align:right;min-width:0}
form.flt select,form.flt input,form.flt button{font-size:15px;margin:2px 0;max-width:100%;box-sizing:border-box}
.ev{border-left:3px solid #aaa;padding:2px 0 2px 7px;margin:5px 0;font-size:14px;overflow-wrap:anywhere}
.ev.fault,.ev.fault_count{border-color:#e33}.ev.thermal{border-color:#f90}.ev.reach,.ev.reboot{border-color:#888;border-left-style:dashed}
.sec{background:rgba(246,246,246,.95);border:1px solid #ccc;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.18);padding:6px 10px;margin:10px 0;color:#444;box-sizing:border-box;max-width:100%;overflow-wrap:break-word}
.sec>h4:first-child,.sec>h3:first-child{margin-top:2px}.sec>p{margin:4px 0}.sec .card{background:#fff;margin:6px 0}
.sm{font-size:13px;color:#777}.err{color:#b11}
/* top intro+filters in one sec */
.diag-top h3{margin:4px 0 4px 0;color:#333;font-size:18px}
.diag-top .flt{background:#fff;margin:6px 0;padding:8px 10px}
.diag-top .note{margin:2px 0 0 0}
/* Unit health: compact grid (desktop) / stacked pills (mobile) */
.uh{max-width:640px;padding:6px 8px}
.uh-h,.uh-r{display:grid;grid-template-columns:minmax(6.5rem,1.6fr) 2.5rem 3.4rem 2.6rem minmax(6.5rem,1.1fr);gap:4px 8px;align-items:center;padding:6px 2px;border-bottom:1px dotted #ddd;font-size:14px}
.uh-h{font-size:11px;color:#888;border-bottom:1px solid #ccc;padding:2px 2px 5px;font-weight:600}
.uh-h span:not(:first-child),.uh-c{display:block;width:100%;box-sizing:border-box;text-align:center;font-variant-numeric:tabular-nums}
.uh-n{color:#333;font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.uh-z{color:#aaa;font-weight:400}
.uh-nz{color:#8a3030;font-weight:600}
.uh-w{color:#555;font-weight:400}
.uh-r:last-child{border-bottom:0}
.mo-row{border-top:1px dotted #ddd;padding:6px 0;margin-top:4px;overflow:hidden}
.mo-row .r{float:none;display:block;margin:2px 0 4px}
.mo-row .sm{display:block;overflow-wrap:anywhere}
@media (max-width:560px){
	.uh-h{display:none}
	.uh-r{display:flex;flex-wrap:wrap;align-items:center;gap:4px 6px;padding:8px 2px}
	.uh-n{flex:1 0 100%;white-space:normal;margin:0 0 2px;font-size:14px}
	.uh-c{display:inline-flex;align-items:center;width:auto;margin:0;padding:2px 7px;border-radius:10px;background:#eee;font-size:12px;text-align:left}
	.uh-c::before{content:attr(data-l);margin-right:.35em;color:#777;font-weight:400}
	.uh-nz{background:#fde8e8}
	.uh-z{background:#f0f0f0;color:#999}
	.uh-w{background:#eee;color:#555}
	.card .r{float:none;display:block;margin-top:3px}
}
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
/* WiFi summary: restarts in the last 7 days (not the form date range). Red when >= threshold. */
$WIFI_FLAG_7D = 5;
$t7 = time() - 7 * 86400;
$wifi_rows = array();
foreach ($db->query("SELECT unit_id,
	sum(CASE WHEN kind = 'reboot' AND ts >= $t7 THEN 1 ELSE 0 END) rb7
	FROM events WHERE kind = 'reboot' AND 1$SC" . ($unit ? " AND unit_id = $unit" : '') . "
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
// Per-unit health rows: lifetime fault counters (unit_state high-water) + WiFi restarts (7d)
$health = array();
foreach ($units as $u) {
	if ($unit && (int)$u['unit_id'] !== $unit) continue;
	$uid = (int)$u['unit_id'];
	$w = $wifi_rows[$uid] ?? array('rb7'=>0);
	$health[] = array(
		'unit_id' => $uid,
		'name' => (string)$u['unit_name'],
		'gfci' => $u['gfci_count'], 'nognd' => $u['nognd_count'], 'stuck' => $u['stuck_count'],
		'rb7' => (int)$w['rb7'],
	);
}
usort($health, function($a, $b){ return strnatcasecmp($a['name'], $b['name']); });

?>
<div class="sec diag-top">
<h3>Diagnostics</h3>
<p class="sm">Faults, thermal events, and WiFi health for each unit. Energy history: <a href="sessions.php">Session Reports</a>.</p>
<form class="flt card" method="get" id="diagfilt">
	<select name="unit"><option value="0">All units</option>
	<?php foreach ($units as $u) echo '<option value="' . (int)$u['unit_id'] . '"' . ($unit == $u['unit_id'] ? ' selected' : '') . '>' . h($u['unit_name']) . '</option>'; ?>
	</select><br>
	<input type="date" name="from" value="<?=h($from)?>"> to <input type="date" name="to" value="<?=h($to)?>">
	<button type="submit">Show</button>
</form>
<p class="sm note">Recent logs use this date range (default last 90 days). Monthly temperatures below are all-time.</p>
</div>

<div class="sec"><h4>Unit health</h4>
<p class="sm">Lifetime fault counts from each charger, and WiFi module restarts in the last 7 days (red when <?= (int)$WIFI_FLAG_7D ?> or more).</p>
<div class="card uh">
<?php if (!$health) echo '<p class="sm">No units in scope.</p>';
else {
	$fc = function($v){ return ($v === null || $v === '') ? null : (int)$v; };
		echo '<div class="uh-h"><span>Unit</span><span>GFCI</span><span>No ground</span><span>Stuck</span><span>WiFi restarts (7d)</span></div>';
	foreach ($health as $row) {
		$cell = function($v, $lab, $extra = '') use ($fc) {
			$n = $fc($v);
			if ($n === null) return '<span class="uh-c uh-z' . $extra . '" data-l="' . h($lab) . '">' . "\u{2014}" . '</span>';
			$cls = $n > 0 ? 'uh-c uh-nz' : 'uh-c uh-z';
			if ($extra) $cls .= $extra;
			return '<span class="' . $cls . '" data-l="' . h($lab) . '">' . $n . '</span>';
		};
		$rb = (int)$row['rb7'];
		/* WiFi: red only at/above threshold; otherwise normal (uh-w) or zero-grey. */
		$wcls = ($rb >= $WIFI_FLAG_7D) ? 'uh-c uh-nz' : ($rb > 0 ? 'uh-c uh-w' : 'uh-c uh-z');
		$wifi = '<span class="' . $wcls . '" data-l="WiFi restarts 7d">' . $rb . '</span>';
		echo '<div class="uh-r"><span class="uh-n" title="' . h($row['name']) . '">' . h($row['name']) . '</span>'
			. $cell($row['gfci'], 'GFCI') . $cell($row['nognd'], 'No ground') . $cell($row['stuck'], 'Stuck')
			. $wifi . '</div>';
	}
} ?>
</div>
</div>

<div class="sec"><h4>Fault &amp; thermal events</h4>
<?php if (!$faults) echo '<p class="sm">None logged in this range.</p>'; ?>
<?php foreach ($faults as $e) { ?>
	<div class="ev <?=h($e['kind'])?>"><b><?=fdt($e['ts'])?></b> <?=h($e['unit_name'])?>: <?=h($e['detail'])?>
	<br><span class="sm"><?=$e['temp_c'] !== null ? temp_txt($e['temp_c']) : ''?><?=$e['session_id'] ? ' &middot; <a href="sessions.php?id=' . (int)$e['session_id'] . '">session</a>' : ' &middot; not in a session'?></span></div>
<?php } ?></div>

<div class="sec" id="monthly"><h4>Monthly temperatures &amp; thermal</h4>
<p class="sm">Per month: thermal throttling, over-temperature faults, WiFi drop-offs, temperature (<?=temp_sym()?>, hottest sensor) avg / max while idle (ambient) and while charging, and WiFi signal where the unit reports it (v5 only). Unit filter above applies; dates do not.</p>
<?php if (!$rows) echo '<p class="sm">No data yet.</p>'; ?>
<?php foreach ($rows as $m => $us_) { ksort($us_); ?>
	<div class="card" style="background:#fff"><b><?=h(date('F Y', strtotime($m . '-01')))?></b>
	<?php foreach ($us_ as $uid => $x) { $th = (int)($x['thermal'] ?? 0); $ot = (int)($x['overtemp'] ?? 0); $dr = (int)($x['drops'] ?? 0); ?>
		<div class="mo-row"><b><?=h($names[$uid] ?? "unit $uid")?></b>
			<span class="r"><?=$th ? "<span class='tag f'>$th thermal</span>" : '<span class="tag">0 thermal</span>'?><?=$ot ? "<span class='tag f'>$ot over-temp</span>" : ''?><?=$dr ? "<span class='tag'>$dr drop-off" . ($dr == 1 ? '' : 's') . "</span>" : ''?><?=!empty($x['reboots']) ? "<span class='tag'>" . (int)$x['reboots'] . " reboot" . ($x['reboots'] == 1 ? '' : 's') . "</span>" : ''?></span><br>
			<span class="sm">idle <?=$t1($x['idle_avg'] ?? null)?> / <?=$t1($x['idle_max'] ?? null)?> &middot; charging <?=$t1($x['chg_avg'] ?? null)?> / <?=$t1($x['chg_max'] ?? null)?><?=isset($x['rssi_avg']) && $x['rssi_avg'] !== null ? ' &middot; WiFi avg ' . round($x['rssi_avg']) . ' dBm (worst ' . (int)$x['rssi_min'] . ')' : ''?></span>
		</div>
	<?php } ?></div>
<?php } ?>
<p class="sm">avg / max <?=temp_sym()?>. Throttling starts at <?=temp_txt(SESS_THERMAL_C_PAGE)?> ambient.</p></div>

<?=oev_home_footer()?>
</div></body></html>
