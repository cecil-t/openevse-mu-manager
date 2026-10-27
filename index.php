<?php
// scheduled window active now (handles windows past midnight) and the unit is awake, not sleeping/disabled
function evse_sched_running($sc, $e){
	$m = function($t){ $p = explode(':', $t); return (int)$p[0] * 60 + (int)($p[1] ?? 0); };
	$a = $m($sc[0]); $b = $m($sc[1]); $n = (int)date('G') * 60 + (int)date('i');
	if ($a === $b) return false;
	$in = $a < $b ? ($n >= $a && $n < $b) : ($n >= $a || $n < $b);
	return $in && isset($e['statecode']) && !in_array((int)$e['statecode'], [-1, 254, 255], true);
}
?><?php
require_once __DIR__ . '/auth_lib.php'; oev_require('public');   // login gate (auth_lib.php)
include __DIR__ . '/include_v5.php';
include __DIR__ . '/include_lb.php';
$with  = evse_with_param();
$wq    = $with ? '?with=' . implode(',', $with) : '';
$units = evse_units($with);
include __DIR__ . '/_head.php';
?>
<script>/* full reload every 5 min (sessions, schedules, lifetime); the load balancer's data updates in place every 5 s */
setInterval(function(){ if (!document.hidden) location.href = './<?=$wq?>'; }, 300000);</script>
<div class="slidebox" style="text-align:left;" id="unit">
<style>
/* unit list: one block per unit, always exactly two lines.
   line 1 (dt): name, schedule (daily start-stop, $GD / v5 /schedule) at the right end. line 2 (dd, indented): state first, then plug / kW / clock off, session (live or last) right-aligned */
