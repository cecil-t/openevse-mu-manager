<?php
/******************************************************************************
 * sessions.php - charging session reports (reads sessions.db, written by the
 * collector and the push receiver). ?id=N shows one session (with graph);
 * ?view=months = monthly temperature / thermal / drop-off view per unit.
 * Filters: ?unit=ID&from=YYYY-MM-DD&to=YYYY-MM-DD
 ******************************************************************************/
require_once __DIR__ . '/auth_lib.php'; oev_require('view');   // login gate (auth_lib.php)
$__vis = oev_visible_unit_ids();   // null = all units; otherwise only these (user's load groups)
$SC = ($__vis === null) ? '' : ' AND unit_id IN (' . ($__vis ? implode(',', $__vis) : '0') . ')';
require __DIR__ . '/sessions_lib.php';
require_once __DIR__ . '/evse_prefs.php';   // TEMP_UNIT (display only)
require_once __DIR__ . '/util_lib.php';   // h(), ago(), oev_sess_ro()
$wq = '';
$title = 'Sessions';
include __DIR__ . '/_head.php';
?>
<style>
.card{background:#eee;border:1px solid #ccc;border-radius:8px;padding:8px 10px;margin:8px 0;color:#555;font-size:15px}
.card b{color:#333}.card .r{float:right}.card .sm{font-size:13px;color:#777}
.card a.blk{display:block;text-decoration:none;color:inherit}
.tag{display:inline-block;font-size:11px;padding:1px 5px;border-radius:4px;background:#ddd;color:#444;margin:2px 2px 0 0}
.tag.f{background:#f99;color:#600}.tag.o{background:#9cf;color:#035}
h4{margin:14px 0 4px 0;color:#333}
.kv{display:flex;justify-content:space-between;border-bottom:1px dotted #ccc;padding:2px 0}
.kv span:first-child{color:#888}
form.flt select,form.flt input{font-size:15px;margin:2px 0;max-width:100%}
.ev{border-left:3px solid #aaa;padding:2px 0 2px 7px;margin:5px 0;font-size:14px}
.ev.fault,.ev.fault_count{border-color:#e33}.ev.thermal{border-color:#f90}.ev.pilot{border-color:#07d}.ev.amps{border-color:#0a7}.ev.limit{border-color:#82d}.ev.reach,.ev.reboot{border-color:#888;border-left-style:dashed}
svg text{font-family:Arial,sans-serif}
.sec{background:rgba(246,246,246,.95);border:1px solid #ccc;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.18);padding:6px 10px;margin:10px 0;color:#444}
.sec>h4:first-child{margin-top:2px}.sec>p{margin:4px 0}.sec .card{background:#fff;margin:6px 0}
/* style.css boxes every 'a span' (old button look); undo that inside the session cards */
.card a.blk span{display:inline;background:none;border:0;padding:0;margin:0;width:auto;text-align:inherit;color:inherit}
.card a.blk span.r{float:right}.card a.blk span.sm{font-size:13px;color:#777;white-space:nowrap}
.card a.blk span.tag{display:inline-block;font-size:11px;padding:1px 5px;margin:2px 2px 0 0;border-radius:4px;background:#ddd;color:#444}
.card a.blk span.tag.f{background:#f99;color:#600}.card a.blk span.tag.o{background:#9cf;color:#035}
.kv{gap:10px}.kv span{white-space:nowrap}.kv span:first-child{flex:0 0 auto}.kv span:last-child{overflow:hidden;text-overflow:ellipsis;text-align:right;min-width:0}
</style>
<?php
if (!is_file(SESS_DB_PATH)) { echo '<div class="sec">No session data yet. The collector has not run.</div></div></body></html>'; exit; }
try { $db = sess_db(true); } catch (Throwable $e) { echo '<div class="sec">Session database unavailable.</div></div></body></html>'; exit; }

function fdt($ts, $fmt = 'D m/d g:ia'){ return $ts ? date($fmt, (int)$ts) : '-'; }
// a time within a session: just the time on the plug-in day, else with the weekday (and date if a week or more later)
function fdd($ts, $ref){
	if (!$ts) return '-';
	$ts = (int)$ts; $ref = (int)$ref;
	if (!$ref || date('Ymd', $ts) == date('Ymd', $ref)) return date('g:i a', $ts);
	return date(($ts - $ref) < 6 * 86400 ? 'D g:i a' : 'D n/j g:i a', $ts);
}
// in-progress session: kwh comes from the lifetime counter and stays 0 until the session ends,
// so show the unit's session meter (display only, nothing is written)
function sess_live(&$s){ if (!empty($s['open']) && (float)$s['kwh'] <= 0 && $s['kwh_meter'] !== null && (float)$s['kwh_meter'] > 0) { $s['kwh'] = (float)$s['kwh_meter']; $s['kwh_live'] = 1; } }
function fkwh($v){ return $v === null ? '-' : number_format((float)$v, 2) . ' kWh'; }
// shorter unit names in the reports: a "Place: " prefix that every unit's name shares is left out (none if the names differ)
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
function limit_text($s){
	if (!$s['limit_type']) return '';
	$v = (float)$s['limit_value'];
	if ($s['limit_type'] == 'time') return 'time limit ' . ($v >= 60 ? round($v / 60, 2) . ' h' : $v . ' min');
	if ($s['limit_type'] == 'energy') return 'energy limit ' . $v . ' kWh';
	return $s['limit_type'] . ' limit ' . $v;
}

// ---------- simple inline SVG charts (no external libraries)
// Bar chart: title above the plot, round y ticks with headroom, bars capped in width
// and centred in their slot, x labels thinned so they never collide. A zero value
// draws no bar (it is a real zero, not missing data).
function svg_nice_step($raw){
	if ($raw <= 0) return 1;
	$p = pow(10, floor(log10($raw))); $f = $raw / $p;
	foreach (array(1, 2, 2.5, 5, 10) as $m) if ($f <= $m) return $m * $p;
	return 10 * $p;
}
function svg_bars($labels, $values, $unit){
	$n = count($values); if (!$n) return '';
	$W = 360; $H = 160; $pl = 34; $pr = 8; $pt = 22; $pb = 20;
	$pw = $W - $pl - $pr; $ph = $H - $pt - $pb;
	$vmax = max($values);
	$step = svg_nice_step(max(0.1, $vmax) / 3);
	$ymax = $step * max(1, ceil($vmax * 1.08 / $step));   // headroom above the tallest bar
	if ($ymax <= $vmax) $ymax += $step;
	$dec = $step < 1 ? ($step < 0.1 ? 2 : 1) : (fmod($step, 1) ? 1 : 0);
	$o = "<svg viewBox='0 0 $W $H' width='100%' role='img' style='display:block;background:#fff;border:1px solid #ccc;border-radius:6px'>";
	$o .= "<text x='$pl' y='13' font-size='11' fill='#666'>" . h($unit) . "</text>";
	for ($v = 0; $v <= $ymax + $step / 2; $v += $step) {
		$y = round($pt + $ph * (1 - $v / $ymax), 1);
		$o .= "<line x1='$pl' x2='" . ($W - $pr) . "' y1='$y' y2='$y' stroke='" . ($v ? '#eee' : '#ccc') . "'/><text x='" . ($pl - 4) . "' y='" . ($y + 3.5) . "' font-size='10' text-anchor='end' fill='#888'>" . number_format($v, $dec) . "</text>";
	}
	$slot = $pw / $n; $bw = min(36, max(1, $slot * 0.7));
	$lw = 0; foreach ($labels as $l) $lw = max($lw, strlen($l) * 5.6 + 8);   // ~px per label at 10px
	$every = max(1, (int)ceil($lw / $slot));
	foreach ($values as $i => $v) {
		$cx = $pl + $slot * ($i + 0.5);
		$hgt = $ph * $v / $ymax; $y = $pt + $ph - $hgt;
		$tip = '<title>' . h($labels[$i]) . ': ' . number_format($v, 1) . ' kWh</title>';
		if ($v > 0) $o .= "<rect x='" . round($cx - $bw / 2, 1) . "' y='" . round($y, 1) . "' width='" . round($bw, 1) . "' height='" . round($hgt, 1) . "' fill='teal'>$tip</rect>";
		if ($i % $every == 0) $o .= "<text x='" . round($cx, 1) . "' y='" . ($H - 6) . "' font-size='10' text-anchor='middle' fill='#666'>" . h($labels[$i]) . "</text>";
	}
	return $o . "</svg>";
}
// Session graph: amps (left axis, kW = A x V on the inner labels), pilot, and each
// fitted temperature sensor (right axis). Works on any sample density: push rows
// (~30 s), poll rows (60 s / 5 min) and thinned 10-minute rows (res = 600). Lines
// break where samples are further apart than the data's own resolution allows.
function svg_session($samples, $segments, $t0, $t1, $volts){
	if (count($samples) < 2 || $t1 <= $t0) return '<p class="sm">Not enough samples for a graph yet.</p>';
	$W = 360; $H = 190; $pl = 30; $pr = 30; $pt = 10; $pb = 22;
	// more points than pixels: bucket (avg amps, last pilot, max temps)
	$maxpts = 720;
	if (count($samples) > $maxpts) {
		$bw = ($t1 - $t0) / $maxpts; $b = array();
		foreach ($samples as $x) {
			$k = (int)floor(($x['ts'] - $t0) / $bw);
			if (!isset($b[$k])) { $b[$k] = $x; $b[$k]['n'] = 0; $b[$k]['asum'] = 0; }
			$c = &$b[$k];
			if ($x['amps'] !== null) { $c['asum'] += $x['amps']; $c['n']++; }
			foreach (array('temp_c', 'temp1_c', 'temp2_c', 'temp3_c') as $t) if ($x[$t] !== null && ($c[$t] === null || $x[$t] > $c[$t])) $c[$t] = $x[$t];
			if ($x['pilot'] !== null) $c['pilot'] = $x['pilot'];
			$c['res'] = max((int)$c['res'], (int)$x['res'], (int)ceil($bw));
			unset($c);
		}
		foreach ($b as &$c) { if ($c['n']) $c['amps'] = $c['asum'] / $c['n']; } unset($c);
		$samples = array_values($b);
	}
	// temperatures to the display unit (stored as C)
	foreach ($samples as &$x) foreach (array('temp_c', 'temp1_c', 'temp2_c', 'temp3_c') as $t) if (isset($x[$t]) && $x[$t] !== null) $x[$t] = temp_conv($x[$t]);
	unset($x);
	// which temperature series exist (per-sensor columns are new; old rows only have temp_c)
	$series = array();
	foreach (array('temp1_c'=>'temp1', 'temp2_c'=>'temp2', 'temp3_c'=>'temp3') as $k => $lbl) foreach ($samples as $x) if ($x[$k] !== null) { $series[$k] = $lbl; break; }
	if (!$series) $series = array('temp_c'=>'temp');
	$amax = 6; $tmin = 100; $tmax = -50;
	foreach ($samples as $x) {
		$amax = max($amax, (float)$x['amps'], (float)$x['pilot']);
		foreach ($series as $k => $l) if ($x[$k] !== null) { $tmin = min($tmin, (float)$x[$k]); $tmax = max($tmax, (float)$x[$k]); }
	}
	$amax = ceil($amax / 8) * 8; if ($tmax < $tmin) { $tmin = 0; $tmax = 50; } $tmin = floor($tmin / 5) * 5; $tmax = max($tmin + 10, ceil($tmax / 5) * 5);
	$X = function($ts) use ($t0, $t1, $W, $pl, $pr){ return round($pl + ($W - $pl - $pr) * ($ts - $t0) / ($t1 - $t0), 1); };
	$YA = function($a) use ($amax, $H, $pt, $pb){ return round($H - $pb - ($H - $pt - $pb) * $a / $amax, 1); };
	$YT = function($c) use ($tmin, $tmax, $H, $pt, $pb){ return round($H - $pb - ($H - $pt - $pb) * ($c - $tmin) / ($tmax - $tmin), 1); };
	$o = "<svg viewBox='0 0 $W $H' width='100%' role='img' style='background:#fff;border:1px solid #ccc;border-radius:6px'>";
	foreach ($segments as $g) { $x1 = $X(max($t0, $g['start_at'])); $x2 = $X(min($t1, $g['stop_at'] ?: $t1)); $o .= "<rect x='$x1' y='$pt' width='" . max(1, $x2 - $x1) . "' height='" . ($H - $pt - $pb) . "' fill='#e6f3ff'/>"; }
	for ($g = 0; $g <= 4; $g++) {
		$a = $amax * $g / 4; $y = $YA($a);
		$o .= "<line x1='$pl' x2='" . ($W - $pr) . "' y1='$y' y2='$y' stroke='#eee'/><text x='" . ($pl - 3) . "' y='" . ($y + 4) . "' font-size='10' text-anchor='end' fill='#07d'>" . round($a) . "</text><text x='" . ($W - $pr + 3) . "' y='" . ($y + 4) . "' font-size='10' fill='#e80'>" . round($tmin + ($tmax - $tmin) * $g / 4) . "</text>";
		if ($g) $o .= "<text x='" . ($pl + 2) . "' y='" . ($y + 10) . "' font-size='8' fill='#9ab'>" . number_format($a * $volts / 1000, 1) . "kW</text>";
	}
	$colors = array('temp_c'=>'#e80', 'temp1_c'=>'#e80', 'temp2_c'=>'#c33', 'temp3_c'=>'#963');
	$paths = array('amps'=>'', 'pilot'=>'') + array_fill_keys(array_keys($series), '');
	$prev = null;
	foreach ($samples as $x) {
		$xx = $X($x['ts']);
		// gap: further apart than 3x the coarser row's resolution (min 3 min)
		$brk = $prev && ($x['ts'] - $prev['ts']) > max(180, 3 * max((int)$x['res'], (int)$prev['res']));
		if ($x['amps'] !== null) $paths['amps'] .= (($paths['amps'] === '' || $brk) ? 'M' : 'L') . "$xx," . $YA((float)$x['amps']) . ' ';
		if ($x['pilot'] !== null) { $y = $YA((float)$x['pilot']); $paths['pilot'] .= (($paths['pilot'] === '' || $brk) ? "M$xx,$y " : "H$xx V$y "); }
		foreach ($series as $k => $l) if ($x[$k] !== null) $paths[$k] .= (($paths[$k] === '' || $brk) ? 'M' : 'L') . "$xx," . $YT((float)$x[$k]) . ' ';
		$prev = $x;
	}
	$o .= "<path d='{$paths['pilot']}' fill='none' stroke='#999' stroke-dasharray='4 3' stroke-width='1.2'/>";
	foreach ($series as $k => $l) $o .= "<path d='{$paths[$k]}' fill='none' stroke='{$colors[$k]}' stroke-width='1.1'/>";
	$o .= "<path d='{$paths['amps']}' fill='none' stroke='#07d' stroke-width='1.8'/>";
	$dur = $t1 - $t0;
	// day boundaries: a dashed line at each local midnight, labelled with the new day (labels thinned if crowded)
	$mids = array();
	for ($d = (new DateTime('@' . (int)$t0))->setTimezone(new DateTimeZone(date_default_timezone_get()))->setTime(0, 0)->modify('+1 day'); $d->getTimestamp() < $t1; $d->modify('+1 day')) $mids[] = $d->getTimestamp();
	foreach ($mids as $m) { $mx = $X($m); $o .= "<line x1='$mx' x2='$mx' y1='$pt' y2='" . ($H - $pb) . "' stroke='#aaa' stroke-dasharray='2 3'/>"; }
	// day names centred in each day's span (only if the span is wide enough)
	if ($mids) { $edges = array_merge(array($t0), $mids, array($t1));
		for ($k = 0; $k + 1 < count($edges); $k++) { $xa = $X($edges[$k]); $xb = $X($edges[$k + 1]);
			if ($xb - $xa >= 30) $o .= "<text x='" . round(($xa + $xb) / 2, 1) . "' y='" . ($pt + 9) . "' font-size='9' text-anchor='middle' fill='#888'>" . date(count($mids) > 6 ? 'n/j' : 'D', (int)$edges[$k] + 1) . "</text>"; } }
	// time ticks: 5 across; clock time if under a day (day lines give the date), else weekday + hour
	for ($k = 0; $k <= 4; $k++) { $ts = $t0 + $dur * $k / 4; $o .= "<text x='" . $X($ts) . "' y='" . ($H - 7) . "' font-size='10' text-anchor='" . ($k == 0 ? 'start' : ($k == 4 ? 'end' : 'middle')) . "' fill='#666'>" . date($dur > 86400 ? ($dur > 6 * 86400 ? 'n/j' : 'D ga') : 'g:i', (int)$ts) . "</text>"; }
	// line 1: amps/kW + pilot; line 2: temperature series + charging. Items never break inside.
	$leg = "<span style='color:#07d;white-space:nowrap'>&#9644; amps / kW (left)</span> &nbsp;<span style='color:#999;white-space:nowrap'>- - pilot</span><br>";
	$l2 = array();
	foreach ($series as $k => $l) $l2[] = "<span style='color:{$colors[$k]};white-space:nowrap'>&#9644; $l " . temp_sym() . "</span>";
	$l2[] = "<span style='background:#e6f3ff;white-space:nowrap'>&nbsp;charging&nbsp;</span>";
	return $o . "</svg><div class='sm'>$leg" . implode(' &nbsp;', $l2) . "</div>";
}
function ftemp($c){ return temp_txt($c); }

$units = $db->query("SELECT * FROM unit_state WHERE 1$SC ORDER BY unit_name")->fetchAll();
define('SESS_THERMAL_C_PAGE', 65);

/* =================== SESSION DETAIL =================== */
if (isset($_GET['id'])) {
	$id = (int)$_GET['id'];
	$s = $db->query("SELECT * FROM sessions WHERE id = $id$SC")->fetch();
	if ($s) sess_live($s);
	if (!$s) { echo '<div class="sec">Session not found. <a href="sessions.php">Back</a></div></div></body></html>'; exit; }
	$segs = $db->query("SELECT * FROM segments WHERE session_id = $id ORDER BY start_at")->fetchAll();
	$evs = $db->query("SELECT * FROM events WHERE session_id = $id ORDER BY ts, id")->fetchAll();
	$smp = $db->query("SELECT ts, amps, pilot, volts, temp_c, temp1_c, temp2_c, temp3_c, res FROM samples WHERE session_id = $id ORDER BY ts")->fetchAll();
	$t0 = (int)$s['plug_in_at']; $t1 = (int)($s['unplug_at'] ?: time());
	$v = $s['volts'] ?: EVSE_VOLTS_L2;
	$skw = sess_session_kw($s);
	?>
	<div class="sec"><a href="sessions.php">&larr; All sessions</a></div>
	<div class="card">
		<b><?=h(unit_short($s['unit_name']))?></b> <span class="r"><b><?=fkwh($s['kwh'])?></b></span><br>
		<span class="sm"><?=fdt($s['plug_in_at'], 'l, M j Y')?></span>
		<?php if ($s['open']) echo ' <span class="tag o">in progress</span>'; ?>
		<?php if ($s['partial']) echo ' <span class="tag">partial</span>'; ?>
		<?php if ($s['faults']) echo ' <span class="tag f">' . (int)$s['faults'] . ' fault/thermal</span>'; ?>
	</div>
	<div class="card">
		<?php
		// which real reading each time comes from (push or poll), shown as a small tag
		$srcq = $db->prepare("SELECT src FROM samples WHERE unit_id = ? AND ts = ? AND src IN ('push', 'poll') ORDER BY src = 'poll' DESC LIMIT 1");
		$tag = function($ts) use ($srcq, $s){ if (!$ts) return ''; $srcq->execute(array((int)$s['unit_id'], (int)$ts)); $v = $srcq->fetchColumn(); $srcq->closeCursor(); return $v ? " <span class='sm' style='color:#999'>$v</span>" : ''; };
		// an earlier push that flagged the change but could not date it (2.8.2 state 0)
		$fps = !empty($s['first_push_at']) && (int)$s['first_push_at'] < (int)$s['plug_in_at']; ?><div class="kv"><span>Plugged in</span><span><?=fdt($s['plug_in_at'], $fps ? 'g:i:s a' : 'g:i a') . $tag($s['plug_in_at'])?></span></div>
		<?php if ($fps) { ?><div class="kv"><span>First push notice</span><span><?=date('g:i:s a', (int)$s['first_push_at'])?></span></div><?php } ?>
		<div class="kv"><span>First charge start</span><span><?=fdd($s['charge_start_at'], $s['plug_in_at']) . $tag($s['charge_start_at'])?></span></div>
		<div class="kv"><span>Last charge stop</span><span><?php $chg_open = $s['open'] && $db->query("SELECT count(*) FROM segments WHERE session_id = " . (int)$s['id'] . " AND open = 1")->fetchColumn(); ?><?=($chg_open || !$s['charge_end_at']) ? "\u{2014}" : fdd($s['charge_end_at'], $s['plug_in_at']) . $tag($s['charge_end_at'])?></span></div>
		<div class="kv"><span>Unplugged</span><span><?=$s['open'] ? 'still plugged in' : fdd($s['unplug_at'], $s['plug_in_at']) . $tag($s['unplug_at']) . ($s['unplug_at'] - $s['plug_in_at'] >= 12 * 3600 ? ' (' . sess_fmt_dur($s['unplug_at'] - $s['plug_in_at']) . ')' : '')?></span></div>
		<?php if (!empty($s['unplug_push_at']) && (int)$s['unplug_push_at'] < (int)$s['unplug_at']) { ?><div class="kv"><span>Unplug push notice</span><span><?=fdd($s['unplug_push_at'], $s['plug_in_at'])?></span></div><?php } ?>
		<div class="kv"><span>Charging time</span><span><?=sess_fmt_dur($s['charge_seconds'])?> (<?=(int)$s['segments']?> segment<?=$s['segments'] == 1 ? '' : 's'?>)</span></div>
		<div class="kv"><span>Energy</span><span><?=fkwh($s['kwh'])?><?=!empty($s['kwh_live']) ? ' <small>(so far, unit meter)</small>' : ($s['kwh_meter'] !== null ? ' <small>(unit meter ' . number_format($s['kwh_meter'], 2) . ')</small>' : '')?></span></div>
		<div class="kv"><span>Avg kW charging</span><span><?=$skw['avg'] !== null ? number_format($skw['avg'], 2) : '-'?></span></div>
		<div class="kv"><span>Peak</span><span><?=$s['peak_amps'] !== null ? round($s['peak_amps'], 1) . ' A / ' . number_format($s['peak_amps'] * $v / 1000, 2) . ' kW' : '-'?></span></div>
		<div class="kv"><span>Pilot start / min / max</span><span><?=h($s['pilot_start'] ?: '-')?> / <?=h($s['pilot_min'] ?: '-')?> / <?=h($s['pilot_max'] ?: '-')?> A</span></div>
		<div class="kv"><span>Limit</span><span><?=h(limit_text($s) ?: 'none')?></span></div>
		<div class="kv"><span>Schedule</span><span><?=$s['timer_active'] ? 'active' : 'none'?></span></div>
		<div class="kv"><span>Max temp</span><span><?=ftemp($s['max_temp_c'])?></span></div>
		<div class="kv"><span>Avg temp (plugged in)</span><span><?=ftemp($s['avg_temp_c'])?></span></div>
		<?php if ($s['unit_elapsed'] !== null || $s['unit_temp_max_c'] !== null) { ?><div class="kv"><span>Unit's own timer / max temp</span><span><?=$s['unit_elapsed'] !== null ? sess_fmt_dur($s['unit_elapsed']) : '-'?> / <?=ftemp($s['unit_temp_max_c'])?></span></div><?php } ?>
		<div class="kv"><span>End reason</span><span><?=h($s['end_reason'] ?: ($s['open'] ? '-' : 'unknown'))?></span></div>
		<?php if ($s['notes']) { ?><div class="sm" style="margin-top:4px">Notes: <?=h($s['notes'])?></div><?php } ?>
		<div class="sm" style="margin-top:4px">kW = amps &times; <?=oev_v($v . ' V')?>, worked out when shown (the unit's voltage override if set; its own reading if it has a voltage sensor; else the assumed <?=oev_v(EVSE_VOLTS_L1 . ' V')?> for L1 / <?=oev_v(EVSE_VOLTS_L2 . ' V')?> for L2).</div>
	</div>
	<div class="sec"><h4>Graph</h4>
	<?=svg_session($smp, $segs, $t0, $t1, $v)?>
	<div class="sm"><?=count($smp)?> samples<?=$s['pushes'] ? ' &middot; ' . (int)$s['pushes'] . ' pushes' : ''?></div></div>
	<div class="sec"><h4>Charging segments</h4>
	<?php if (!$segs) echo '<p class="sm">No charging in this session.</p>'; ?>
	<?php
	// in-progress segment: its lifetime-based kwh stays 0 until it ends; show the running unit-meter figure
	// (session so-far minus the closed segments), like the headline
	$segdone = 0; foreach ($segs as $g) if (empty($g['open'])) $segdone += (float)$g['kwh'];
	foreach ($segs as &$g) if (!empty($g['open']) && (float)$g['kwh'] <= 0 && !empty($s['kwh_live'])) { $g['kwh'] = max(0, (float)$s['kwh'] - $segdone); $g['kwh_live'] = 1; }
	unset($g);
	foreach ($segs as $i => $g) { ?>
		<div class="card">
			<b>#<?=$i + 1?></b> <?=fdd($g['start_at'], $s['plug_in_at'])?> &ndash; <?=$g['open'] ? 'now' : fdd($g['stop_at'], date('Ymd', (int)$g['stop_at']) == date('Ymd', (int)$g['start_at']) ? $g['start_at'] : $s['plug_in_at'])?>
			<span class="r"><?=fkwh($g['kwh'])?><?=!empty($g['kwh_live']) ? ' <small>so far</small>' : ''?></span><br>
			<span class="sm"><?=sess_fmt_dur($g['seconds'])?> &middot; avg <?=$g['avg_amps'] !== null ? round($g['avg_amps'], 1) : ($g['seconds'] ? round($g['amp_seconds'] / $g['seconds'], 1) : '-')?> A &middot; peak <?=$g['peak_amps'] !== null ? round($g['peak_amps'], 1) : '-'?> A
			<?=$g['max_temp_c'] !== null ? '&middot; temp max ' . temp_num($g['max_temp_c']) . ' / avg ' . temp_num($g['avg_temp_c']) . ' ' . temp_sym() : ''?>
			&middot; stop: <b><?=h($g['open'] ? 'charging' : ($g['stop_reason'] ?: 'unknown'))?></b></span>
		</div>
	<?php } ?></div>
	<div class="sec"><h4>Timeline</h4>
	<?php if (!$evs) echo '<p class="sm">No current changes or faults logged.</p>'; ?>
	<?php foreach ($evs as $e) { ?>
		<div class="ev <?=h($e['kind'])?>"><b><?=fdd($e['ts'], $s['plug_in_at'])?></b> <?=h($e['detail'])?>
		<br><span class="sm"><?=h($e['reason'])?><?=$e['temp_c'] !== null ? ' &middot; ' . temp_txt($e['temp_c']) : ''?><?=$e['srssi'] !== null ? ' &middot; WiFi ' . (int)$e['srssi'] . ' dBm' : ''?></span></div>
	<?php } ?></div>
	</div></body></html>
	<?php
	exit;
}

/* =================== MONTHLY TEMPERATURE / THERMAL =================== */
if (isset($_GET['view']) && $_GET['view'] == 'months') {
	$unit = isset($_GET['unit']) && ctype_digit((string)$_GET['unit']) ? (int)$_GET['unit'] : 0;
	$uw = ($unit ? " AND unit_id = $unit" : '') . $SC;
	$M = "strftime('%Y-%m', ts, 'unixepoch', 'localtime')";
	$rows = array();
	// idle = outside a session (15-min samples); charging = state 3 samples
	foreach ($db->query("SELECT $M m, unit_id,
			avg(CASE WHEN session_id IS NULL THEN temp_c END) idle_avg, max(CASE WHEN session_id IS NULL THEN temp_c END) idle_max,
			avg(CASE WHEN state = 3 THEN temp_c END) chg_avg, max(CASE WHEN state = 3 THEN temp_c END) chg_max,
			avg(srssi) rssi_avg, min(srssi) rssi_min
			FROM samples WHERE (temp_c IS NOT NULL OR srssi IS NOT NULL)$uw GROUP BY m, unit_id") as $x) $rows[$x['m']][$x['unit_id']] = $x;
	// thermal = derate / threshold crossings (not the "back below" restores); over-temp = fault state 10
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
	<div class="sec"><a href="sessions.php">&larr; Sessions</a></div>
	<form class="flt card" method="get"><input type="hidden" name="view" value="months">
		<select name="unit"><option value="0">All units</option>
		<?php foreach ($units as $u) echo '<option value="' . (int)$u['unit_id'] . '"' . ($unit == $u['unit_id'] ? ' selected' : '') . '>' . h(unit_short($u['unit_name'])) . '</option>'; ?>
		</select> <button type="submit">Show</button></form>
	<p class="sec sm">Per month: thermal throttling events, over-temperature faults, WiFi drop-offs, temperature (<?=temp_sym()?>, hottest sensor) avg / max while idle (ambient) and while charging, and WiFi signal where the unit reports it (v5 only).</p>
	<?php if (!$rows) echo '<p class="sec sm">No data yet.</p>'; ?>
	<?php foreach ($rows as $m => $us_) { ksort($us_); ?>
		<div class="sec"><h4><?=h(date('F Y', strtotime($m . '-01')))?></h4>
		<?php foreach ($us_ as $uid => $x) { $th = (int)($x['thermal'] ?? 0); $ot = (int)($x['overtemp'] ?? 0); $dr = (int)($x['drops'] ?? 0); ?>
			<div class="card"><b><?=h($names[$uid] ?? "unit $uid")?></b>
				<span class="r"><?=$th ? "<span class='tag f'>$th thermal</span>" : '<span class="tag">0 thermal</span>'?><?=$ot ? "<span class='tag f'>$ot over-temp</span>" : ''?><?=$dr ? "<span class='tag'>$dr drop-off" . ($dr == 1 ? '' : 's') . "</span>" : ''?><?=!empty($x['reboots']) ? "<span class='tag'>" . (int)$x['reboots'] . " reboot" . ($x['reboots'] == 1 ? '' : 's') . "</span>" : ''?></span><br>
				<span class="sm">idle <?=$t1($x['idle_avg'] ?? null)?> / <?=$t1($x['idle_max'] ?? null)?> &middot; charging <?=$t1($x['chg_avg'] ?? null)?> / <?=$t1($x['chg_max'] ?? null)?><?=isset($x['rssi_avg']) && $x['rssi_avg'] !== null ? ' &middot; WiFi avg ' . round($x['rssi_avg']) . ' dBm (worst ' . (int)$x['rssi_min'] . ')' : ''?></span>
			</div>
		<?php } ?></div>
	<?php } ?>
	<p class="sec sm">avg / max <?=temp_sym()?>. Throttling starts at <?=temp_txt(SESS_THERMAL_C_PAGE)?> ambient.</p>
	</div></body></html>
	<?php
	exit;
}

/* =================== LIST + TOTALS =================== */
$unit = isset($_GET['unit']) && ctype_digit((string)$_GET['unit']) ? (int)$_GET['unit'] : 0;
$from = (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])) ? $_GET['from'] : date('Y-m-d', strtotime('-90 days'));
$to   = (isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']))   ? $_GET['to']   : date('Y-m-d');
$f = strtotime($from . ' 00:00:00'); $t = strtotime($to . ' 23:59:59');
$where = "plug_in_at BETWEEN $f AND $t$SC" . ($unit ? " AND unit_id = $unit" : '');
$sessions = $db->query("SELECT * FROM sessions WHERE $where ORDER BY plug_in_at DESC LIMIT 200")->fetchAll();
foreach ($sessions as &$x) sess_live($x); unset($x);
$byunit = $db->query("SELECT unit_name, count(*) n, sum(CASE WHEN open = 1 AND coalesce(kwh,0) <= 0 THEN coalesce(kwh_meter,0) ELSE coalesce(kwh,0) END) kwh, sum(charge_seconds) sec FROM sessions WHERE $where GROUP BY unit_id ORDER BY kwh DESC")->fetchAll();
$bymonth = $db->query("SELECT strftime('%Y-%m', plug_in_at, 'unixepoch', 'localtime') m, count(*) n, sum(CASE WHEN open = 1 AND coalesce(kwh,0) <= 0 THEN coalesce(kwh_meter,0) ELSE coalesce(kwh,0) END) kwh, sum(charge_seconds) sec FROM sessions WHERE $where GROUP BY m ORDER BY m")->fetchAll();
$days = ($t - $f) / 86400;
if ($days <= 45) {
	$byday = $db->query("SELECT strftime('%Y-%m-%d', plug_in_at, 'unixepoch', 'localtime') d, sum(CASE WHEN open = 1 AND coalesce(kwh,0) <= 0 THEN coalesce(kwh_meter,0) ELSE coalesce(kwh,0) END) kwh FROM sessions WHERE $where GROUP BY d")->fetchAll(PDO::FETCH_KEY_PAIR);
	$labels = array(); $vals = array();
	for ($d = $f; $d <= $t; $d = strtotime('+1 day', $d)) { $k = date('Y-m-d', $d); $labels[] = date('n/j', $d); $vals[] = isset($byday[$k]) ? (float)$byday[$k] : 0; }
	$chart = svg_bars($labels, $vals, 'kWh per day');
} else {
	// every month in the range, including months with no sessions (0 kWh, no bar)
	$mk = array(); foreach ($bymonth as $m) $mk[$m['m']] = (float)$m['kwh'];
	$months = array(); for ($d = strtotime(date('Y-m-01', $f)); $d <= $t; $d = strtotime('+1 month', $d)) $months[] = $d;
	$mfmt = count($months) <= 6 ? 'M Y' : "M 'y";
	$labels = array(); $vals = array();
	foreach ($months as $d) { $labels[] = date($mfmt, $d); $vals[] = $mk[date('Y-m', $d)] ?? 0; }
	$chart = svg_bars($labels, $vals, 'kWh per month');
}
$tot = array('n'=>0, 'kwh'=>0, 'sec'=>0); foreach ($byunit as $b) { $tot['n'] += $b['n']; $tot['kwh'] += $b['kwh']; $tot['sec'] += $b['sec']; }
$faults = $db->query("SELECT * FROM events WHERE kind IN ('fault','fault_count','thermal') AND ts BETWEEN $f AND $t$SC" . ($unit ? " AND unit_id = $unit" : '') . " ORDER BY ts DESC LIMIT 20")->fetchAll();
$drops = $db->query("SELECT * FROM events WHERE kind IN ('reach', 'reboot') AND ts BETWEEN $f AND $t$SC" . ($unit ? " AND unit_id = $unit" : '') . " ORDER BY ts DESC LIMIT 30")->fetchAll();
$dropcount = $db->query("SELECT unit_name, sum(kind = 'reach') n, sum(kind = 'reboot') rb,
	(SELECT round(avg(srssi)) FROM samples x WHERE x.unit_id = events.unit_id AND x.ts BETWEEN $f AND $t) rssi FROM events WHERE ((kind = 'reach' AND reason IN ('unreachable', 'push stopped')) OR kind = 'reboot') AND ts BETWEEN $f AND $t$SC" . ($unit ? " AND unit_id = $unit" : '') . " GROUP BY unit_id ORDER BY unit_name")->fetchAll();
?>
<form class="flt card" method="get">
	<select name="unit"><option value="0">All units</option>
	<?php foreach ($units as $u) echo '<option value="' . (int)$u['unit_id'] . '"' . ($unit == $u['unit_id'] ? ' selected' : '') . '>' . h(unit_short($u['unit_name'])) . '</option>'; ?>
	</select><br>
	<input type="date" name="from" value="<?=h($from)?>"> to <input type="date" name="to" value="<?=h($to)?>">
	<button type="submit">Show</button>
</form>

<div class="card"><b><?=number_format($tot['kwh'], 1)?> kWh</b> in <?=$tot['n']?> session<?=$tot['n'] == 1 ? '' : 's'?> &middot; <?=sess_fmt_dur($tot['sec'])?> charging
	<div class="sm"><?=h(date('M j, Y', $f))?> &ndash; <?=h(date('M j, Y', $t))?></div></div>
<?=array_sum($vals) > 0 ? '<div class="sec">' . $chart . '</div>' : ''?>

<div class="sec"><h4>By unit</h4>
<?php if (!$byunit) echo '<p class="sm">No sessions in this range yet.</p>'; ?>
<?php foreach ($byunit as $b) { ?>
	<div class="kv"><span><?=h(unit_short($b['unit_name']))?></span><span><b><?=number_format($b['kwh'], 1)?></b> kWh &middot; <?=$b['n']?> &middot; <?=sess_fmt_dur($b['sec'])?></span></div>
<?php } ?></div>
<div class="sec"><h4>By month</h4>
<?php foreach (array_reverse($bymonth) as $m) { ?>
	<div class="kv"><span><?=h(date('M Y', strtotime($m['m'] . '-01')))?></span><span><b><?=number_format($m['kwh'], 1)?></b> kWh &middot; <?=$m['n']?> &middot; <?=sess_fmt_dur($m['sec'])?></span></div>
<?php } ?></div>

<div class="sec"><h4>Sessions</h4>
<?php if (!$sessions) echo '<p class="sm">None yet. Sessions show up after a car is plugged in.</p>'; ?>
<?php foreach ($sessions as $s) { ?>
	<div class="card"><a class="blk" href="sessions.php?id=<?=(int)$s['id']?>">
		<b><?=h(unit_short($s['unit_name']))?></b> <span class="r"><b><?=fkwh($s['kwh'])?></b></span><br>
		<?=fdt($s['plug_in_at'])?> &ndash; <?=$s['open'] ? 'now' : fdt($s['unplug_at'], date('Ymd', (int)$s['unplug_at']) == date('Ymd', (int)$s['plug_in_at']) ? 'g:ia' : 'D g:ia')?><br>
		<span class="sm"><?=sess_fmt_dur($s['charge_seconds'])?> charging<?php $skw = sess_session_kw($s); ?><?=$skw['avg'] !== null ? ' &middot; avg ' . number_format($skw['avg'], 1) . ' kW' : ''?><?=$skw['peak'] !== null ? ' &middot; peak ' . number_format($skw['peak'], 1) . ' kW' : ''?></span><br>
		<?php if ($s['open']) echo '<span class="tag o">in progress</span>'; ?>
		<?php if ($s['end_reason']) echo '<span class="tag">' . h($s['end_reason']) . '</span>'; ?>
		<?php if ($s['segments'] > 1) echo '<span class="tag">' . (int)$s['segments'] . ' segments</span>'; ?>
		<?php if ($s['limit_type']) echo '<span class="tag">' . h(limit_text($s)) . '</span>'; ?>
		<?php if ($s['timer_active']) echo '<span class="tag">timer</span>'; ?>
		<?php if ($s['faults']) echo '<span class="tag f">' . (int)$s['faults'] . ' fault/thermal</span>'; ?>
		<?php if ($s['partial']) echo '<span class="tag">partial</span>'; ?>
	</a></div>
<?php } ?>
<p class="sm"><a href="sessions.php?view=months">Monthly temperatures &amp; thermal events &rarr;</a></p></div>
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

<div class="sec"><h4>Collector</h4>
<?php foreach ($units as $u) { $age = $u['last_ok_at'] ? time() - $u['last_ok_at'] : null; $pa = $u['last_push_at'] ? time() - $u['last_push_at'] : null; ?>
	<div class="kv"<?=$u['srssi'] !== null ? ' title="WiFi ' . (int)$u['srssi'] . ' dBm"' : ''?>><span><?=h(unit_short($u['unit_name']))?></span><span<?=($age === null || $age > 300) ? ' class="err"' : ''?>><?=$age === null ? 'never' : ($age < 120 ? 'ok' : 'last ' . sess_fmt_dur($age) . ' ago')?><?=$pa !== null ? ' &middot; push ' . ($pa < 90 ? $pa . 's' : sess_fmt_dur($pa)) : ' &middot; polled'?><?=$u['temp_c'] !== null ? ' &middot; ' . temp_txt($u['temp_c']) : ''?><?=$u['srssi'] !== null ? ' &middot; ' . (int)$u['srssi'] . 'dBm' : ''?><?=$u['charging'] ? ' &middot; charging' : ($u['connected'] ? ' &middot; plugged in' : '')?></span></div>
<?php } ?>
<p class="sm" style="margin-top:4px">push = seconds since the unit's last push; dBm = WiFi signal (v5 units only).</p></div>
<div class="sec"><p class="sm"><a href="sessions.php?view=months">Monthly temperatures, thermal events &amp; drop-offs</a></p>
<p class="sm">History starts <?php $hs = (int)$db->query('SELECT min(plug_in_at) FROM sessions')->fetchColumn(); echo $hs ? oev_v(date('M j, Y', $hs)) : 'with the first session'; ?>. kW = amps &times; <?=oev_v(EVSE_VOLTS_L2 . ' V')?> on L2, <?=oev_v(EVSE_VOLTS_L1 . ' V')?> on L1 (assumed), unless a unit has its own voltage sensor (Settings &gt; Units) or override. <a href="./">Back to units</a></p></div>
</div></body></html>
