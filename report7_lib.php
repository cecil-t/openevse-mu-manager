<?php
/******************************************************************************
 * report7_lib.php - rolling 7-day multi-unit chart data + embed markup.
 * Used by sessions.php (top overview) and report7.php (JSON / thin page).
 ******************************************************************************/
require_once __DIR__ . '/sessions_lib.php';
require_once __DIR__ . '/evse_prefs.php';
require_once __DIR__ . '/util_lib.php';
require_once __DIR__ . '/auth_lib.php';

if (!defined('R7_DAYS')) define('R7_DAYS', 7);
if (!defined('R7_STEP')) define('R7_STEP', 300);

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


function r7_span_label($D){
	$a = (int)($D['since'] ?? 0); $b = (int)($D['now'] ?? 0);
	if (!$a || !$b) return '';
	return date('M j, Y', $a) . ' – ' . date('M j, Y g:ia T', $b);
}


/** Shared CSS for the Last-7-days chart + per-unit session cards (sessions top + report7). */
function r7_css(){
	return <<<'CSS'
.r7 .chips{display:flex;flex-wrap:wrap;gap:6px;margin:4px 0 8px 0}
.r7 .chip{display:inline-flex;align-items:center;gap:6px;border:1px solid #bbb;border-radius:16px;background:#fff;color:#333;padding:4px 11px;font:14px Arial,sans-serif;cursor:pointer;user-select:none;-webkit-tap-highlight-color:transparent;max-width:100%;box-sizing:border-box}
.r7 .chip .sw{display:inline-block;width:14px;height:4px;border-radius:2px;flex:none}
.r7 .chip .sw.d{height:0;border-top:2px dashed #888;background:none!important;width:14px}
.r7 .chip .nm{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.r7 .chip.off{background:#e4e4e4;color:#999;border-color:#ccc}
.r7 .chip.off .sw{opacity:.25}.r7 .chip.off .nm{text-decoration:line-through}
.r7 .chip.opt{border-style:dashed}
.r7 .chips .sep{flex-basis:100%;height:0}
.r7 #r7wrap{position:relative;touch-action:pan-y;-webkit-user-select:none;user-select:none}
.r7 #r7svg{display:block;background:#fff;border:1px solid #ccc;border-radius:6px;box-sizing:border-box;max-width:100%}
.r7 #r7svg text{font-family:Arial,sans-serif}
.r7 #r7tip{position:absolute;top:8px;z-index:5;pointer-events:none;background:rgba(255,255,255,.97);border:1px solid #aaa;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.25);padding:5px 8px;font:13px/1.35 Arial,sans-serif;color:#333;white-space:nowrap}
.r7 #r7tip b{display:block;margin-bottom:2px}
.r7 #r7tip .sw{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:5px;vertical-align:-1px}
.r7 #r7tip .c{color:#07d;font-size:11px}
.r7 .ucard{background:#fff;border:1px solid #ccc;border-radius:8px;padding:6px 9px;margin:6px 0}
.r7 .ucard .hd{display:flex;align-items:center;gap:7px;font-weight:bold;color:#333}
.r7 .ucard .hd .sw{width:12px;height:12px;border-radius:3px;flex:none}
.r7 .ucard .hd .tt{margin-left:auto;font-weight:normal;color:#555;font-size:14px;white-space:nowrap}
.r7 .srow{display:flex;gap:8px;justify-content:space-between;border-top:1px dotted #ddd;padding:3px 0;font-size:14px;color:#555;text-decoration:none}
.r7 .srow:first-of-type{border-top:0}
.r7 .srow span{display:inline;background:none;border:0;padding:0;margin:0;width:auto;text-align:inherit;color:inherit;font-size:inherit}
.r7 .srow .w{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.r7 .srow .k{display:block;flex:none;white-space:nowrap;color:#333;font-weight:600}
.r7 .srow span.o{color:#07d;font-size:12px;font-weight:normal}
.r7 .ulist{display:grid;grid-template-columns:minmax(0,1fr);gap:0 10px}
@media (min-width:700px){.r7 .ulist{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}}
.r7 .err{color:#b11}
CSS;
}

/**
 * Markup + data bootstrap for the Last-7-days overview.
 * $D = r7_data(). JSON refresh stays on report7.php?json=1 (shared).
 */
function r7_embed_html(array $D){
	$json = json_encode($D, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
	if ($json === false) $json = '{"ok":false,"error":"encode failed","units":[],"series":{},"segs":{},"sess":{},"gaps":{},"ticks":[],"list":[]}';
	$span = h(r7_span_label($D));
	$o = '';
	$o .= '<div class="sec r7" id="r7overview">';
	$o .= '<h4>Last 7 days, all units</h4>';
	if ($span !== '') $o .= '<p class="sm" style="margin:0 0 6px 0">' . $span . '</p>';
	$o .= '<div class="chips" id="r7chips"></div>';
	$o .= '<div id="r7wrap"><svg id="r7svg" role="img" aria-label="Charging current and temperature for every unit over the last 7 days"></svg><div id="r7tip" hidden></div></div>';
	$o .= '<div class="sm" id="r7note" style="margin-top:5px"></div>';
	$o .= '</div>';
	$o .= '<div class="sec r7"><h4>Per-unit summaries, last 7 days</h4><div id="r7list"></div></div>';
	$o .= '<script>var R7 = ' . $json . '; var R7_JSON_URL = "report7.php?json=1";</script>';
	$o .= '<script src="report7_embed.js"></script>';
	return $o;
}