dl.units{margin:0 0 6px 0;padding:0}
dl.units .ub{border-bottom:1px solid #ddd;padding:3px 0 4px 0}
dl.units dt,dl.units dd{display:flex;align-items:baseline;white-space:nowrap;overflow:hidden}
dl.units dt{height:22px;line-height:22px;margin:0}
dl.units dt a{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis}
dl.units dt .sch{flex:none;margin-left:8px;color:#999;font-size:90%}
dl.units dt .sch.none{color:#ccc}
dl.units dt .sch.run{color:#c62828;font-weight:600}   /* scheduled charge running: its stop time, calm red */
dl.units dd{height:20px;line-height:20px;margin:0 0 0 1.25em;color:#666}
dl.units dd .st{flex:none}
dl.units dd .ex{flex:none;margin-left:5px}
/* session at the right end of line 2: live (plugged in: time plugged in + kWh so far) or last (ended + kWh); from sessions.db, no unit calls */
dl.units dd .ss{flex:0 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;margin-left:auto;padding-left:6px;color:#888;font-style:italic;font-size:90%}
dl.units dd .ss.none{color:#ccc}
/* phones under 340px: no room for kW next to a live session; kW stays on the unit page */
@media (max-width:340px){dl.units dd .kw{display:none}}
dl.units img.plug{vertical-align:baseline}
/* unit name links in the logo's "Open" teal */
dl.units dt a{color:teal}
/* state pill: washed-out tint of the unit.php header colour for that state (style.css th.sN), darker text of the same hue */
dl.units dd .st i{font-style:normal}
dl.units dd .st{display:inline-block;height:16px;line-height:16px;padding:0 7px;border-radius:8px;align-self:center;background:rgba(136,136,136,.16);color:#555}
dl.units dd .st.s1{background:rgba(0,170,0,.15);color:#060}
dl.units dd .st.s2{background:rgba(0,170,119,.16);color:#064}
dl.units dd .st.s3{background:rgba(0,119,221,.15);color:#035a9e}
dl.units dd .st.s254,dl.units dd .st.s255{background:rgba(136,34,221,.13);color:#5a1a96}
dl.units dd .st.serr{background:rgba(255,34,34,.13);color:#a11}
/* card header: enabled-unit count */
.uhead{border-bottom:1px solid #ccc;padding:0 0 3px 0;margin-bottom:2px}
.uhead div{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.uhead .h1{height:22px;line-height:22px;font-weight:bold;color:#666}
/* load groups: a header per group (name, limit, live amps now, load balancer state), its units under it; ungrouped units after */
.lgh{display:flex;align-items:center;white-space:nowrap;overflow:hidden;height:26px;line-height:26px;margin-top:14px;padding:0 6px 0 8px;border-radius:4px;background:#00706f;color:#fff;font-weight:bold}
.lgh .n{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis}
.lgh .n a{color:#fff;text-decoration:underline}
.lgh .a{flex:none;margin-left:8px;font-weight:normal;font-size:90%;color:#fff}
.lgh .lb{flex:none;margin-left:6px;font-weight:normal;font-size:85%;padding:0 6px;border-radius:8px;height:16px;line-height:16px;background:rgba(255,255,255,.85);color:#555}
.lgh .lb.live{color:#060}
.lgh .lb.warn{color:#8a5a00}
.lgh .lb.down{background:#fdd;color:#a11;font-weight:bold}
.lgh.ung{background:#888}
.lgh:first-child{margin-top:4px}
.lgw{background:#fdd;color:#a11;font-size:85%;padding:2px 6px;border-radius:4px;margin:3px 0 0 0;white-space:normal}
/* load balancer note on line 2 (capped / amps lent / not counted / waiting); dropped by the script below when it doesn't fit */
dl.units dd .nt{flex:none;margin-left:6px;color:#888;font-size:85%;font-style:italic}
/* no MQTT check-in from a grouped unit for 90 s+ */
.lbdot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-left:3px;vertical-align:middle;background:#bbb}
.lbdot.g{background:#2a2}.lbdot.a{background:#e9a000}.lbdot.r{background:#d22}.lbdot.x{display:none}
dl.units dd .mqx{flex:none;margin-left:5px;color:#c22;font-size:80%}
/* pilot current setting on line 1, before the schedule */
dl.units dt .pc{flex:none;width:3.4em;margin-left:6px;text-align:right;color:#777;font-size:90%}
</style>
<?php $nenabled = count(array_filter($units, function($u){ return empty($u['_test']); })); ?>
<?php $ngroups = count(array_unique(array_filter(array_map(function($u){ return (int)($u['loadgroupid'] ?? 0); }, $units)))); ?>
<div class="uhead"><div class="h1"><?=$nenabled?> unit<?=$nenabled == 1 ? '' : 's'?> enabled<?=$ngroups ? ' &middot; ' . $ngroups . ' load group' . ($ngroups == 1 ? '' : 's') : ''?></div></div>
<dl class="units">
<?php
$lifetotal = 0;
// $all (every unit's summary) is built below, once the load groups are known

// Session info for every unit: one read-only query on the logger's sessions.db (no unit calls).
// Per unit: its latest session (open = plugged in now) and its latest finished one.
$sess = array();   // unit id => ['open'=>row|null, 'last'=>row|null]
try {
	$sdb = oev_sess_ro(1);
	$q = $sdb->query("SELECT id, unit_id, open, plug_in_at, unplug_at, charge_end_at, kwh, kwh_meter FROM sessions
		WHERE id IN (SELECT max(id) FROM sessions GROUP BY unit_id UNION SELECT max(id) FROM sessions WHERE open = 0 GROUP BY unit_id)");
	foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $sess[(int)$r['unit_id']][$r['open'] ? 'open' : 'last'] = $r;
	$sdb = null;
} catch (Exception $ex) { $sess = array(); }
function idx_dur($sec){ $m = intdiv(max(0, (int)$sec), 60); if ($m >= 1440) return intdiv($m, 1440) . 'd ' . intdiv($m % 1440, 60) . 'h'; return $m >= 60 ? intdiv($m, 60) . 'h ' . ($m % 60) . 'm' : $m . 'm'; }
function idx_kwh($v){ return number_format((float)$v, 1) . ' kWh'; }
// live: plugged in with an open logger session; kWh so far as the session report shows it (unit meter while open)
// last: not plugged in, the latest finished session's end + kWh. Nothing known: a dim dash.
function idx_session($e, $s){
	$plugged = !empty($e['connected']);
	if (oev_is_public()) { if (!$plugged) return ''; unset($s['last']); }   // view-only link: the live session only, no history
	if ($plugged && !empty($s['open'])) {
		$o = $s['open'];
		$k = ((float)$o['kwh'] > 0) ? (float)$o['kwh'] : ($o['kwh_meter'] !== null ? (float)$o['kwh_meter'] : null);
		$t = 'Plugged in ' . date('m/d H:i', (int)$o['plug_in_at']) . ($k !== null ? ', ' . number_format($k, 2) . ' kWh so far' : '');
		return '<span class="ss" title="' . h($t) . '">' . h(idx_dur(time() - (int)$o['plug_in_at'])) . ($k !== null ? ' &middot; ' . h(idx_kwh($k)) : '') . '</span>';
	}
	if (!$plugged && !empty($s['last'])) {
		$l = $s['last'];
		$end = (int)($l['unplug_at'] ?: $l['charge_end_at']);
		if ($end) {
			$k = ($l['kwh'] !== null) ? (float)$l['kwh'] : null;
			$t = 'Last session: unplugged ' . date('m/d H:i', $end) . ($k !== null ? ', ' . number_format($k, 2) . ' kWh' : '');
			return '<span class="ss" title="' . h($t) . '">' . date('m/d H:i', $end) . ($k !== null ? ' &middot; ' . h(idx_kwh($k)) : '') . '</span>';
		}
	}
	return '<span class="ss none" title="No session recorded">' . "\u{2014}" . '</span>';
}
// Load groups (openevse.db load_groups / evses.loadgroupid): one section per group with live amps,
// then units not in a group. Load balancer state from its heartbeat file (no unit calls).
$lgroups = array();
try {
	$gdb = new PDO(EVSE_DB, null, null, array(PDO::ATTR_TIMEOUT => 1, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
	foreach ($gdb->query('SELECT id, name, max_load FROM load_groups ORDER BY id') as $g) $lgroups[(int)$g['id']] = array('name'=>$g['name'], 'limit'=>(int)$g['max_load'], 'keys'=>array());
	$gdb = null;
} catch (Exception $ex) { $lgroups = array(); }
$ungrouped = array();
foreach ($units as $k => $unit) {
	$gid = (int)($unit['loadgroupid'] ?? 0);
	if ($gid && isset($lgroups[$gid])) $lgroups[$gid]['keys'][] = $k; else $ungrouped[] = $k;
}
$lgroups = array_filter($lgroups, function($g){ return $g['keys']; });
// grouped units come from the load balancer's heartbeat (<= 5 s old) when it is running; the rest are polled
$lbhb = oev_filter_hb(evse_lb_heartbeat());   // only the groups this user may see
$lbu  = evse_lb_units($lbhb);   // unit id => heartbeat entry (fresh heartbeat only)
$grouped = array(); foreach ($lgroups as $g) foreach ($g['keys'] as $k) $grouped[$k] = true;
$all = evse_home_all($units, $lbhb, $grouped);
function idx_lb_tag($hb){
	if (!is_array($hb)) return '';
	if (!$hb['fresh']) return '<span class="lb down" title="Load balancer not running: last heartbeat ' . h(date('m/d H:i', (int)$hb['time'])) . '. Units are read directly.">LB down</span>';
	$live = ($hb['mode'] ?? '') === 'live';
	if (empty($hb['mqtt'])) return '<span class="lb warn" title="Load balancer running, but its MQTT link is down: it reacts on its 20 s poll instead of within seconds">LB slow</span>';
	return '<span class="lb' . ($live ? ' live' : '') . '" title="Load balancer running (' . ($live ? 'live' : 'dry run: no changes made') . ')">LB ' . ($live ? 'on' : 'dry run') . '</span>';
}
$sections = array();
foreach ($lgroups as $gid => $g) {
	$hg = null; if (is_array($lbhb) && $lbhb['fresh']) foreach ((array)$lbhb['groups'] as $x) if ((int)$x['id'] === (int)$gid) $hg = $x;
	if ($hg) $amps = (float)$hg['live_amps'];
	else { $amps = 0; foreach ($g['keys'] as $k) $amps += (float)(($all[$k]['amps'] ?? 0) ?: 0); }
	$warn = '<div class="lgwb" data-g="' . (int)$gid . '">'; if ($hg) foreach ((array)$hg['warn'] as $w) $warn .= '<div class="lgw">' . h($w) . '</div>'; $warn .= '</div>';
	$sections[] = array('<div class="lgh" data-g="' . (int)$gid . '"><span class="n">' . (oev_is_public() ? h($g['name']) : '<a href="lbstatus.php" title="' . h($g['name']) . ': load group, ' . (int)$g['limit'] . ' A limit. Details">' . h($g['name']) . '</a>') . '</span>'
		. '<span class="a" title="Drawing now / group limit">' . h(number_format($amps, 1)) . ' / ' . (int)$g['limit'] . ' A</span>' . idx_lb_tag($lbhb) . '</div>' . $warn, $g['keys']);
}
if ($ungrouped) $sections[] = array($lgroups ? '<div class="lgh ung"><span class="n">No load group</span></div>' : '', $ungrouped);
foreach ($sections as $sec) {
echo $sec[0];
foreach ($sec[1] as $k) {
	$unit = $units[$k];
	$e = isset($all[$k]) ? $all[$k] : null;
	if (!is_array($e)) $e = array('state'=>'Connection Error', 'statecode'=>-1, 'lifetimeKWH'=>0, 'connected'=>0, 'api'=>'offline');
	$lifetotal += (float)$e['lifetimeKWH'];
	$power = ($e['statecode'] == 3 && isset($e['power'])) ? h($e['power']) . '&nbsp;kW' : '';
	$connected = !empty($e['connected']) ? '<img src="media/plug.svg" alt="Connected" title="EV Connected" class="plug" />' : '';
	$link = 'unit.php?unitid=' . (int)$unit['id'] . ($with ? '&with=' . implode(',', $with) : '');
	?>
	<?php $lu = $lbu[(int)$unit['id']] ?? null; $lv = is_array($lu) ? evse_lb_view($lu) : null; $isg = isset($grouped[$k]) || isset($lbu[(int)$unit['id']]);   // live-updated: grouped units + monitored ones
	$vv = $e['volts'] ?? ((evse_home_slow_get($unit) ?? array())['volts'] ?? null); ?>
	<div class="ub"<?=$isg ? ' data-u="' . (int)$unit['id'] . '" data-v="' . h($vv ?: 240) . '"' : ''?>>
		<dt><a href="<?=h($link)?>" title="<?=h($unit['name'])?>"><?=h($unit['name'])?></a><?php
			$sc = $e['sched'] ?? null;   // ['HH:MM','HH:MM'] / false (none) / null (unknown)
			if (is_array($sc) && evse_sched_running($sc, $e)) $sch = '<span class="sch run" title="Scheduled charge running: started ' . h($sc[0]) . ', stops ' . h($sc[1]) . '">Stop ' . h($sc[1]) . '</span>';
			elseif (is_array($sc)) $sch = '<span class="sch" title="Schedule: starts ' . h($sc[0]) . ', stops ' . h($sc[1]) . ' daily">Schd ' . h($sc[0]) . '</span>';
			elseif ($sc === false && !empty($e['lim'])) $sch = '<span class="sch" title="No schedule; session limit: ' . h(implode(' or ', $e['lim'])) . '">Lim ' . h(implode(', ', $e['lim'])) . '</span>';   // schedule wins when both exist
			elseif ($sc === false) $sch = '<span class="sch none" title="No schedule, no session limit">' . "\u{2014}" . '</span>';
			else $sch = '';   // offline / not readable
			// pilot current setting (the unit's current limit right now, $GE / v5 status)
			$pc = (isset($e['pilot']) && (int)$e['pilot'] > 0 && $e['statecode'] != -1) ? '<span class="pc" title="' . h(isset($e['saved_current']) && (int)$e['pilot'] < (int)$e['saved_current'] ? 'Temporary ' . (int)$e['pilot'] . ' A (saved ' . (int)$e['saved_current'] . ' A)' : 'Saved setting') . '">' . h(evse_amps_label($e['pilot'], $e['saved_current'] ?? null)) . '</span>' : '<span class="pc"></span>';   // always there, so pilots line up
			echo $sch . (!empty($unit['_test']) ? '<span class="sch none">(test)</span>' : '') . $pc;
		?></dt>
		<?php
			// grouped units: the load balancer's view (paused / note / check-in), updated in place every 5 s by the script below
			$stx = ($lv && $lv['paused']) ? 'Paused' : $e['state'];
			$sttl = ($lv && $lv['paused']) ? $lv['pill_title'] : $e['state'];
		?><dd><span class="st <?=in_array((int)$e['statecode'], array(1, 2, 3, 254, 255)) ? 's' . (int)$e['statecode'] : 'serr'?>" title="<?=h($sttl)?>"><i><?=h($stx)?></i></span><?php
			if ($isg) {
				echo '<span class="mqx"' . ($lv && $lv['stale'] ? '' : ' style="display:none"') . ' title="' . h($lv && $lv['checkin'] !== null ? 'No MQTT check-in for ' . idx_dur($lv['checkin']) : 'No MQTT check-in from this unit') . '">&#9679;</span>';
				echo '<span class="ex pl"' . ($connected ? '' : ' style="display:none"') . '><img src="media/plug.svg" alt="Connected" title="EV Connected" class="plug" /></span>';
				echo '<span class="ex kw"' . ($power !== '' ? '' : ' style="display:none"') . '><i>' . $power . '</i></span>';
				echo '<span class="nt"' . ($lv && $lv['note'] !== '' ? '' : ' style="display:none"') . ' title="' . h($lv['why'] ?? '') . '">' . h($lv['note'] ?? '') . '</span>';
			} else {
				if ($connected) echo '<span class="ex">' . $connected . '</span>';
				if ($power !== '') echo '<span class="ex kw"><i>' . $power . '</i></span>';
			}
			if (!empty($e['clock_off'])) echo '<span class="ex"><small style="background-color:#ffa" title="Unit clock is ' . h(evse_offset_text($e['clock_offset'])) . ' from server time">clock off</small></span>';
		?><?=($e['statecode'] != -1) ? idx_session($e, $sess[(int)$unit['id']] ?? array()) : ''?></dd>
	</div>
<?php } } ?>
</dl>
<table class="list">
<tr>
	<td id="td_time" style="vertical-align:bottom;white-space:nowrap"><i>as of <span id="asof"><?=date('m/d') . '&nbsp;' . date('H:i');?></span></i> <span id="lbdot" class="lbdot <?=is_array($lbhb) ? ($lbhb['fresh'] ? 'g' : 'r') : 'x'?>" title="<?=is_array($lbhb) ? ($lbhb['fresh'] ? 'Live updates: load balancer running' : 'Load balancer down: data read from the units at page load') : 'No load balancer'?>"></span></td>
	<td colspan="2" style="vertical-align:bottom;text-align:right;"><?=oev_is_public() ? '' : '<i>' . round($lifetotal/1000,2) . ' MWh</i>'?></td>
</tr>
<tr>
	<td><br /><?=oev_is_public() ? '' : '<a href="sessions.php">Session Reports</a>'?></td>
	<td colspan="2" style="text-align:right"><br><?=oev_is_admin() ? '<a href="settings.php">Settings</a>' : ''?></td>
</tr>
</table>
<script>
	var difference = (new Date() - new Date(<?=date('U')*1000;?>)) / 1000;
	if (Math.abs(difference) >= 300) { document.getElementById('td_time').style.backgroundColor='#ffa'; }
	// a load balancer note that doesn't fit next to the session is dropped (narrow phones)
	function fitNotes(){ document.querySelectorAll('dl.units dd').forEach(function(d){ var n = d.querySelector('.nt'), s = d.querySelector('.ss'); if (!n || n.textContent === '') return; n.style.display = ''; if (d.scrollWidth > d.clientWidth + 1 || (s && s.scrollWidth > s.clientWidth + 1)) n.style.display = 'none'; }); }
	fitNotes();
	// live update from the load balancer (lbstate.php, its heartbeat) every 5 s while the tab is visible
	(function(){
		function show(el, on){ if (el) el.style.display = on ? '' : 'none'; }
		function hhmm(t){ var d = new Date(t * 1000); return ('0' + (d.getMonth() + 1)).slice(-2) + '/' + ('0' + d.getDate()).slice(-2) + '\u00a0' + ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
		function apply(j){
			if (!j.ok) return;
			var tag = document.querySelectorAll('.lgh .lb');
			tag.forEach(function(t){
				if (!j.fresh) { t.className = 'lb down'; t.textContent = 'LB down'; t.title = 'Load balancer not running'; }
				else if (!j.mqtt) { t.className = 'lb warn'; t.textContent = 'LB slow'; t.title = 'Load balancer running, but its MQTT link is down'; }
				else { var lv = j.mode === 'live'; t.className = 'lb' + (lv ? ' live' : ''); t.textContent = 'LB ' + (lv ? 'on' : 'dry run'); t.title = 'Load balancer running'; }
			});
			if (!j.fresh) return;   // stale: leave the values read at page load
			j.groups.forEach(function(g){
				var h = document.querySelector('.lgh[data-g="' + g.id + '"] .a'); if (h) h.textContent = g.live_amps.toFixed(1) + ' / ' + g.limit + ' A';
				var w = document.querySelector('.lgwb[data-g="' + g.id + '"]');
				if (w) { w.innerHTML = ''; g.warn.forEach(function(x){ var d = document.createElement('div'); d.className = 'lgw'; d.textContent = x; w.appendChild(d); }); }
				g.units.forEach(upd);
			});
			(j.monitor || []).forEach(upd);
			function upd(u){
					var b = document.querySelector('.ub[data-u="' + u.id + '"]'); if (!b || !u.ok) return;
					var st = b.querySelector('dd .st'); st.className = 'st ' + u.pill_cls; st.title = u.pill_title; st.querySelector('i').textContent = u.pill;
					var pc = b.querySelector('dt .pc'); if (pc) { pc.textContent = u.label; pc.title = u.label_title; }
					show(b.querySelector('.pl'), u.plugged);
					var kw = b.querySelector('.kw'); if (kw) { var on = u.charging && u.amps !== null; if (on) kw.querySelector('i').innerHTML = (u.amps * parseFloat(b.dataset.v) / 1000).toFixed(1) + '&nbsp;kW'; show(kw, on); }
					var mq = b.querySelector('.mqx'); if (mq) { show(mq, u.stale); mq.title = u.checkin === null ? 'No MQTT check-in from this unit' : 'No MQTT check-in for ' + Math.round(u.checkin / 60) + 'm'; }
					var nt = b.querySelector('.nt'); if (nt) { nt.textContent = u.note; nt.title = u.why; show(nt, u.note !== ''); }
			}
			document.getElementById('asof').innerHTML = hhmm(j.time);
			fitNotes();
		}
		OEVLive.poll('lbstate.php', function(j){
			apply(j);
			if (!j.ok) OEVLive.dot('x', 'No load balancer');
			else OEVLive.light(j, 'Live: load balancer data ' + j.age + ' s old, updated every 5 s');
		});
	})();
</script>
</div></div>
<?=evse_rapi_stats_comment()?>
</body></html>
