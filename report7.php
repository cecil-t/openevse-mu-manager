<?php
/******************************************************************************
 * report7.php - rolling 7-day chart, every unit overlaid on one graph.
 *  - amps (left axis, solid, max per 5-minute bucket), temperature (right axis,
 *    dashed, avg of the hottest sensor per bucket), charging segments shaded,
 *    plugged-in sessions as thin strips under the plot, stretches with no
 *    readings (unit offline) hatched grey. One colour per unit.
 *  - chips turn units / amps / temperatures / shading on and off (remembered in
 *    localStorage). Hover or tap for the values at that time.
 *  - the last 7 days' sessions per unit under the chart (sessions table).
 *  - ?json=1 returns the same data as JSON (the page refreshes it every 5 min).
 * Read only: sessions.db (read-only connection) and openevse.db unit names.
 * Linked from Session reports.
 ******************************************************************************/
$R7_JSON = isset($_GET['json']);
require_once __DIR__ . '/auth_lib.php'; oev_require('view', $R7_JSON);   // login gate (auth_lib.php)
require_once __DIR__ . '/sessions_lib.php';   // SESS_DB_PATH, sess_fmt_dur()
require_once __DIR__ . '/evse_prefs.php';     // TEMP_UNIT, temp_conv()
require_once __DIR__ . '/util_lib.php';       // h(), oev_sess_ro()

define('R7_DAYS', 7);
define('R7_STEP', 300);   // seconds per bucket sent to the browser

function r7_short_names($names){
	// a "Place: " prefix that every unit's name shares is left out (as on sessions.php)
	$pre = '';
	$ns = array_values($names);
	if (count($ns) > 1 && preg_match('/^([^:]{1,40}:\s*)\S/', (string)$ns[0], $m)) { $pre = $m[1]; foreach ($ns as $x) if (strpos((string)$x, $pre) !== 0) { $pre = ''; break; } }
	$o = array(); foreach ($names as $id => $n) $o[$id] = ($pre !== '' && strpos((string)$n, $pre) === 0) ? substr((string)$n, strlen($pre)) : (string)$n;
	return $o;
}

