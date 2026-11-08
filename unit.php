<?php
require_once __DIR__ . '/auth_lib.php'; oev_require('public');   // login gate (auth_lib.php)
include __DIR__ . '/include_v5.php';
require_once __DIR__ . '/util_lib.php';   // nm_local(), nm_web(): Unit Web Admin row
require_once __DIR__ . '/index_wide_lib.php';   // idx_temp_html(): the home page's temperature dot and thresholds
$with  = evse_with_param();
$wq    = $with ? '?with=' . implode(',', $with) : '';
$units = evse_units($with);
$unitid = isset($_GET['unitid']) ? $_GET['unitid'] : '';
$OEV_WIDE = 1; include __DIR__ . '/_head.php';
if (!ctype_digit((string)$unitid) || !isset($units[(int)$unitid]) || filter_var($units[(int)$unitid]['evseurl'], FILTER_VALIDATE_URL) === false) {
	die('missing or invalid unitid</div></body></html>');
}
$unitid = (int)$unitid;
$unit = $units[$unitid];
$self = 'unit.php?unitid=' . $unitid . ($with ? '&with=' . implode(',', $with) : '');
include_once __DIR__ . '/include_lb.php';
// load balancer state for the 5 s live update (all units, including monitored units outside a group); null if it isn't running
$lbhb = oev_filter_hb(evse_lb_heartbeat()); $lbu = null;
if (is_array($lbhb)) { $all = (array)($lbhb['monitor'] ?? array()); foreach ((array)$lbhb['groups'] as $g) foreach ((array)$g['units'] as $x) $all[] = $x; foreach ($all as $x) if ((int)$x['id'] === $unitid) $lbu = $x; }
$lbca = is_array($lbu) ? evse_lb_checkin_age($lbu) : null;
// fresh heartbeat only: idle LB pause shows as Paused on first paint (raw unit state stays Sleeping)
$lv = (is_array($lbhb) && !empty($lbhb['fresh']) && is_array($lbu)) ? evse_lb_view($lbu) : null;

// small POST button
// $small: true = text link, 'chip' = compact button floated right in a table cell
function btn($action, $label, $extra = array(), $confirm = '', $small = false, $title = ''){
	global $unitid, $with;
	if (!oev_can_control()) return '';   // view-only users and public links: no buttons
	$o = '<form class="inl" method="post" action="action.php"' . ($confirm ? ' onsubmit="return askForm(this, ' . h(json_encode($confirm)) . ')"' : '') . '>';
	$o .= '<input type="hidden" name="unitid" value="' . $unitid . '"><input type="hidden" name="with" value="' . h(implode(',', $with)) . '">';
	$o .= '<input type="hidden" name="action" value="' . h($action) . '">' . oev_csrf_field();
	foreach ($extra as $k => $v) $o .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
	if ($small === 'chip') return $o . '<button type="submit" class="chip"' . ($title !== '' ? ' title="' . h($title) . '"' : '') . '>' . h($label) . '</button></form>';
	if ($small) return $o . '<button type="submit" style="background:none;border:none;padding:0;cursor:pointer;text-decoration:underline;font-size:smaller;">' . h($label) . '</button></form> ';
	return $o . '<button type="submit"><a><span>' . h($label) . '</span></a></button></form> ';
}
?>
<script>var liveOK = false, loadedAt = Date.now();
// full reload every 60 s, or every 5 min while the live update (below) is working; never while a popup is open
function autoRefresh(){ var open = [].some.call(document.querySelectorAll('.slidecontainer'), function(x){ return x.style.display == 'block'; }); if (open || (liveOK && Date.now() - loadedAt < 300000)) setTimeout(autoRefresh, 10000); else location.href = <?=json_encode($self)?>; } setTimeout(autoRefresh, 60000);</script>
<div class="slidebox pgcard" id="ucard"><?php /* the page's card: the same rounded .slidebox as index.php (style.css); the popups below stay outside it */ ?>
<div style="text-align:center;">
	<form method="get" id="unit">
	Select Unit:
	<select name="unitid" onchange="document.forms['unit'].submit();">
	<?php foreach ($units as $u) { ?>
		<option value="<?=(int)$u['id']?>" <?= $u['id'] == $unitid ? 'selected' : '' ?>><?=h($u['name'])?></option>
	<?php } ?>
	</select><?php if ($with) { ?><input type="hidden" name="with" value="<?=h(implode(',', $with))?>"><?php } ?><?php if (($_GET['ws'] ?? '') === '0') { ?><input type="hidden" name="ws" value="0"><?php } ?>
	<a href="javascript:void(0);" id="b_speak" title="Speak status" aria-label="Speak status" style="text-decoration:none;font-size:20px;vertical-align:middle;margin-left:4px;">&#128266;</a></form>
