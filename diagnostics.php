<?php
/******************************************************************************
 * diagnostics.php — faults, thermal, WiFi / drop-offs, monthly health,
 * and per-unit reporting (collector freshness). View auth (not public).
 * Owns the monthly thermal/WiFi health view (sessions redirects here).
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
function unit_short($n){
	static $pre = null;
	if ($pre === null) {
		$pre = '';
		try {
			$ns = oev_db()->query('SELECT name FROM evses')->fetchAll(PDO::FETCH_COLUMN);
			if (count($ns) > 1 && preg_match('/^([^:]{1,40}:\s*)\S/', (string)$ns[0], $m)) { $pre = $m[1]; foreach ($ns as $x) if (strpos((string)$x, $pre) !== 0) { $pre = ''; break; } }
		} catch (Exception $e) { $pre = ''; }
	}
	$n = (string)$n;
	return ($pre !== '' && strpos($n, $pre) === 0) ? substr($n, strlen($pre)) : $n;
}

$units = $db->query("SELECT * FROM unit_state WHERE 1$SC ORDER BY unit_name")->fetchAll();
define('SESS_THERMAL_C_PAGE', 65);

$unit = isset($_GET['unit']) && ctype_digit((string)$_GET['unit']) ? (int)$_GET['unit'] : 0;
$from = (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])) ? $_GET['from'] : date('Y-m-d', strtotime('-90 days'));
$to   = (isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']))   ? $_GET['to']   : date('Y-m-d');
$f = strtotime($from . ' 00:00:00'); $t = strtotime($to . ' 23:59:59');
$uw = ($unit ? " AND unit_id = $unit" : '') . $SC;

$faults = $db->query("SELECT * FROM events WHERE kind IN ('fault','fault_count','thermal') AND ts BETWEEN $f AND $t$uw ORDER BY ts DESC LIMIT 40")->fetchAll();
$drops = $db->query("SELECT * FROM events WHERE kind IN ('reach', 'reboot') AND ts BETWEEN $f AND $t$uw ORDER BY ts DESC LIMIT 40")->fetchAll();
$dropcount = $db->query("SELECT unit_name, sum(kind = 'reach') n, sum(kind = 'reboot') rb,
	(SELECT round(avg(srssi)) FROM samples x WHERE x.unit_id = events.unit_id AND x.ts BETWEEN $f AND $t) rssi FROM events WHERE ((kind = 'reach' AND reason IN ('unreachable', 'push stopped')) OR kind = 'reboot') AND ts BETWEEN $f AND $t$uw GROUP BY unit_id ORDER BY unit_name")->fetchAll();

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
$names = array(); foreach ($units as $u) $names[$u['unit_id']] = unit_short($u['unit_name']);
$t1 = function($v){ return temp_num($v); };
?>
<div class="sec"><h3 style="margin:4px 0 6px 0;color:#333">Diagnostics</h3>
<p class="sm">Faults, thermal events, WiFi drop-offs, monthly health, and unit reporting. Energy history stays on <a href="sessions.php">Session Reports</a>. Collector / system health for admins is also under Settings → System status.</p></div>

<form class="flt card" method="get" id="diagfilt">
	<select name="unit"><option value="0">All units</option>
	<?php foreach ($units as $u) echo '<option value="' . (int)$u['unit_id'] . '"' . ($unit == $u['unit_id'] ? ' selected' : '') . '>' . h(unit_short($u['unit_name'])) . '</option>'; ?>
	</select><br>
	<input type="date" name="from" value="<?=h($from)?>"> to <input type="date" name="to" value="<?=h($to)?>">
	<button type="submit">Show</button>
</form>
<p class="sec sm">Recent logs use the date range above (default last 90 days). Monthly temperatures below are all-time.</p>

<div class="sec"><h4>Faults &amp; thermal</h4>
<?php if (!$faults) echo '<p class="sm">None logged in this range.</p>'; ?>
<?php foreach ($faults as $e) { ?>
	<div class="ev <?=h($e['kind'])?>"><b><?=fdt($e['ts'])?></b> <?=h(unit_short($e['unit_name']))?>: <?=h($e['detail'])?>
	<br><span class="sm"><?=$e['temp_c'] !== null ? temp_txt($e['temp_c']) : ''?><?=$e['session_id'] ? ' &middot; <a href="sessions.php?id=' . (int)$e['session_id'] . '">session</a>' : ' &middot; not in a session'?></span></div>
<?php } ?></div>

<div class="sec"><h4>WiFi drop-offs &amp; reboots</h4>
<?php if (!$drops) echo '<p class="sm">None logged in this range.</p>'; ?>
<?php foreach ($dropcount as $d) { ?>
	<div class="kv"><span><?=h(unit_short($d['unit_name']))?></span><span><?=(int)$d['n']?> drop-off<?=$d['n'] == 1 ? '' : 's'?> &middot; <?=(int)$d['rb']?> reboot<?=$d['rb'] == 1 ? '' : 's'?><?=$d['rssi'] !== null ? ' &middot; WiFi avg ' . (int)$d['rssi'] . ' dBm' : ''?></span></div>
<?php } ?>
<?php foreach ($drops as $e) { ?>
	<div class="ev reach"><b><?=fdt($e['ts'])?></b> <?=h(unit_short($e['unit_name']))?>: <?=h($e['detail'])?><?=$e['srssi'] !== null ? ' <span class="sm">(WiFi ' . (int)$e['srssi'] . ' dBm)</span>' : ''?></div>
<?php } ?></div>

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

<div class="sec"><h4>Unit reporting</h4>
<p class="sm">When each unit last delivered a good reading (collector or push). Admins also see site-wide collector health under Settings → System status.</p>
<?php foreach ($units as $u) { $age = $u['last_ok_at'] ? time() - $u['last_ok_at'] : null; $pa = $u['last_push_at'] ? time() - $u['last_push_at'] : null; ?>
	<div class="kv"<?=$u['srssi'] !== null ? ' title="WiFi ' . (int)$u['srssi'] . ' dBm"' : ''?>><span><?=h(unit_short($u['unit_name']))?></span><span<?=($age === null || $age > 300) ? ' class="err"' : ''?>><?=$age === null ? 'never' : ($age < 120 ? 'ok' : 'last ' . sess_fmt_dur($age) . ' ago')?><?=$pa !== null ? ' &middot; push ' . ($pa < 90 ? $pa . 's' : sess_fmt_dur($pa)) : ' &middot; polled'?><?=$u['temp_c'] !== null ? ' &middot; ' . temp_txt($u['temp_c']) : ''?><?=$u['srssi'] !== null ? ' &middot; ' . (int)$u['srssi'] . 'dBm' : ''?><?=$u['charging'] ? ' &middot; charging' : ($u['connected'] ? ' &middot; plugged in' : '')?></span></div>
<?php } ?>
<p class="sm" style="margin-top:4px">push = seconds since the unit's last push; dBm = WiFi signal (v5 units only).</p></div>

<?=oev_home_footer()?>
</div></body></html>