function r7_data(){
	$now = time();
	$since = (int)(floor(($now - R7_DAYS * 86400) / R7_STEP) * R7_STEP);
	$vis = oev_visible_unit_ids();   // null = all
	$out = array('ok' => true, 'now' => $now, 'since' => $since, 'step' => R7_STEP, 'tz' => date_default_timezone_get(),
		'tsym' => TEMP_UNIT == 'F' ? "\u{00B0}F" : "\u{00B0}C", 'units' => array(), 'series' => array(), 'segs' => array(), 'sess' => array(), 'gaps' => array(), 'ticks' => array(), 'list' => array(), 'error' => null);
	// unit names + stable colours (by unit id across ALL units, so a colour never moves when scope changes)
	$palette = array('#1f77b4', '#d62728', '#2ca02c', '#9467bd', '#ff7f0e', '#17becf', '#8c564b', '#e377c2', '#7f7f7f', '#bcbd22');
	$names = array();
	try { foreach (oev_db()->query('SELECT id, name FROM evses ORDER BY id') as $r) $names[(int)$r['id']] = (string)$r['name']; } catch (Throwable $e) {}
	$color = array(); $i = 0; foreach ($names as $id => $n) $color[$id] = $palette[$i++ % count($palette)];
	$short = r7_short_names($names);
	$ids = array_keys($names);
	if ($vis !== null) $ids = array_values(array_intersect($ids, $vis));
	uasort($short, function($a, $b){ return strnatcasecmp($a, $b); });
	foreach ($short as $id => $n) if (in_array($id, $ids, true)) $out['units'][] = array('id' => $id, 'name' => $n, 'color' => $color[$id]);
	// day / hour ticks in the site time zone (DST-safe: worked out here, not in the browser)
	$tz = new DateTimeZone(date_default_timezone_get());
	$d = (new DateTime('@' . $since))->setTimezone($tz)->setTime(0, 0);
	for ($k = 0; $k < R7_DAYS + 2; $k++) {
		foreach (array(0, 6, 12, 18) as $hr) {
			$x = (clone $d)->setTime($hr, 0); $ts = $x->getTimestamp();
			if ($ts > $since && $ts < $now) $out['ticks'][] = array($ts, $hr ? $x->format('ga') : $x->format('D n/j'), $hr ? 0 : 1);
		}
		$d->modify('+1 day');
	}
	if (!$ids) return $out;
	$in = implode(',', array_map('intval', $ids));
	if (!is_file(SESS_DB_PATH)) { $out['error'] = 'No session data yet. The collector has not run.'; return $out; }
	try {
		$db = oev_sess_ro(3);
		$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
		$step = R7_STEP;
		// 5-minute buckets: max amps, avg temp (hottest sensor), coarsest row resolution, charging flag
		$q = $db->query("SELECT unit_id u, (ts - $since) / $step b, max(amps) a, avg(temp_c) t, max(coalesce(res, 60)) r
			FROM samples WHERE ts >= $since AND ts <= $now AND unit_id IN ($in) GROUP BY unit_id, b ORDER BY unit_id, b");
		foreach ($q as $r) {
			$a = $r['a'] === null ? null : round((float)$r['a'], 1);
			$t = $r['t'] === null ? null : round(temp_conv($r['t']), 1);
			$row = array((int)$r['b'], $a, $t);
			if ((int)$r['r'] > $step) $row[] = (int)$r['r'];
			$out['series'][(int)$r['u']][] = $row;
		}
		// offline: readings further apart than their own resolution allows (as the chart's line breaks), or none lately
		$last = array();
		foreach ($db->query("SELECT u, p, ts, gap FROM (SELECT unit_id u, ts, lag(ts) OVER w p, max(900, 2 * max(coalesce(res, 60), coalesce(lag(res) OVER w, 60)) + $step) gap
				FROM samples WHERE ts >= $since AND ts <= $now AND unit_id IN ($in) WINDOW w AS (PARTITION BY unit_id ORDER BY ts)) WHERE p IS NOT NULL AND ts - p > gap") as $r)
			$out['gaps'][(int)$r['u']][] = array((int)$r['p'], (int)$r['ts']);
		foreach ($db->query("SELECT unit_id u, max(ts) t FROM samples WHERE ts >= $since AND unit_id IN ($in) GROUP BY unit_id") as $r)
			if ($now - (int)$r['t'] > 900) $out['gaps'][(int)$r['u']][] = array((int)$r['t'], $now);
		foreach ($db->query("SELECT unit_id, start_at, stop_at, open FROM segments WHERE unit_id IN ($in) AND start_at <= $now AND (open = 1 OR stop_at IS NULL OR stop_at >= $since) ORDER BY start_at") as $r)
			$out['segs'][(int)$r['unit_id']][] = array(max($since, (int)$r['start_at']), ($r['open'] || !$r['stop_at']) ? $now : (int)$r['stop_at']);
		$tot = array();
		foreach ($db->query("SELECT id, unit_id, plug_in_at, unplug_at, open, charge_seconds, kwh, kwh_meter, segments, peak_amps FROM sessions
				WHERE unit_id IN ($in) AND plug_in_at <= $now AND (open = 1 OR unplug_at IS NULL OR unplug_at >= $since) ORDER BY plug_in_at DESC") as $s) {
			$u = (int)$s['unit_id']; $end = ($s['open'] || !$s['unplug_at']) ? $now : (int)$s['unplug_at'];
			$out['sess'][$u][] = array(max($since, (int)$s['plug_in_at']), $end);
			$kwh = (float)$s['kwh']; $live = false;
			if ($s['open'] && $kwh <= 0 && $s['kwh_meter'] !== null && (float)$s['kwh_meter'] > 0) { $kwh = (float)$s['kwh_meter']; $live = true; }   // as sessions.php: unit meter while open
			$out['list'][] = array('id' => (int)$s['id'], 'u' => $u, 'in' => (int)$s['plug_in_at'], 'out' => $s['open'] ? null : (int)$s['unplug_at'],
				'chg' => (int)$s['charge_seconds'], 'kwh' => round($kwh, 2), 'live' => $live, 'peak' => $s['peak_amps'] === null ? null : round((float)$s['peak_amps'], 1));
		}
	} catch (Throwable $e) { $out['error'] = 'Session database unavailable.'; }
	return $out;
}

$D = r7_data();
if ($R7_JSON) {
	header('Content-Type: application/json'); header('Cache-Control: no-store');
	echo json_encode($D);
	exit;
}

$wq = '';
$title = '7-day chart';
include __DIR__ . '/_head.php';
?>
<style>
.container{max-width:1100px}
.sec{background:rgba(246,246,246,.95);border:1px solid #ccc;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.18);padding:6px 10px;margin:10px 0;color:#444}
.sec h4{margin:2px 0 6px 0;color:#333}
.sm{font-size:13px;color:#777}
.chips{display:flex;flex-wrap:wrap;gap:6px;margin:4px 0 8px 0}
.chip{display:inline-flex;align-items:center;gap:6px;border:1px solid #bbb;border-radius:16px;background:#fff;color:#333;padding:4px 11px;font:14px Arial,sans-serif;cursor:pointer;user-select:none;-webkit-tap-highlight-color:transparent;max-width:100%;box-sizing:border-box}
.chip .sw{display:inline-block;width:14px;height:4px;border-radius:2px;flex:none}
.chip .sw.d{height:0;border-top:2px dashed #888;background:none!important;width:14px}
.chip .nm{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.chip.off{background:#e4e4e4;color:#999;border-color:#ccc}
.chip.off .sw{opacity:.25}.chip.off .nm{text-decoration:line-through}
.chip.opt{border-style:dashed}
.chips .sep{flex-basis:100%;height:0}
#r7wrap{position:relative;touch-action:pan-y;-webkit-user-select:none;user-select:none}
#r7svg{display:block;background:#fff;border:1px solid #ccc;border-radius:6px;box-sizing:border-box}
#r7svg text{font-family:Arial,sans-serif}
#r7tip{position:absolute;top:8px;z-index:5;pointer-events:none;background:rgba(255,255,255,.97);border:1px solid #aaa;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.25);padding:5px 8px;font:13px/1.35 Arial,sans-serif;color:#333;white-space:nowrap}
#r7tip b{display:block;margin-bottom:2px}
#r7tip .sw{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:5px;vertical-align:-1px}
#r7tip .c{color:#07d;font-size:11px}
.ucard{background:#fff;border:1px solid #ccc;border-radius:8px;padding:6px 9px;margin:6px 0}
.ucard .hd{display:flex;align-items:center;gap:7px;font-weight:bold;color:#333}
.ucard .hd .sw{width:12px;height:12px;border-radius:3px;flex:none}
.ucard .hd .tt{margin-left:auto;font-weight:normal;color:#555;font-size:14px;white-space:nowrap}
.srow{display:flex;gap:8px;justify-content:space-between;border-top:1px dotted #ddd;padding:3px 0;font-size:14px;color:#555;text-decoration:none}
.srow:first-of-type{border-top:0}
/* style.css boxes every 'a span' (old button look); undo that inside the session rows */
.srow span{display:inline;background:none;border:0;padding:0;margin:0;width:auto;text-align:inherit;color:inherit;font-size:inherit}
.srow .w{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.srow .k{display:block;flex:none;white-space:nowrap;color:#333;font-weight:600}
.srow span.o{color:#07d;font-size:12px;font-weight:normal}
.ulist{display:grid;grid-template-columns:minmax(0,1fr);gap:0 10px}
@media (min-width:700px){.ulist{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}}
.nav a{margin-right:12px}
</style>
<div class="sec nav sm"><a href="./">&larr; Home</a><a href="sessions.php">Session reports</a></div>
<div class="sec">
	<h4>Last 7 days, all units</h4>
	<div class="chips" id="r7chips"></div>
	<div id="r7wrap"><svg id="r7svg" role="img" aria-label="Charging current and temperature for every unit over the last 7 days"></svg><div id="r7tip" hidden></div></div>
	<div class="sm" id="r7note" style="margin-top:5px"></div>
</div>
<div class="sec"><h4>Sessions, last 7 days</h4><div id="r7list"></div></div>
<script>
var R7 = <?=json_encode($D, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
(function(){
"use strict";
var LS = 'oev_report7';
var prefs = {off: [], amps: true, temps: true, shade: true, gaps: true};
try { var p = JSON.parse(localStorage.getItem(LS) || 'null'); if (p && typeof p === 'object') { for (var k in prefs) if (k in p) prefs[k] = p[k]; } } catch (e) {}
function save(){ try { localStorage.setItem(LS, JSON.stringify(prefs)); } catch (e) {} }
function isOn(id){ return prefs.off.indexOf(id) < 0; }
var NS = 'http://www.w3.org/2000/svg';
var fmtT, fmtD;
try {
	fmtT = new Intl.DateTimeFormat('en-US', {timeZone: R7.tz, weekday: 'short', month: 'numeric', day: 'numeric', hour: 'numeric', minute: '2-digit'});
	fmtD = new Intl.DateTimeFormat('en-US', {timeZone: R7.tz, weekday: 'short', month: 'numeric', day: 'numeric', hour: 'numeric', minute: '2-digit'});
} catch (e) { fmtT = fmtD = {format: function(d){ return d.toLocaleString(); }}; }
function ft(ts){ return fmtT.format(new Date(ts * 1000)); }
function esc(s){ return String(s).replace(/[&<>"']/g, function(c){ return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }
function dur(s){ s = Math.max(0, Math.round(s)); if (s < 60) return s + 's'; var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60); if (h >= 24) return Math.floor(h / 24) + 'd ' + (h % 24) + 'h'; return h ? h + 'h ' + (m < 10 ? '0' : '') + m + 'm' : m + 'm'; }
function el(tag, attrs, parent){ var e = document.createElementNS(NS, tag); for (var k in attrs) e.setAttribute(k, attrs[k]); if (parent) parent.appendChild(e); return e; }
function niceStep(raw){ if (raw <= 0) return 1; var p = Math.pow(10, Math.floor(Math.log(raw) / Math.LN10)), f = raw / p, ms = [1, 2, 2.5, 5, 10]; for (var i = 0; i < ms.length; i++) if (f <= ms[i]) return ms[i] * p; return 10 * p; }

/* ---------- chips ---------- */
function chips(){
	var box = document.getElementById('r7chips'), h = '';
	R7.units.forEach(function(u){ h += '<button type="button" class="chip' + (isOn(u.id) ? '' : ' off') + '" data-u="' + u.id + '" aria-pressed="' + isOn(u.id) + '"><span class="sw" style="background:' + u.color + '"></span><span class="nm">' + esc(u.name) + '</span></button>'; });
	h += '<span class="sep"></span>';
	h += '<button type="button" class="chip opt' + (prefs.amps ? '' : ' off') + '" data-o="amps" aria-pressed="' + prefs.amps + '"><span class="sw" style="background:#555"></span><span class="nm">Amps</span></button>';
	h += '<button type="button" class="chip opt' + (prefs.temps ? '' : ' off') + '" data-o="temps" aria-pressed="' + prefs.temps + '"><span class="sw d"></span><span class="nm">Temperature</span></button>';
	h += '<button type="button" class="chip opt' + (prefs.shade ? '' : ' off') + '" data-o="shade" aria-pressed="' + prefs.shade + '"><span class="sw" style="background:#9cc8ee;height:10px"></span><span class="nm">Charging shading</span></button>';
	h += '<button type="button" class="chip opt' + (prefs.gaps ? '' : ' off') + '" data-o="gaps" aria-pressed="' + prefs.gaps + '"><span class="sw" style="height:10px;background:repeating-linear-gradient(45deg,#aaa 0 2px,#eee 2px 4px)"></span><span class="nm">Offline</span></button>';
	box.innerHTML = h;
}
document.getElementById('r7chips').addEventListener('click', function(ev){
	var b = ev.target.closest ? ev.target.closest('.chip') : null; if (!b) return;
	if (b.dataset.u) { var id = +b.dataset.u, i = prefs.off.indexOf(id); if (i < 0) prefs.off.push(id); else prefs.off.splice(i, 1); }
	else if (b.dataset.o) prefs[b.dataset.o] = !prefs[b.dataset.o];
	save(); chips(); draw();
});

/* ---------- chart ---------- */
var G = null;   // geometry of the last draw, for the tooltip
function draw(){
	var svg = document.getElementById('r7svg'), wrap = document.getElementById('r7wrap');
	while (svg.firstChild) svg.removeChild(svg.firstChild);
	hideTip();
	var W = Math.max(280, Math.floor(wrap.clientWidth)), narrow = W < 520;
	var units = R7.units.filter(function(u){ return isOn(u.id); });
	var lane = 4, laneH = units.length * lane + (units.length ? 4 : 0);
	var pl = 32, pr = prefs.temps ? 34 : 10, pt = 16, pb = 22 + laneH;
	var H = narrow ? 250 + laneH : 330 + laneH;
	var ph = H - pt - pb, pw = W - pl - pr;
	svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H); svg.setAttribute('width', W); svg.setAttribute('height', H);
	var t0 = R7.since, t1 = R7.now, step = R7.step;
	var X = function(ts){ return pl + pw * (ts - t0) / (t1 - t0); };
	// axis ranges from the units that are on
	var amax = 0, tmin = 1e9, tmax = -1e9;
	units.forEach(function(u){ (R7.series[u.id] || []).forEach(function(r){ if (r[1] !== null && r[1] > amax) amax = r[1]; if (r[2] !== null) { if (r[2] < tmin) tmin = r[2]; if (r[2] > tmax) tmax = r[2]; } }); });
	var astep = niceStep(Math.max(8, amax) / 4), atop = astep * Math.max(1, Math.ceil(Math.max(8, amax) * 1.05 / astep));
	if (tmax < tmin) { tmin = R7.tsym.indexOf('F') >= 0 ? 40 : 5; tmax = tmin + 50; }
	var tstep = niceStep(Math.max(5, tmax - tmin) / 4); tmin = Math.floor(tmin / tstep) * tstep; tmax = Math.max(tmin + tstep * 2, Math.ceil(tmax / tstep) * tstep);
	var YA = function(a){ return pt + ph * (1 - a / atop); };
	var YT = function(c){ return pt + ph * (1 - (c - tmin) / (tmax - tmin)); };
	// shading: charging segments
	if (prefs.shade) units.forEach(function(u){ minusGaps(R7.segs[u.id], R7.gaps[u.id]).forEach(function(s){ var x1 = X(Math.max(t0, s[0])), x2 = X(Math.min(t1, s[1])); el('rect', {x: x1.toFixed(1), y: pt, width: Math.max(1.5, x2 - x1).toFixed(1), height: ph, fill: u.color, 'fill-opacity': .13}, svg); }); });
	// offline: grey hatching, full height
	if (prefs.gaps) {
		var pat = el('pattern', {id: 'r7hatch', width: 6, height: 6, patternUnits: 'userSpaceOnUse', patternTransform: 'rotate(45)'}, el('defs', {}, svg));
		el('rect', {width: 6, height: 6, fill: '#f2f2f2'}, pat); el('line', {x1: 0, y1: 0, x2: 0, y2: 6, stroke: '#c8c8c8', 'stroke-width': 2}, pat);
		units.forEach(function(u){ (R7.gaps[u.id] || []).forEach(function(s){ var x1 = X(Math.max(t0, s[0])), x2 = X(Math.min(t1, s[1])); el('rect', {x: x1.toFixed(1), y: pt, width: Math.max(1.5, x2 - x1).toFixed(1), height: ph, fill: 'url(#r7hatch)', 'fill-opacity': .7}, svg); }); });
	}
	// grid + amps axis (left)
	for (var a = 0; a <= atop + astep / 2; a += astep) {
		var y = YA(a).toFixed(1);
		el('line', {x1: pl, x2: W - pr, y1: y, y2: y, stroke: a ? '#eee' : '#bbb'}, svg);
		if (prefs.amps) { var tx = el('text', {x: pl - 4, y: +y + 3.5, 'font-size': 10, 'text-anchor': 'end', fill: '#555'}, svg); tx.textContent = Math.round(a * 10) / 10; }
	}
	if (prefs.amps) { var la = el('text', {x: 2, y: 10, 'font-size': 10, fill: '#555'}, svg); la.textContent = 'amps'; }
	// temperature axis (right)
	if (prefs.temps) {
		for (var g = 0; g <= 4; g++) { var tv = tmin + (tmax - tmin) * g / 4, yy = YT(tv); var tt = el('text', {x: W - pr + 4, y: (yy + 3.5).toFixed(1), 'font-size': 10, fill: '#a55'}, svg); tt.textContent = Math.round(tv); }
		var lt = el('text', {x: W - 2, y: 10, 'font-size': 10, 'text-anchor': 'end', fill: '#a55'}, svg); lt.textContent = R7.tsym + ' - -';
	}
	// day lines (midnight, dashed) and hour ticks
	var dayX = [];
	R7.ticks.forEach(function(k){ var x = X(k[0]).toFixed(1); if (k[2]) { dayX.push([k[0], k[1]]); el('line', {x1: x, x2: x, y1: pt, y2: pt + ph, stroke: '#999', 'stroke-dasharray': '2 3'}, svg); } else if (!narrow) el('line', {x1: x, x2: x, y1: pt + ph, y2: pt + ph + 3, stroke: '#bbb'}, svg); });
	// day labels centred in each day's span (only if it fits)
	var edges = [[t0, null]].concat(dayX).concat([[t1, null]]);
	for (var i = 0; i + 1 < edges.length; i++) {
		var xa = X(edges[i][0]), xb = X(edges[i + 1][0]), lbl = edges[i][1];
		if (lbl === null) { var d0 = new Date((edges[i][0] + 1) * 1000); try { lbl = new Intl.DateTimeFormat('en-US', {timeZone: R7.tz, weekday: 'short', month: 'numeric', day: 'numeric'}).format(d0).replace(',', ''); } catch (e) { lbl = ''; } }
		var full = xb - xa >= 62, short = xb - xa >= 30;
		if (!short) continue;
		var txt = full ? lbl : lbl.split(' ')[0];
		var dl = el('text', {x: ((xa + xb) / 2).toFixed(1), y: H - 6, 'font-size': 10, 'text-anchor': 'middle', fill: '#555'}, svg); dl.textContent = txt;
	}
	// plugged-in strips under the plot
	units.forEach(function(u, k){ var yy = pt + ph + 4 + k * lane; minusGaps(R7.sess[u.id], R7.gaps[u.id]).forEach(function(s){ var x1 = X(Math.max(t0, s[0])), x2 = X(Math.min(t1, s[1])); el('rect', {x: x1.toFixed(1), y: yy, width: Math.max(1.5, x2 - x1).toFixed(1), height: lane - 1, fill: u.color, rx: 1}, svg); });
		if (prefs.gaps) (R7.gaps[u.id] || []).forEach(function(s){ var x1 = X(Math.max(t0, s[0])), x2 = X(Math.min(t1, s[1])); el('rect', {x: x1.toFixed(1), y: yy, width: Math.max(1.5, x2 - x1).toFixed(1), height: lane - 1, fill: '#d0d0d0'}, svg); }); });
	if (units.length) { var pg = el('text', {x: pl - 4, y: (pt + ph + 4 + laneH / 2 - 2).toFixed(1), 'font-size': 8, 'text-anchor': 'end', fill: '#777'}, svg);
		el('tspan', {x: pl - 4, dy: 0}, pg).textContent = 'plugged'; el('tspan', {x: pl - 4, dy: 8}, pg).textContent = 'in'; }
	// lines: break where buckets are further apart than the data's own resolution allows
	function path(rows, idx, Y){
		var d = '', prev = null;
		rows.forEach(function(r, i){
			var v = r[idx]; if (v === 0 && idx === 1 && !((rows[i - 1] && rows[i - 1][1]) || (rows[i + 1] && rows[i + 1][1]))) v = null;
			if (v === null) { prev = null; return; }
			var ts = t0 + r[0] * step + step / 2, res = r[3] || 60;
			var brk = prev === null || (r[0] - prev[0]) * step > Math.max(900, 2 * Math.max(res, prev[3] || 60) + step);
			d += (brk ? 'M' : 'L') + X(ts).toFixed(1) + ',' + Y(v).toFixed(1);
			prev = r;
		});
		return d;
	}
	if (prefs.temps) units.forEach(function(u){ var d = path(R7.series[u.id] || [], 2, YT); if (d) el('path', {d: d, fill: 'none', stroke: u.color, 'stroke-width': 1.2, 'stroke-dasharray': '4 3', 'stroke-opacity': .85, 'stroke-linejoin': 'round'}, svg); });
	if (prefs.amps) units.forEach(function(u){ var d = path(R7.series[u.id] || [], 1, YA); if (d) el('path', {d: d, fill: 'none', stroke: u.color, 'stroke-width': 1.8, 'stroke-linejoin': 'round'}, svg); });
	el('rect', {x: pl, y: pt, width: pw, height: ph, fill: 'none', stroke: '#ccc'}, svg);
	// hover guide + hit slices (one per bucket; the browser does the hit test, so CSS zoom never skews it)
	var guide = el('line', {x1: 0, x2: 0, y1: pt, y2: pt + ph, stroke: '#333', 'stroke-width': 1, 'stroke-opacity': .5, visibility: 'hidden'}, svg);
	var dots = el('g', {}, svg);
	var n = Math.ceil((t1 - t0) / step), hit = el('g', {fill: '#000', 'fill-opacity': 0}, svg), sw = pw / n;
	for (var b = 0; b < n; b++) el('rect', {x: (pl + b * sw).toFixed(2), y: pt, width: (sw + .6).toFixed(2), height: ph + laneH + 4, 'data-b': b}, hit);
	// fast lookup: bucket -> row per unit
	var look = {};
	units.forEach(function(u){ var m = {}; (R7.series[u.id] || []).forEach(function(r){ m[r[0]] = r; }); look[u.id] = m; });
	G = {units: units, look: look, X: X, YA: YA, YT: YT, guide: guide, dots: dots, step: step, t0: t0, t1: t1, W: W};
	var shown = units.length, withData = units.filter(function(u){ return (R7.series[u.id] || []).length; }).length;
	document.getElementById('r7note').innerHTML = (R7.error ? '<span class="err">' + esc(R7.error) + '</span> ' : '')
		+ 'Solid = charging current (max per 5 min, left axis)' + (prefs.temps ? ', dashed = temperature (hottest sensor, avg per 5 min, right axis)' : '') + (prefs.shade ? ', shaded = charging' : '') + (prefs.gaps ? ', grey hatching = offline (no readings)' : '') + ', strips under the chart = plugged in. '
		+ (shown ? withData + ' of ' + shown + ' units have data. ' : 'All units are turned off. ') + 'Hover or tap the chart for values. Updated ' + esc(ft(R7.now)) + '.';
}
function inAny(list, ts){ return (list || []).some(function(s){ return ts >= s[0] && ts <= s[1]; }); }
function onSegs(u, ts){ return inAny(R7.segs[u.id], ts) && !inAny(R7.gaps[u.id], ts); }
// spans with the unit's offline stretches cut out (no readings = not shown as charging / plugged in)
function minusGaps(spans, gaps){
	var out = []; (spans || []).forEach(function(s){ var parts = [[s[0], s[1]]];
		(gaps || []).forEach(function(g){ var nx = []; parts.forEach(function(p){ if (g[1] <= p[0] || g[0] >= p[1]) nx.push(p); else { if (g[0] > p[0]) nx.push([p[0], g[0]]); if (g[1] < p[1]) nx.push([g[1], p[1]]); } }); parts = nx; });
		out = out.concat(parts); });
	return out;
}
function showAt(b){
	if (!G) return;
	var ts = G.t0 + b * G.step, mid = ts + G.step / 2, x = G.X(Math.min(G.t1, mid));
	G.guide.setAttribute('x1', x.toFixed(1)); G.guide.setAttribute('x2', x.toFixed(1)); G.guide.setAttribute('visibility', 'visible');
	while (G.dots.firstChild) G.dots.removeChild(G.dots.firstChild);
	var rows = '';
	G.units.forEach(function(u){
		var r = G.look[u.id][b]; if (!r) return;
		var chg = onSegs(u, mid);
		if (prefs.amps && r[1] !== null) el('circle', {cx: x.toFixed(1), cy: G.YA(r[1]).toFixed(1), r: 3, fill: u.color, stroke: '#fff', 'stroke-width': 1}, G.dots);
		if (prefs.temps && r[2] !== null) el('circle', {cx: x.toFixed(1), cy: G.YT(r[2]).toFixed(1), r: 2.5, fill: '#fff', stroke: u.color, 'stroke-width': 1.5}, G.dots);
		var parts = [];
		if (prefs.amps) parts.push(r[1] === null ? '- A' : r[1].toFixed(1) + ' A');
		if (prefs.temps) parts.push(r[2] === null ? '-' : Math.round(r[2] * (R7.tsym.indexOf('F') >= 0 ? 1 : 10)) / (R7.tsym.indexOf('F') >= 0 ? 1 : 10) + ' ' + R7.tsym);
		rows += '<div><span class="sw" style="background:' + u.color + '"></span>' + esc(u.name) + ': ' + parts.join(' &middot; ') + (chg ? ' <span class="c">charging</span>' : '') + '</div>';
	});
	var tip = document.getElementById('r7tip');
	tip.innerHTML = '<b>' + esc(ft(ts)) + '</b>' + (rows || '<div class="sm">No readings in these 5 minutes</div>');
	tip.hidden = false;
	var tw = tip.offsetWidth, left = x + 12;
	if (left + tw > G.W - 4) left = x - 12 - tw;
	if (left < 4) left = Math.max(4, Math.min(G.W - tw - 4, x - tw / 2));
	tip.style.left = left + 'px';
}
function hideTip(){ var t = document.getElementById('r7tip'); if (t) t.hidden = true; if (G) { G.guide.setAttribute('visibility', 'hidden'); while (G.dots.firstChild) G.dots.removeChild(G.dots.firstChild); } }
function pick(cx, cy){ var e = document.elementFromPoint(cx, cy); if (e && e.getAttribute && e.getAttribute('data-b') !== null) { showAt(+e.getAttribute('data-b')); return true; } return false; }
var wrap = document.getElementById('r7wrap');
if (window.PointerEvent) {
	wrap.addEventListener('pointermove', function(e){ pick(e.clientX, e.clientY) || (e.pointerType === 'mouse' && hideTip()); });
	wrap.addEventListener('pointerdown', function(e){ pick(e.clientX, e.clientY); });
	wrap.addEventListener('pointerleave', function(e){ if (e.pointerType === 'mouse') hideTip(); });
} else {
	wrap.addEventListener('mousemove', function(e){ pick(e.clientX, e.clientY) || hideTip(); });
	wrap.addEventListener('mouseleave', hideTip);
	wrap.addEventListener('touchstart', function(e){ var t = e.touches[0]; pick(t.clientX, t.clientY); }, {passive: true});
	wrap.addEventListener('touchmove', function(e){ var t = e.touches[0]; pick(t.clientX, t.clientY); }, {passive: true});
}
document.addEventListener('click', function(e){ if (!wrap.contains(e.target)) hideTip(); });

/* ---------- session list ---------- */
function list(){
	var box = document.getElementById('r7list'), h = '';
	if (!R7.units.length) { box.innerHTML = '<p class="sm">No units to show for your login.</p>'; return; }
	h += '<div class="ulist">';
	R7.units.forEach(function(u){
		var ss = R7.list.filter(function(s){ return s.u === u.id; }), kwh = 0, chg = 0;
		ss.forEach(function(s){ kwh += s.kwh; chg += s.chg; });
		h += '<div class="ucard"><div class="hd"><span class="sw" style="background:' + u.color + '"></span><span>' + esc(u.name) + '</span><span class="tt">' + (ss.length ? kwh.toFixed(1) + ' kWh &middot; ' + ss.length + ' session' + (ss.length === 1 ? '' : 's') + ' &middot; ' + dur(chg) + ' charging' : 'no sessions') + '</span></div>';
		ss.forEach(function(s){
			var plugged = (s.out || R7.now) - s.in;
			h += '<a class="srow" href="sessions.php?id=' + s.id + '"><span class="w">' + esc(ft(s.in)) + ' &middot; plugged ' + dur(plugged) + (s.out ? '' : ' <span class="o">in progress</span>') + ' &middot; charged ' + dur(s.chg) + '</span><span class="k">' + s.kwh.toFixed(2) + ' kWh' + (s.live ? ' <span class="o">so far</span>' : '') + '</span></a>';
		});
		h += '</div>';
	});
	box.innerHTML = h + '</div><p class="sm">Sessions plugged in during the last 7 days (or still plugged in). Tap one for its detail page. kWh while a session is still open comes from the unit\'s own meter, as on Session reports.</p>';
}

chips(); draw(); list();
var rt = null;
window.addEventListener('resize', function(){ clearTimeout(rt); rt = setTimeout(draw, 150); });
// fresh data every 5 minutes while the page is visible
setInterval(function(){
	if (document.hidden || !window.fetch) return;
	fetch('report7.php?json=1', {credentials: 'same-origin', cache: 'no-store'}).then(function(r){ return r.ok ? r.json() : null; }).then(function(j){ if (j && j.ok) { R7 = j; chips(); draw(); list(); } }).catch(function(){});
}, 300000);
})();
</script>
</div></body></html>