<style>
form#unit{white-space:nowrap}
table.stats td.nw{white-space:nowrap}
table.stats td:first-child{white-space:nowrap}
form.inl button.chip{float:right;margin:-1px 0 -1px 6px;padding:0 6px;font-size:13px;line-height:19px;color:#000;background:#fff;border:1px solid #ccc;border-radius:2px}
form.inl button.chip:hover{background:#ccc}
/* Sleep/Wake and Reboot sit right-aligned in the label column so value cells keep their full width */
table.stats td:first-child form.inl button.chip{margin:-1px 0 -1px 8px}
/* 310px phones: tighter cells so the longest state (Error: Over Temperature + plug) still fits on one line */
@media (max-width:359px){table.stats td{padding:2px 3px;font-size:14px}table.stats td:first-child form.inl button.chip{margin-left:3px;padding:0 4px}}
.slidecontainer .slidebox .pt{font-weight:bold;margin-bottom:6px}
.slidecontainer .slidebox{box-sizing:border-box;max-width:calc(100vw - 24px);overflow-wrap:anywhere;word-wrap:break-word}
.slidecontainer .slidebox .pl{white-space:normal;line-height:1.4;margin-bottom:2px}
.slidecontainer .slidebox .slider{margin:2px 0}
.slidecontainer .slidebox .pn{margin-top:6px;font-size:85%;font-style:italic;color:#666}
.slidecontainer .slidebox .pclose{margin-top:8px;font-size:80%}
.slidecontainer .slidebox .pclose a{color:#999}
.slidecontainer .slidebox .pbtns{margin-top:10px}
.slidecontainer .slidebox button.pbtn{margin:4px 4px 0 4px;padding:1px 10px;font-size:13px;line-height:18px;color:#000;background:#fff;border:1px solid #bbb;border-radius:3px;cursor:pointer}
.slidecontainer .slidebox button.pbtn:hover{background:#ddd}
#pop_info .slidebox,#pop_ask .slidebox{max-width:300px}
/* every popup but Pilot (its slider needs the room) is exactly the stats table's width
   (300px, or the screen on 310px phones; popFit() sets the table's measured width when it opens) */
.slidecontainer:not(#setpilot) .slidebox{width:300px;max-width:calc(100vw - 24px);padding:10px 12px}
.slidecontainer .slidebox input,.slidecontainer .slidebox select{max-width:100%;box-sizing:border-box;font-size:14px}
.slidecontainer .slidebox form{margin:4px 0}
.slidecontainer .slidebox .yn{margin:4px 0 8px}
.slidecontainer .slidebox .yn select{max-width:100%}
.slidecontainer .slidebox .ynnow{font-size:85%;color:#666;white-space:nowrap}
.slidecontainer .slidebox .pf{display:inline-block;white-space:nowrap;margin:3px 2px}
.slidecontainer .slidebox .pf input[type=time]{width:7.5em}
.slidecontainer .slidebox .pf input[type=number]{width:4.5em}
.slidecontainer .slidebox .pf select{max-width:9em}
@media screen and (max-width:699px){#ucard{width:auto;max-width:none;margin:0 5px;padding:8px}}
.slidecontainer .slidebox .pf button.pbtn{margin:0 0 0 4px}
#pop_info .slidebox{text-align:left}
/* page card: index.php's card; the table keeps its own width (max 300px) centred in it */
#ucard table.stats{margin-top:7px;text-align:left;max-width:320px}
#ucard table.stats th{text-align:center}
/* desktop / Tesla (style.css zooms these to 150%): the table fills the card */
@media screen and (min-width:1024px){#ucard table.stats{max-width:none}}
/* phones under 360px: a little less card padding so the longest state still fits on one line */
@media (max-width:359px){#ucard{padding:10px 4px}}
table.stats tr.g td{border-top:2px solid #9a9a9a}
table.stats tr.g2 td{background-color:#e3e7ec}
/* readings: darker text, a little larger; the temperature dot as on the home page (index_wide.php) */
#ucard table.stats td{color:#222;font-size:16px}@media screen and (min-width:700px){#ucard table.stats td{font-size:17px}}#ucard table.stats td:first-child{color:#444}#ucard table.stats td a{color:inherit}
@media (max-width:359px){#ucard table.stats td{font-size:15px}}
.td{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px;vertical-align:-1px}.td.g{background:#2a2}.td.y{background:#e6b800}.td.o{background:#ef7d00}.td.r{background:#d22}.td.b{background:#1e6fd9}.td.ta{margin:0 0 0 5px;vertical-align:middle}
/* phones: the two tables read as one list */
#ucard table.stats{table-layout:fixed}#ucard table.stats th{width:auto}#ucard col.cn{width:42%}#ucard table.st2{margin-top:0}#ucard tr.r_head2{display:none}#ucard table.st2 tr:nth-child(2) td{border-top:2px solid #9a9a9a}
/* desktop: two name/value tables side by side; name columns only as wide as their text */
@media screen and (min-width:700px){
	#ucard .ustats{display:flex;gap:28px;align-items:flex-start}
	body.pgw #ucard{padding:12px 22px 20px 22px}#ucard form#unit{margin:12px 0}#ucard table.stats td{padding:4px 12px}
	#ucard .ustats table.stats{flex:1 1 0;min-width:0;max-width:none;margin:0}
	#ucard tr.r_head2{display:table-row}
	#ucard table.stats{table-layout:auto}#ucard table.stats th{width:auto}#ucard col.cn{width:33%}#ucard table.stats td{white-space:nowrap}
}
.lbdot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-left:5px;vertical-align:middle;background:#bbb}
.lbdot.g{background:#2a2}.lbdot.a{background:#e9a000}.lbdot.r{background:#d22}.lbdot.x{display:none}
table.stats td.vfade{transition:background-color 1.2s ease-out}
table.stats td.vfade.vchg{background-color:#fff8d1;transition:none}
/* Connected (s2) header is light lime (style.css gradient #9C0->#CF6, unchanged): text = home-page s2 pill text (index.php/index_wide.php .st.s2 color:#3d5208); other states keep white */
table.stats th.s2,table.stats th.s02{color:#3d5208}
</style>
<?php
$e = evse_status($unit);
if (!is_array($e)) die('Can\'t connect to ' . (oev_is_admin() ? h($unit['evseurl']) : 'the unit') . ', response code: ' . h($e) . '</div></div></div></body></html>');
$plugimg = $e['connected'] ? '<img src="media/plug.svg" alt="EV Connected" title="EV Connected" class="plug" /> ' : '';
// both buttons are on the page; the live update shows the one that fits the current state
$asl = evse_sleep_class($e['statecode']) == 'asleep';
$sleepbtn = '<span id="b_wake"' . ($asl ? '' : ' style="display:none"') . '>' . btn('wake', 'Wake', array(), '', 'chip') . '</span><span id="b_sleep"' . ($asl ? ' style="display:none"' : '') . '>' . btn('sleep', 'Sleep', array(), '', 'chip') . '</span>';
$upt = evse_uptime_info($unit, $e);
evse_unit_extras($unit, $e);   // WiFi signal, hostname, fault counts
$ls = evse_last_session($unit, false);   // previous completed session
$cur = evse_last_session($unit, true);   // in-progress session, if any
// combined Limits row: "None", "10 kWh", "2h 30m", or (2.8.2, both set) "10 kWh · 2h 30m"
$limparts = array();
if ($e['chargelimit'] === "\u{2014}") $limparts[] = "\u{2014}";
elseif ($e['chargelimit'] !== 'None' && $e['chargelimit'] !== '') $limparts[] = $e['chargelimit'];
if ($e['timelimit'] === "\u{2014}") $limparts[] = "\u{2014}";
elseif ($e['timelimit'] !== 'None' && $e['timelimit'] !== '') {
	$lm = (is_array($e['limit'] ?? null) && ($e['limit']['type'] ?? '') == 'time') ? (int)$e['limit']['value'] : (int)$e['timelimitcode'] * 15;
	$limparts[] = ($lm >= 60 ? intdiv($lm, 60) . 'h' . ($lm % 60 ? ' ' . ($lm % 60) . 'm' : '') : $lm . 'm');
}
$limtxt = $limparts ? implode(" \u{00B7} ", $limparts) : 'None';
$limkwh = (int)$e['chargelimit'];
$sbtn = '<button type="submit" class="pbtn">Save</button>';
// State popup lines (display only; raw dump stays one tap away)
$stl = array('State: ' . $e['state']);
if (!empty($e['status_text'])) $stl[] = 'Status: ' . $e['status_text'];
$stl[] = 'State code: ' . $e['statecode'];
$stl[] = 'Vehicle: ' . (!empty($e['connected']) ? 'connected' : 'not connected');
// Charge time: the unit's own elapsed counter (2.x $GS) keeps the previous charge's time until a new
// charge starts, so use the logger's open session (segment time); without a session, trust the unit
// only while it is charging; otherwise none
$ctsec = null;
if ($cur) $ctsec = (int)$cur['seg_seconds'];
elseif ((int)$e['statecode'] == 3 && !empty($e['elapsed_sec'])) $ctsec = (int)$e['elapsed_sec'];
$cttxt = $ctsec ? evse_elapsed_text($ctsec) : "\u{2014}";
$stl[] = 'Charge time: ' . $cttxt . ($cur ? ' (this session)' : '');
if ($e['api'] == 'http') {
	$stl[] = 'Manual override: ' . (!empty($e['manual_override']) ? 'yes' : 'no');
	$ovj = (is_array($e['override'] ?? null) && $e['override']) ? $e['override'] : null;
	if ($ovj) $stl[] = 'Override: ' . (isset($ovj['state']) ? $ovj['state'] : 'custom') . (isset($ovj['auto_release']) ? ($ovj['auto_release'] ? ' (clears on unplug)' : ' (stays after unplug)') : '');
}
$ckl = array();
$bl = evse_detect($unit)['config'] ?? null;   // boot lock (newer WiFi firmware only)
foreach (array('diode'=>'Diode','vent'=>'Vent required','ground'=>'Ground','relay'=>'Stuck relay','gfci'=>'GFCI','temp'=>'Temperature') as $k => $l) $ckl[] = strtoupper($l[0]) . ' - ' . $l . ': ' . (!empty($e['check_' . $k]) ? 'on' : 'OFF');
$ckl[] = 'Boot lock: ' . (!is_array($bl) || !array_key_exists('boot_lock', $bl) ? 'not supported by this WiFi firmware' : (!empty($bl['boot_lock']) ? 'On' : 'Off') . (!empty($unit['loadgroupid']) ? ' (managed by load balancer)' : ''));
// Time row: the collector keeps unit clocks on server time (clock_logic.php); this only reports it
$cs = evse_clock_state($unit);
$fs = function($s){ $s = (float)$s; return (abs($s) < 0.05 ? '0' : sprintf('%+.1f', $s)) . ' s'; };
if (!empty($e['ntp']) || ($cs && $cs['clock_ntp'])) $clk = array('This unit sets its own clock from NTP. The server checks it every 10 minutes.');
else $clk = array('The clock is synced with the server automatically: checked every 10 minutes and corrected when it is 1 second or more off.');
if ($cs) {
	$clk[] = 'Last check: ' . date('g:i a', (int)$cs['clock_checked_at']) . ($cs['clock_offset'] !== null ? ', ' . $fs($cs['clock_offset']) . ' vs server' : ', no reliable reading');
	$clk[] = 'Last sync: ' . ($cs['clock_synced_at'] ? date((date('Ymd', (int)$cs['clock_synced_at']) == date('Ymd') ? '' : 'n/j ') . 'g:i a', (int)$cs['clock_synced_at']) . ' (was ' . $fs($cs['clock_sync_from']) . ')' : 'none needed yet');
	if ($cs['clock_fail']) $clk[] = 'The last sync did not take; it will retry.';
	if ($cs['clock_note']) $clk[] = $cs['clock_note'];
} else $clk[] = 'Not checked yet.';
// highlight only when an automatic sync failed and the clock is still a few seconds off
// (before the first automatic check: the old > 1 min rule)
$clkhl = $cs ? ($cs['clock_fail'] && $e['clock_offset'] !== null && abs($e['clock_offset']) > 3) : !empty($e['clock_off']);
?>
</div>
<?php if (isset($_GET['msg'])) { ?><div style="position:relative;height:0;max-width:300px;margin:0 auto;"><div id="actmsg" style="position:absolute;left:0;right:0;top:9px;z-index:5;text-align:center;transition:opacity .6s;pointer-events:none;"><span style="display:inline-block;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:top;font-size:13px;line-height:18px;padding:1px 8px;color:#333;background:#fff;border:1px solid #ccc;border-radius:3px;box-shadow:0 1px 3px rgba(0,0,0,.25);" title="<?=h($_GET['msg'])?>"><?=h($_GET['msg'])?></span></div></div>
<script>
// one-time action result, floated over the table header (no layout jump): drop ?msg= so refresh doesn't show it again, fade out after 3 s
(function(){
	try { var u = new URL(location.href); u.searchParams.delete('msg'); history.replaceState(null, '', u.pathname + u.search + u.hash); } catch (e) {}
	var m = document.getElementById('actmsg');
	setTimeout(function(){ m.style.opacity = '0'; setTimeout(function(){ m.style.display = 'none'; }, 600); }, 3000);
})();
</script><?php } ?>
<div class="ustats"><table class="stats"><colgroup><col class="cn"><col></colgroup>
	<tr id="r_head" class="r_head"><th class="s<?=h($e['statecode'])?>">Name</th><th class="s<?=h($e['statecode'])?>">Value</th></tr>
	<?php $st_txt = ($lv && !empty($lv['paused'])) ? 'Paused' : $e['state']; $st_ttl = ($lv && !empty($lv['paused'])) ? $lv['pill_title'] : ($e['state_title'] ?? ''); ?>
	<tr><td><?=$sleepbtn?><a id="a_state" href="javascript:void(0);">State</a></td><td id="v_state" class="nw"<?=$st_ttl !== '' ? ' title="' . h($st_ttl) . '"' : ''?>><img src="media/plug.svg" alt="EV Connected" title="EV Connected" class="plug" id="v_plug"<?=$e['connected'] ? '' : ' style="display:none"'?> /><?=$e['connected'] ? ' ' : ''?><span id="v_st"><?=h($st_txt)?></span></td></tr>
	<?php if ($e['api'] == 'http') { ?>
	<tr><td>Override</td><?php
		// compact: "Sleep"/"Awake" (+ "*" if it persists past unplug), full override JSON in the tooltip
		$ov = (is_array($e['override']) && $e['override']) ? $e['override'] : null;
		$ovt = 'None';
		if ($ov) {
			$ovs = isset($ov['state']) ? $ov['state'] : '';
			$ovt = ($ovs == 'disabled') ? 'Sleep' : (($ovs == 'active') ? 'Awake' : ($ovs !== '' ? ucfirst($ovs) : 'Custom'));
			if (isset($ov['auto_release']) && !$ov['auto_release']) $ovt .= '*';
		}
			// "Back to schedule" only matters if the unit has a schedule; otherwise Wake already clears the override
		$hassched = is_array($e['schedule'] ?? null) && count($e['schedule']) > 0;
	?><td class="nw"><?php if ($ov) { if ($hassched) echo str_replace('class="chip">', 'class="chip" title="Back to schedule">', btn('clear_override', "\u{21A9} Schedule", array(), '', 'chip')); ?><span title="<?=h(json_encode($ov) . (isset($ov['auto_release']) && !$ov['auto_release'] ? ' (stays after unplug)' : ' (clears on unplug)'))?>"><?=h($ovt)?></span><?php } else echo 'None'; ?></td></tr>
	<?php } ?>
	<tr><td><a id="a_pilot" href="javascript:void(0);">Pilot</a></td><td id="v_pilot" class="nw"><?php $psv = ($e['api'] == 'http') ? ($e['max_current_soft'] ?? null) : ($e['saved_current'] ?? null);
		echo $e['pilot'] === null ? "\u{2014}" : h(evse_amps_label($e['pilot'], $psv)) . (($psv !== null && (int)$e['pilot'] < (int)$psv) ? ' / ' . (int)$psv . ' A' : '') . ' &middot; ' . round($e['pilot'] * $e['volts'] * 0.001, 1) . ' kW'; ?></td></tr>
	<tr><td>Current</td><td id="v_amps"><?=$e['amps'] === null ? "\u{2014}" : h($e['amps']) . ' amps'?></td></tr>
	<tr><td>Voltage</td><td class="nw"<?=!empty($e['volts_src']) ? ' title="' . h(str_replace(array('L1 assumed', 'L2 assumed'), 'assumed for the service level', $e['volts_src'])) . '"' : ''?>><?=h($e['volts'])?> volts</td></tr>
	<tr><td>Power</td><td id="v_power"><?=$e['power'] === null ? "\u{2014}" : h($e['power']) . ' kW'?></td></tr>
	<tr><td>Energy</td><td><?=$e['energy'] === null ? "\u{2014}" : h($e['energy']) . ' kWh'?></td></tr>
	<tr><td>Charge Time</td><td><?=($cur && $ctsec && !oev_is_public()) ? '<a href="sessions.php?id=' . (int)$cur['id'] . '" title="Current session report">' . h($cttxt) . '</a>' : h($cttxt)?></td></tr>
	<tr><td>Temp</td><td class="nw"><?=is_numeric($e['temp']) ? idx_temp_html(((float)$e['temp'] - 32) * 5 / 9, true) : temp_txt_f($e['temp'])?></td></tr>
	<tr><td>Last check-in</td><td id="v_seen" class="nw" title="When this unit last checked in (MQTT or HTTP). The light: green = live, updated every 5 s; amber = late; red = load balancer down (values from page load)"><span id="v_age"><?=$lbca === null ? "\u{2014}" : h($lbca < 120 ? $lbca . ' s ago' : ($lbca < 7200 ? round($lbca / 60) . ' min ago' : round($lbca / 3600, 1) . ' h ago'))?></span><span id="lbdot" class="lbdot <?=is_array($lbhb) && $lbu ? ($lbhb['fresh'] ? 'g' : 'r') : 'x'?>"></span></td></tr>
</table><table class="stats st2"><colgroup><col class="cn"><col></colgroup><?php /* desktop: settings and info beside the live readings; phones: one list */ ?>
	<tr class="r_head r_head2"><th class="s<?=h($e['statecode'])?>">Name</th><th class="s<?=h($e['statecode'])?>">Value</th></tr>
	<tr class="g g2"><td><a id="a_timer" href="javascript:void(0);">Start / Stop</a></td><td><?=h($e['timerstart'] . ' / ' . $e['timerend'])?></td></tr>
	<tr class="g2"><td><a id="a_limits" href="javascript:void(0);">Limits</a></td><td class="nw"><?=h($limtxt)?></td></tr>
	<tr class="g"><td><a id="a_level" href="javascript:void(0)">Level</a></td><td><?=$e['level'] === null ? "\u{2014}" : 'L' . h($e['level']) . ' (' . h($e['autolevel']) . ')'?></td></tr>
	<tr><td><a id="a_checks" href="javascript:void(0);">Safety Checks</a></td><td><?php
		if (array_key_exists('check_diode', $e) && $e['check_diode'] === null) echo "\u{2014}";   // $GE not confirmed
		else foreach (array('diode'=>'D','vent'=>'V','ground'=>'G','relay'=>'R','gfci'=>'F','temp'=>'T') as $k => $l)
			echo $e['check_' . $k] ? $l : '<span class="err">' . strtolower($l) . '</span>';
	?></td></tr>
	<tr><td><a id="a_time" href="javascript:void(0);">Time</a></td><?php
		// clock vs server time: highlight only, details in the tooltip
		$tt = array();
		if ($clkhl) $tt[] = 'Unit clock is ' . evse_offset_text($e['clock_offset']) . ' from server time';
		elseif (array_key_exists('clock_offset', $e) && $e['clock_offset'] === null) $tt[] = 'Clock not checked (no reply)';
		if (!empty($e['ntp'])) $tt[] = 'Unit syncs from NTP';
	?><td id="td_time" style="white-space:nowrap<?= $clkhl ? ';background-color:#ffa' : '' ?>"<?= $tt ? ' title="' . h(implode('. ', $tt)) . '"' : '' ?>><?=h($e['time'])?></td></tr>
	<tr><td><?=btn('restart', 'Reboot', array(), 'This will reboot your EVSE (the controller, then the WiFi module). It is offline for about a minute. Are you sure?', 'chip', 'Restarts both the controller and the WiFi module')?>Uptime</td><td class="nw"><span title="<?=h($upt['title'])?>"><?=h($upt['text'])?></span></td></tr>
	<?php if (!oev_is_public()) { /* view-only link: live status only, no history */ ?><tr><td><a id="a_faults" href="javascript:void(0);">Lifetime</a></td><td><?=$e['lifetimeKWH'] === null ? "\u{2014}" : round($e['lifetimeKWH'] / 1000, 2) . ' MWh'?></td></tr>
	<tr><td>Last Session</td><td class="nw"><?php
		if ($ls) {
			$lsk = ($ls['kwh'] !== null && (float)$ls['kwh'] > 0) ? $ls['kwh'] : $ls['kwh_meter'];
			echo '<a href="sessions.php?id=' . (int)$ls['id'] . '" title="' . h(date('D M j g:ia', (int)$ls['plug_in_at'])) . '">' . h(date('n/j', (int)$ls['plug_in_at'])) . '</a>' . ($lsk !== null ? ' &middot; ' . h(round($lsk, 1)) . ' kWh' : '');
			// temperature derate during that session: orange from 65 C (half current), red from 71 C or an over-temperature fault
			$lsm = max((float)($ls['max_temp_c'] ?? -99), (float)($ls['unit_temp_max_c'] ?? -99)); $lsf = (int)($ls['ot_faults'] ?? 0);
			if ($lsf || $lsm >= IDX_THROTTLE_C) {
				$lst = 'Reached ' . round($lsm) . "\u{00B0}C (" . round($lsm * 9 / 5 + 32) . "\u{00B0}F)" . ' during this session; ' . (($lsf || $lsm >= IDX_OVERTEMP_C) ? 'the unit went over temperature and stopped charging until it cooled.' : ($lsm >= IDX_QUARTER_C ? 'the unit cut current to a quarter to cool down.' : 'the unit cut current to half to cool down.'));
				echo ' <span class="td ' . (($lsf || $lsm >= IDX_OVERTEMP_C) ? 'r' : 'o') . ' ta" title="' . h($lst) . '"></span>';
			}
		} else echo "\u{2014}";
	?></td></tr><?php } ?>
	<tr><td>Firmware / <a id="a_wifi" href="javascript:void(0);">WiFi</a></td><td><?=h($e['firmware'])?> &nbsp;/&nbsp; <?=h($e['version'])?> <span class="api"><?=h($e['api'])?></span></td></tr>
<?php if (!oev_is_public()) { ?><tr><td>Unit Web Admin</td><td class="nw"><?php $uw = nm_web($unit['evseurl']); echo (oev_is_admin() && $uw !== '' && nm_local($_SERVER['REMOTE_ADDR'] ?? '', (string)parse_url($unit['evseurl'], PHP_URL_HOST))) ? '<a href="' . h($uw) . '" target="_blank" rel="noopener noreferrer">' . h($uw) . '</a>' : '<span class="api" title="Admins on the units\' own network only">Unavailable</span>'; ?></td></tr><?php } ?>
<?php // Your notifications: this login's own emails for this unit (notify_prefs.php saves; any login that sees the unit, never a view-only link)
$YN = null;
if (!oev_is_public() && oev_user()) { try { $ydb = oev_db(); if (oev_unit_notify_have($ydb)) $YN = oev_unit_notify_view($ydb, oev_user(), array($unitid => $unit))[$unitid]; } catch (Exception $x) { $YN = null; } }
if ($YN) { $ynk = oev_unit_notify_kinds(); ?><tr id="yn"><td><a id="a_yn" href="javascript:void(0);">Your notifications</a></td><td><?php
	$p = array(); foreach ($ynk as $k => $t) $p[] = ($k === 'sessions' ? 'Summary' : 'Stopped') . ' ' . ($YN[$k]['on'] ? 'On' : 'Off'); echo h(implode(' · ', $p)); ?></td></tr><?php } ?>
</table></div>
</div><?php /* #ucard */ ?>

<?php
$pmin = isset($e['min_current_hard']) ? (int)$e['min_current_hard'] : 6;
$pmax = (int)$unit['breaker'];
if (!empty($e['max_current_hard'])) $pmax = min($pmax ?: 80, (int)$e['max_current_hard']);
// the popup sets the SAVED current (2.x $SC N, v5 config max_current_soft)
$pcur = ($e['api'] == 'http') ? ($e['max_current_soft'] ?? $e['pilot']) : ($e['saved_current'] ?? $e['pilot']);
$inlg = !empty($unit['loadgroupid']);
$tlopts = array(0=>'None',1=>'15 minutes',2=>'30 minutes',3=>'45 minutes',4=>'60 minutes',6=>'1.5 hours',8=>'2 hours',10=>'2.5 hours',12=>'3 hours',16=>'4 hours',20=>'5 hours',24=>'6 hours',28=>'7 hours',32=>'8 hours');
$ts = $e['timerstart'] != 'None' ? $e['timerstart'] : ''; $te = $e['timerend'] != 'None' ? $e['timerend'] : '';
$hidden = '<input type="hidden" name="unitid" value="' . $unitid . '"><input type="hidden" name="with" value="' . h(implode(',', $with)) . '">' . oev_csrf_field();
$CAN = oev_can_control();   // false: view only (no popups that change things)
?>
<div id="setpilot" class="slidecontainer" onclick="closePop(this);"><div class="slidebox" onclick="event.stopPropagation();">
	<div class="pt">Saved current</div><form method="post" action="action.php"><?=$hidden?><input type="hidden" name="action" value="current">
	<span>Saved:</span> <span id="pilotValue" style="font-weight:bold;color:red"><?=h($pcur)?></span> A &middot; <span id="pilotkW"></span> kW<br /><br />
	<input type="range" min="<?=$pmin?>" max="<?=$pmax?>" value="<?=h($pcur)?>" class="slider" id="pilotRange" name="amps" /><br />
	<?=$sbtn?></form><div class="pn">Saved in the unit (kept after a restart). This is the most this unit will charge at, up to its Max current in Settings (<?=(int)$unit['breaker']?> A).<?=$inlg ? ' In a load group, the balancer may set a lower temporary value, shown in parentheses.' : ''?></div><div class="pclose"><a href="javascript:void(0);" onclick="closePop(this)">Close</a></div>
</div></div>
<div id="settimer" class="slidecontainer" onclick="closePop(this);"><div class="slidebox" onclick="event.stopPropagation();">
	<div class="pt">Start / Stop schedule</div><form method="post" action="action.php"><?=$hidden?><input type="hidden" name="action" value="timer">
	<span class="pf">Start: <input type="time" name="start" value="<?=h($ts)?>" /></span> <span class="pf">End: <input type="time" name="end" value="<?=h($te)?>" /></span><br />
	<?=$sbtn?></form>
	<div class="pn">Clear both times to turn the schedule off. Runs every day.</div><div class="pclose"><a href="javascript:void(0);" onclick="closePop(this)">Close</a></div>
</div></div>
<div id="setlimits" class="slidecontainer" onclick="closePop(this);"><div class="slidebox" onclick="event.stopPropagation();">
	<div class="pt">Limits</div>
	<form method="post" action="action.php"><?=$hidden?><input type="hidden" name="action" value="energylimit">
	Energy (kWh, 0 = none):<br /><span class="pf"><input type="number" name="value" min="0" max="255" step="1" value="<?=$limkwh?>" /> <?=$sbtn?></span></form>
	<form method="post" action="action.php"><?=$hidden?><input type="hidden" name="action" value="timelimit">
	Time:<br /><span class="pf"><select name="code"><?php foreach ($tlopts as $k => $v) echo '<option value="' . $k . '"' . ($e['timelimitcode'] == $k ? ' selected' : '') . '>' . $v . '</option>'; ?></select> <?=$sbtn?></span></form>
	<?php if ($e['api'] == 'http') { ?><div class="pn">WiFi v5 keeps one limit at a time: saving one replaces the other.</div><?php } ?>
	<div class="pclose"><a href="javascript:void(0);" onclick="closePop(this)">Close</a></div>
</div></div>
<div id="pop_info" class="slidecontainer" onclick="closePop(this);"><div class="slidebox" onclick="event.stopPropagation();"><div class="pt"></div><div class="pc"></div><div class="px"></div><div class="pclose"><a href="javascript:void(0);" onclick="closePop(this)">Close</a></div></div></div>
<div id="pop_ask" class="slidecontainer" onclick="closePop(this);"><div class="slidebox" onclick="event.stopPropagation();"><div class="pt"></div><div class="pc"></div><div class="pbtns"><button type="button" class="pbtn" id="ask_ok">OK</button><button type="button" class="pbtn" onclick="closePop(this)">Cancel</button></div></div></div>
<div id="setlevel" class="slidecontainer" onclick="closePop(this);"><div class="slidebox" onclick="event.stopPropagation();">
	<div class="pt">Service level</div>
	<div class="pn" style="margin-top:0">This setting should not normally need to be adjusted.</div>
	<form method="post" action="action.php"><?=$hidden?><input type="hidden" name="action" value="level">
	<span class="pf">Level: <select name="value"><?php foreach (array('A'=>'Auto', '1'=>'L1', '2'=>'L2') as $k => $v) echo '<option value="' . $k . '"' . ((!empty($e['autolevel']) ? 'A' : (string)$e['level']) === (string)$k ? ' selected' : '') . '>' . $v . '</option>'; ?></select> <?=$sbtn?></span></form><div class="pclose"><a href="javascript:void(0);" onclick="closePop(this)">Close</a></div>
</div></div>
<?php if ($YN) { ?><div id="setyn" class="slidecontainer" onclick="closePop(this);"><div class="slidebox" onclick="event.stopPropagation();">
	<div class="pt">Your notifications</div>
	<form method="post" action="notify_prefs.php"><?=$hidden?>
	<?php foreach ($ynk as $k => $t) { $c = $YN[$k]; ?>
	<div class="yn"><label for="yn_<?=$k?>"><?=h($t)?></label><br /><select id="yn_<?=$k?>" name="n_<?=$k?>">
		<option value="inherit"<?=$c['mode'] === 'inherit' ? ' selected' : ''?>>Use account default (<?=$c['account_on'] ? 'On' : 'Off'?>)</option>
		<option value="on"<?=$c['mode'] === 'on' ? ' selected' : ''?>>On</option>
		<option value="off"<?=$c['mode'] === 'off' ? ' selected' : ''?>>Off</option>
	</select> <span class="ynnow">Now: <?=$c['on'] ? 'On' : 'Off'?></span></div>
	<?php } ?>
	<?=$sbtn?></form>
	<div class="pn">Just for you and just this unit. Session summary: email when unplugged. Charging stopped: email when charging stops while still plugged in (not load-balancer pauses).<?=oev_setting('notify_on', '1') ? '' : ' Emails are turned off for the whole site right now, so nothing is sent.'?></div>
	<div class="pclose"><a href="notifications.php">All your notifications</a> &middot; <a href="javascript:void(0);" onclick="closePop(this)">Close</a></div>
</div></div><?php } ?>
<form id="f_prompt" method="post" action="action.php" style="display:none"><?=$hidden?><input type="hidden" name="action" id="p_action"><input type="hidden" name="value" id="p_value"></form>

<script>
var slider = document.getElementById("pilotRange"), output = document.getElementById("pilotValue"), pilotkW = document.getElementById("pilotkW");
function upd(){ output.innerHTML = slider.value; pilotkW.innerHTML = (slider.value * <?=(int)$e['volts']?> / 1000).toFixed(1); }
upd(); slider.oninput = upd;
function post(action, value){ document.getElementById('p_action').value = action; document.getElementById('p_value').value = value; document.getElementById('f_prompt').submit(); }
// shared in-page popups (no alert/confirm/prompt)
// popups (not Pilot) are exactly the stats table's width; Pilot is never narrower than it (CSS caps at 300px as a fallback)
function popFit(c){ var t = document.querySelector('table.stats'), b = c.querySelector('.slidebox'); if (!t || !b) return; if (c.id != 'setpilot') { b.style.width = t.offsetWidth + 'px'; b.style.maxWidth = t.offsetWidth + 'px'; } else b.style.minWidth = Math.min(t.offsetWidth, window.innerWidth - 16) + 'px'; }
function openPop(id){ var c = document.getElementById(id); c.style.display = 'block'; popFit(c); }
function closePop(el){ while (el && !(el.classList && el.classList.contains('slidecontainer'))) el = el.parentNode; if (el) el.style.display = 'none'; }
function fill(box, title, lines){ box.querySelector('.pt').textContent = title; var c = box.querySelector('.pc'); c.innerHTML = ''; lines.forEach(function(t){ var d = document.createElement('div'); d.className = 'pl'; d.textContent = t; c.appendChild(d); }); }
function pop(title, lines, link){ var b = document.getElementById('pop_info'); fill(b, title, lines); var x = b.querySelector('.px'); x.innerHTML = ''; x.className = 'px'; if (link) { var a = document.createElement('a'); a.href = link[1]; a.textContent = link[0]; x.className = 'px pclose'; x.appendChild(a); } b.style.display = 'block'; popFit(b); }
function ask(title, lines, ok){ var b = document.getElementById('pop_ask'); fill(b, title, lines); b.querySelector('#ask_ok').onclick = function(){ b.style.display = 'none'; ok(); }; b.style.display = 'block'; popFit(b); }
function askForm(f, msg){ ask('Confirm', [msg], function(){ if (f.elements.action && f.elements.action.value === 'restart') sentMsg('Reboot command sent\u2026 waiting for the unit'); f.submit(); }); return false; }
// immediate note at the top while a slow action (reboot) runs; the result message replaces it when the page comes back
function sentMsg(t){ var d = document.createElement('div'); d.id = 'sentmsg'; d.style.cssText = 'position:fixed;left:0;right:0;top:9px;z-index:50;text-align:center;pointer-events:none;'; var s = document.createElement('span'); s.style.cssText = 'display:inline-block;font-size:13px;line-height:18px;padding:1px 8px;color:#333;background:#fff;border:1px solid #ccc;border-radius:3px;box-shadow:0 1px 3px rgba(0,0,0,.25);'; s.textContent = t; d.appendChild(s); document.body.appendChild(d); }
// a value that changes (live feed or 5 s poll) flashes pale yellow and fades out (~1.2 s); Last check-in excluded
(function(){
	var cells = [].filter.call(document.querySelectorAll('table.stats td:nth-child(2)'), function(c){ return c.id !== 'v_seen'; });
	cells.forEach(function(c){ c._t = c.textContent; c.classList.add('vfade'); new MutationObserver(function(){
		if (c.textContent === c._t) return; c._t = c.textContent;
		clearTimeout(c._h); c.classList.add('vchg'); c._h = setTimeout(function(){ c.classList.remove("vchg"); }, 100);   // full colour for 0.1 s, then a 1.2 s fade
	}).observe(c, {childList: true, characterData: true, subtree: true}); });
})();
// WebSocket feed (on by default; ?ws=0 turns it off, below): per value, when the feed last sent it; none = never
var lbAge = null, seenAt = 0, wsState = 'connecting', wsLast = 0, tip = function(){}, wsAt = {}, lbPaused = <?=($lv && !empty($lv['paused'])) ? 'true' : 'false'?>; function wsFresh(k){ if (k === '') { for (var x in wsAt) if (Date.now() - wsAt[x] < 15000) return true; return false; } return !!wsAt[k] && Date.now() - wsAt[k] < 15000; }
// live update from the load balancer (lbstate.php?unit=N, read only) every 5 s while the tab is visible:
// state, current, power, pilot setting and last check-in; everything else stays as loaded.
// Same light as the home page: green live, amber late or a failed fetch, red load balancer down (values from page load)
(function(){
	var V = <?=(int)$e['volts']?>, dot = function(c, t){ OEVLive.dot(c, t); }, pageAt = <?=time()?>;   // this page read the unit itself at pageAt
	if (document.getElementById('lbdot').className.indexOf(' x') >= 0) return;   // no load balancer data for this unit
	function $(id){ return document.getElementById(id); }
	function ago(s){ return s === null ? '\u2014' : (s < 120 ? s + ' s ago' : (s < 7200 ? Math.round(s / 60) + ' min ago' : (s / 3600).toFixed(1) + ' h ago')); }
	function num(x){ return String(Math.round(x * 100) / 100); }
	function apply(j){
		var u = j.u; liveOK = false; lbAge = j.age; if (u && u.checkin !== null && u.checkin !== undefined) seenAt = Date.now() - u.checkin * 1000;
		if (!j.ok || !u) { dot('x', ''); return; }
		if (!j.fresh) { dot('r', 'Load balancer down: values from page load'); return; }
		if (!u.ok) { dot('a', 'The load balancer has no reply from this unit: values from page load'); return; }
		liveOK = true; lbPaused = !!u.paused;
		// the load balancer's last read of this unit is older than this page's own read (e.g. right after a save): keep the page's state and pilot until it reads again
		var newer = u.read !== null && u.read !== undefined && j.now - u.read >= pageAt;
		if (newer) {
		if (!wsFresh('state') || u.paused) {
		var st = $('v_st'); st.textContent = u.pill; $('v_state').title = u.pill_title;
		document.querySelectorAll('.r_head th').forEach(function(th){ th.className = u.paused ? 's254' : u.pill_cls; });
		var asl = u.code == 254 || u.code == 255; $('b_wake').style.display = asl ? '' : 'none'; $('b_sleep').style.display = asl ? 'none' : '';
		}
		if (!wsFresh('vehicle')) $('v_plug').style.display = u.plugged ? '' : 'none';
		if (u.label && !wsFresh('pilot')) { var t = u.label + ((u.saved !== null && u.setting < u.saved) ? ' / ' + u.saved + ' A' : '') + ' \u00b7 ' + (u.setting * V / 1000).toFixed(1) + ' kW'; $('v_pilot').textContent = t; $('v_pilot').title = u.label_title; }
		}
		if (u.amps !== null && !wsFresh('amp')) { $('v_amps').textContent = num(u.amps) + ' amps'; $('v_power').textContent = (Math.round(u.amps * V / 100) / 10) + ' kW'; }
		$('v_age').textContent = ago(u.checkin);
		if (u.stale) dot('a', 'No check-in for ' + ago(u.checkin).replace(' ago', ''));
		else if (j.age > 15) dot('a', 'Load balancer data is ' + j.age + ' s old');
		else dot('g', 'Live: updated every 5 s');
	}
	OEVLive.poll('lbstate.php?unit=<?=$unitid?>', apply, function(){ liveOK = false; })();
})();
<?php if (($_GET['ws'] ?? '1') !== '0' && is_file(__DIR__ . '/ws_lib.php')) { require_once __DIR__ . '/ws_lib.php'; if (oev_ws_capable($unit, $e['version'] ?? '')) { ?>
// Live feed (default; ?ws=0 off; units that passed the /ws check in ws_lib.php): every value the unit's WebSocket sends is shown
// as it arrives (relayed by ws_bridge.php); the 5 s poll above fills the rest, and takes a value back once the feed
// hasn't sent it for 15 s. EventSource reconnects by itself every 25 s.
(function(){
	var V = <?=(int)$e['volts']?>, SAVED = <?=json_encode(isset($psv) && $psv !== null ? (int)$psv : null)?>, CUR = <?=$cur ? 'true' : 'false'?>;
	var es, d = document.getElementById('lbdot'), n = 0, retry = null; var lastState = <?=(int)$e['statecode']?>;
	function $(id){ return document.getElementById(id); }
	function row(label){ var c = null; document.querySelectorAll('table.stats tr').forEach(function(tr){ var t = tr.cells[0]; if (t && !c && t.textContent.trim() === label) c = tr.cells[1]; }); return c; }
	function num(x){ return String(Math.round(x * 100) / 100); }
	var cV = row('Voltage'), cE = row('Energy'), cC = row('Charge Time'), cT = row('Temp');
	function again(ms){ if (!retry) retry = setTimeout(function(){ retry = null; start(); }, ms); }   // closed for good (non-200 answer): a new feed after ms
	function start(){
		es = new EventSource('ws_bridge.php?unit=<?=$unitid?>');
		es.addEventListener('u', function(m){ var j = JSON.parse(m.data), now = Date.now(); n++;
		for (var k in j) wsAt[k] = now;
		if (j.state !== undefined && !lbPaused) { $('v_st').textContent = j.state_text; $('v_state').title = j.state_text;
			document.querySelectorAll('.r_head th').forEach(function(th){ th.className = j.state_cls; });
			var asl = j.state == 254 || j.state == 255; if ($('b_wake')) $('b_wake').style.display = asl ? '' : 'none'; if ($('b_sleep')) $('b_sleep').style.display = asl ? 'none' : ''; }
		if (j.vehicle !== undefined) $('v_plug').style.display = j.vehicle ? '' : 'none';
		if (j.pilot !== undefined && j.pilot > 0) { var lbl = (SAVED !== null && j.pilot < SAVED) ? '(' + j.pilot + ' A) / ' + SAVED + ' A' : j.pilot + ' A'; $('v_pilot').textContent = lbl + ' \u00b7 ' + (j.pilot * V / 1000).toFixed(1) + ' kW'; }
		if (j.amp !== undefined) { var a = j.amp / 1000; $('v_amps').textContent = num(a) + ' amps'; $('v_power').textContent = (Math.round(a * V / 100) / 10) + ' kW'; }
		if (j.voltage !== undefined && cV && (j.state === 3 || (wsAt.state && lastState === 3)) && j.voltage >= 90 && j.voltage <= 260) cV.textContent = Math.round(j.voltage) + ' volts';
		if (j.state !== undefined) lastState = j.state;
		if (j.energy_text !== undefined && cE) cE.textContent = j.energy_text;
		if (j.elapsed_text !== undefined && cC && !CUR && lastState === 3) cC.textContent = j.elapsed_text;
		if (j.temp_html && cT) cT.innerHTML = j.temp_html;
		if (j.srssi !== undefined) $('a_wifi').title = 'Signal: ' + j.srssi + ' dBm (live)';
		wsState = 'live'; wsLast = now; d.style.boxShadow = 'inset 0 0 0 2px #0077dd'; tip(); });
		es.addEventListener('connected', function(){ wsAt[''] = Date.now(); wsState = 'live'; d.style.boxShadow = 'inset 0 0 0 2px #0077dd'; tip(); });   // handshake done: ring at once
		es.addEventListener('fail', function(){ es.close(); wsState = 'failed'; d.style.boxShadow = 'inset 0 0 0 2px #e9a000'; tip(); again(300000); });   // unit's /ws failed: 5 s poll only (re-checked within the hour)
		es.onerror = function(){ if (wsState === 'failed') return; if (es.readyState === 2) { d.style.boxShadow = ''; wsState = 'reconnecting'; tip(); again(15000); } else if (!wsFresh('')) { d.style.boxShadow = ''; wsState = 'reconnecting'; tip(); } };
	}
	start();
})();
<?php } else { ?>
wsState = 'unsupported';
<?php } ?>
// ?ws=1: one owner of the dot's tooltip: how the unit connection is going (OEVLive.dot sets only the colour here)
(function(){
	var d = document.getElementById('lbdot'), set = OEVLive.dot;
	OEVLive.dot = function(cls, t){ set(cls, t); tip(); };
	function secs(ms){ var s = Math.max(0, Math.round(ms / 1000)); return s < 120 ? s + ' s' : Math.round(s / 60) + ' min'; }
	window.tip = function(){   // two lines: how the unit connection is going, then its detail
		var q = seenAt ? 'Last unit check-in ' + secs(Date.now() - seenAt) + ' ago' : '', f;
		if (wsState === 'live') { f = 'Live: updating instantly from the unit'; q = wsLast ? 'Last update ' + secs(Date.now() - wsLast) + ' ago' : 'Connected, no changes yet'; }
		else if (wsState === 'connecting') f = 'Live feed connecting; updating every 5 seconds';
		else if (wsState === 'reconnecting') f = 'Live feed reconnecting; updating every 5 seconds';
		else if (wsState === 'failed') f = 'Live feed stopped (the unit did not accept it); updating every 5 seconds';
		else f = 'Updating every 5 seconds';
		d.title = f + (q ? '\n' + q : '');
	};
	tip(); setInterval(tip, 1000);
})();
<?php } ?>
document.getElementById('a_state').onclick = function(){ pop('State', <?=json_encode($stl)?>, <?=oev_is_public() ? 'null' : "['Raw status dump', " . json_encode('dump.php?unitid=' . $unitid . '&with=' . implode(',', $with)) . ']'?>); }
<?php if ($CAN) { ?>
document.getElementById('a_pilot').onclick = function(){ openPop('setpilot'); }
document.getElementById('a_timer').onclick = function(){ openPop('settimer'); }
document.getElementById('a_limits').onclick = function(){ openPop('setlimits'); }
document.getElementById('a_level').onclick = function(){ openPop('setlevel'); }
<?php } else { $vo = 'View only: changes need an admin login.'; ?>
document.getElementById('a_pilot').onclick = function(){ pop('Saved current', [<?=json_encode('Saved: ' . $pcur . ' A')?>, <?=json_encode($vo)?>]); }
document.getElementById('a_timer').onclick = function(){ pop('Start / Stop schedule', [<?=json_encode('Start: ' . ($ts ?: 'none') . ', stop: ' . ($te ?: 'none'))?>, <?=json_encode($vo)?>]); }
document.getElementById('a_limits').onclick = function(){ pop('Limits', [<?=json_encode('Limits: ' . $limtxt)?>, <?=json_encode($vo)?>]); }
document.getElementById('a_level').onclick = function(){ pop('Service level', [<?=json_encode('Level: ' . ($e['level'] === null ? '-' : 'L' . $e['level']))?>, <?=json_encode($vo)?>]); }
<?php } ?>
<?php if ($YN) { ?>document.getElementById('a_yn').onclick = function(){ openPop('setyn'); }
if (location.hash === '#yn') openPop('setyn');   // from Your notifications (notifications.php)
<?php } ?>document.getElementById('a_time').onclick = function(){ pop('Clock', <?=json_encode($clk)?>); }
document.getElementById('a_checks').onclick = function(){ pop('Safety checks', <?=json_encode($ckl)?>); }
document.getElementById('a_wifi').onclick = function(){ pop('WiFi', <?=json_encode(array_values(array_filter(array('SSID: ' . $e['ssid'], oev_is_admin() ? 'IP address: ' . $e['ipaddress'] : null, (oev_is_admin() && $e['hostname'] !== '') ? 'Hostname: ' . $e['hostname'] : null, $e['srssi'] !== null ? 'Signal: ' . $e['srssi'] . ' dBm' : null, 'API: ' . $e['api']))))?>); }
if (document.getElementById('a_faults')) document.getElementById('a_faults').onclick = function(){ pop('Fault counts (since reset)', <?=json_encode(array('GFCI: ' . (int)($e['gfcicount'] ?? 0), 'No ground: ' . (int)($e['nogndcount'] ?? 0), 'Stuck relay: ' . (int)($e['stuckcount'] ?? 0)))?>); }
window.speechSynthesis.onvoiceschanged = function(){ window.speechSynthesis.getVoices(); };
document.getElementById('b_speak').onclick = function(){
	var msg = new SpeechSynthesisUtterance(<?=json_encode(isset($e['status']) ? $e['status'] : '')?>);
	msg.lang = 'en-US'; msg.rate = 0.9; msg.pitch = 1; msg.volume = 0.5;
	var v = speechSynthesis.getVoices().find(function(x){ return x.name === 'Google US English'; }); if (v) msg.voice = v;
	speechSynthesis.speak(msg);
}
</script>
</div>
<?=evse_rapi_stats_comment()?>
</body></html>
