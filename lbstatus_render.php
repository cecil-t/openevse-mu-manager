<?php
/* Load balancer detail page body (lbstatus.php), also sent by lbstate.php?detail=1 for the 5 s live update.
 * Data: the coordinator's heartbeat + the tail of its log; settings from lb_core.php (same constants it runs with). */
require_once __DIR__ . '/loadbalancer/coordinator/lb_core.php';
function lbs_dur($s){ $m = intdiv(max(0, (int)$s), 60); return $m >= 60 ? intdiv($m, 60) . 'h ' . ($m % 60) . 'm' : $m . 'm'; }
// last ~400 lines of the coordinator log, oldest first
function lbs_log_lines(){
	$f = __DIR__ . '/loadbalancer/run/coordinator.log';
	$fh = @fopen($f, 'r'); if (!$fh) return array();
	$sz = filesize($f); $n = min($sz, 131072); fseek($fh, $sz - $n); $t = (string)fread($fh, $n); fclose($fh);
	$l = explode("\n", rtrim($t, "\n")); if ($n < $sz) array_shift($l);
	return array_slice($l, -400);
}
// kind of a log line, for the filter
function lbs_log_kind($l){
	if (preg_match('/ group .*: plan /', $l)) return 'plan';
	if (preg_match('/NOT confirmed|failed|ERROR|refused|disconnected|lost|down/i', $l)) return 'err';
	if (preg_match('/ (set|DRY-RUN would set) u\d+ /', $l)) return 'set';
	if (preg_match('/pause|resume/i', $l)) return 'pause';
	if (preg_match('/MQTT/', $l)) return 'mqtt';
	return 'state';
}
function lbs_log_unit($l){ return preg_match('/ u(\d+)[ :]/', $l, $m) ? (int)$m[1] : 0; }
// last changes per unit id: [[time, from, to, ok], ...] newest first
function lbs_changes($lines, $max = 3){
	$o = array();
	foreach (array_reverse($lines) as $l) {
		if (!preg_match('/^\d{4}-\d\d-\d\d (\d\d:\d\d:\d\d) set u(\d+) .*? current (\d+) -> \(?(\d+) A\)?(, saved \d+ A)?: (.*)$/', $l, $m)) continue;
		$id = (int)$m[2]; if (count($o[$id] ?? array()) >= $max) continue;
		$o[$id][] = array($m[1], (int)$m[3], (int)$m[4], stripos($m[6], 'confirmed') === 0 || $m[6] === 'ok', $m[5] !== '');
	}
	return $o;
}
function lbs_log_html($lines){
	$out = '';
	foreach (array_reverse($lines) as $l) $out .= '<div class="ll k-' . lbs_log_kind($l) . '" data-u="' . lbs_log_unit($l) . '">' . h($l) . '</div>';
	return $out;
}
function lbs_render($hb){
	ob_start();
	if (!is_array($hb)) { echo '<div class="warn">Load balancer not found.</div>'; return ob_get_clean(); }
	$live = ($hb['mode'] ?? '') === 'live';
	$lines = lbs_log_lines(); $chg = lbs_changes($lines, 2);
	if (!$hb['fresh']) echo '<div class="warn"><b>Load balancer is down</b> since ' . h(date('g:i A', (int)$hb['time'])) . '. Chargers keep their last setting. It restarts on its own; if this stays red, check its Docker container on the server (docker ps).</div>';
	else echo '<div class="top"><span class="ok">Running</span> ' . h(lbs_dur(time() - (int)($hb['started'] ?? time()))) . ($live ? '' : ' &middot; <b>test mode</b>')
		. ' &middot; MQTT ' . (!empty($hb['mqtt']) ? 'on' : '<span class="bad">off</span>') . ' &middot; checked every ' . (int)($hb['poll_sec'] ?? 20) . ' s</div>';
	foreach ((array)$hb['groups'] as $g) {
		echo '<div class="lgh"><span class="n">' . h($g['name']) . '</span><span class="a">' . h(number_format((float)$g['live_amps'], 1)) . ' / ' . (int)$g['limit'] . ' A</span></div>';
		echo '<div class="gs">Counted ' . (int)($g['active_total'] ?? 0) . ' of ' . (int)$g['limit'] . ' A limit' . (!empty($g['pause_armed']) ? ' &middot; idle chargers paused' : '') . '</div>';
		foreach ((array)$g['warn'] as $w) echo '<div class="warn">' . h($w) . '</div>';
		foreach ((array)$g['units'] as $u) {
			$v = evse_lb_view($u); $id = (int)$u['id'];
			$set = $v['setting']; $sv = $v['saved'];
			$amp = $v['paused'] ? '<b>paused</b>' : (($sv !== null && $set !== null && (int)$set >= (int)$sv) ? '<b>' . (int)$sv . ' A</b>' : '<b>(' . (int)$set . ' A)</b><span> / ' . ($sv !== null ? (int)$sv : '?') . ' A</span>');
			// one plain line: what it is doing + why it has this setting
			if ($v['paused']) $why = ($v['plugged'] ? 'waiting for room' : 'idle');
			elseif ($v['sleepfree']) $why = 'not counted';
			elseif ($v['cap'] !== null) $why = 'capped';
			elseif ($set !== null && $sv !== null && (int)$set >= (int)$sv) $why = '';
			else $why = 'share';
			$st = ($v['paused'] ? 'Paused' : $v['pill']) . (($v['code'] == 254 && $v['plugged']) ? ', car in' : '') . ($v['charging'] && $v['amps'] !== null ? ' <b>' . number_format((float)$v['amps'], 1) . ' A</b>' : '');
			echo '<div class="u"><div class="l1"><span class="n"><a href="unit.php?unitid=' . $id . '">' . h(preg_replace('/^.*?:\s*/', '', $u['name'])) . '</a></span><span class="a">' . $amp . '</span></div>';
			echo '<div class="l2"><span class="s">' . $st . '</span>' . ($why !== '' ? '<span class="x">' . h($why) . '</span>' : '')
				. ($v['stale'] ? '<span class="mq stale">no MQTT' . ($v['checkin'] !== null ? ' ' . h(lbs_dur($v['checkin'])) : '') . '</span>' : '') . '</div>';
			$r = array(); foreach ($chg[$id] ?? array() as $c) $r[] = '<span class="' . ($c[3] ? 'c-ok' : 'c-bad') . '">' . h(date('g:i', strtotime($c[0]))) . ' ' . ($c[4] ? 'saved ' . $c[2] : $c[1] . '&rarr;' . $c[2]) . ($c[3] ? ' &#10003;' : ' &#10007;') . '</span>';
			if ($r) echo '<div class="l4">' . implode(' ', $r) . '</div>';
			echo '</div>';
		}
	}
	echo '<div class="legend">(temp) / saved &middot; &#10003; confirmed</div>';
	return ob_get_clean();
}
// settings, in plain words (what the coordinator runs with)
function lbs_settings($hb){
	$H = LB_HR; $P = LB_PAUSE; $rows = array();
	if (is_array($hb)) foreach ((array)$hb['groups'] as $g) {
		$rows[] = array(h($g['name']), (int)$g['limit'] . ' A limit');
		foreach ((array)$g['units'] as $u) $rows[] = array(h(preg_replace('/^.*?:\s*/', '', $u['name'])), 'saved ' . (int)($u['saved'] ?? 0) . ' A');
	}
	$rows[] = array('Share', 'cars split the limit evenly; chargers with no car get what is left');
	$rows[] = array('Lowest', LB_MIN_AMPS . ' A');
	$rows[] = array('Capped', 'a car using ' . $H['below_a'] . ' A less than its share for ' . intdiv($H['below_for'], 60) . ' min is capped at what it uses + ' . $H['margin_a'] . ' A');
	$rows[] = array('Paused', 'with 2+ cars charging, idle chargers are paused after ' . $P['arm_after'] . ' s');
	$rows[] = array('Not counted', 'one sleeping charger (' . LB_MIN_AMPS . ' A)');
	$rows[] = array('Changes', 'lower first, raise ' . $H['settle_sec'] . ' s later');
	$rows[] = array('Checked', 'every 20 s; MQTT within seconds');
	$o = '<table class="cfg">'; foreach ($rows as $r) $o .= '<tr><td>' . $r[0] . '</td><td>' . $r[1] . '</td></tr>'; return $o . '</table>';
}
