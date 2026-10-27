<?php
// Load balancer detail page: coordinator health, per-unit reasons and recent changes, settings, filterable log.
// Updates in place every 5 s (lbstate.php?detail=1) while the tab is visible. Read-only.
require_once __DIR__ . '/auth_lib.php'; oev_require('view');   // login gate (auth_lib.php); detail + log: not for view-only links
include __DIR__ . '/include_v5.php';
include __DIR__ . '/include_lb.php';
include __DIR__ . '/lbstatus_render.php';
$title = 'Load balancer'; $wq = '';
include __DIR__ . '/_head.php';
$hb = oev_filter_hb(evse_lb_heartbeat());
$units = array(); if (is_array($hb)) foreach ((array)$hb['groups'] as $g) foreach ((array)$g['units'] as $u) $units[(int)$u['id']] = preg_replace('/^.*?:\s*/', '', $u['name']);
?>
<div class="slidebox" style="text-align:left;">
<style>
.lb{font-size:16px}
.lb .top{line-height:24px}
.lb .ok{color:#2a7a2a;font-weight:bold}
.lb .bad{color:#c22;font-weight:bold}
.lb .lgh{display:flex;align-items:center;white-space:nowrap;overflow:hidden;height:28px;line-height:28px;margin-top:12px;padding:0 6px 0 8px;border-radius:4px;background:#00706f;color:#fff;font-weight:bold}
.lb .lgh .n{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis}
.lb .lgh .a{flex:none;margin-left:8px;font-weight:normal}
.lb .gs{color:#555;margin:3px 0 0 2px}
.lb .u{border-bottom:1px solid #ddd;padding:5px 0}
.lb .l1,.lb .l2{display:flex;align-items:baseline;white-space:nowrap;overflow:hidden;line-height:24px}
.lb .l1 .n{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis}
.lb .l1 .n a{color:teal}
.lb .l1 .a{flex:none;margin-left:8px}
.lb .l1 .a b{color:#333}
.lb .l1 .a span{color:#777}
.lb .l2,.lb .l4{margin-left:1em;color:#444}
.lb .l2 .s{flex:none}
.lb .l2 .s b{color:#222}
.lb .l2 .x{flex:0 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;margin-left:8px;color:#555;font-style:italic}
.lb .l2 .mq{flex:none;margin-left:auto;padding-left:8px}
.lb .l2 .mq.stale{color:#c22;font-weight:bold}
.lb .l4{font-size:15px;line-height:22px;color:#555}
.lb .l4 span{white-space:nowrap;margin-right:10px}
.lb .l4 .c-bad{color:#c22}
.lb .legend{color:#666;font-size:15px;text-align:right;margin-top:4px}
.lb .warn{background:#fdd;color:#a11;padding:5px 7px;border-radius:4px;margin:4px 0;white-space:normal}
.lb details{margin-top:14px;color:#333}
.lb summary{cursor:pointer;font-weight:bold;color:#444}
.lb table.cfg{border-collapse:collapse;margin-top:4px;width:100%;font-size:15px}
.lb table.cfg td{vertical-align:top;padding:4px;border-bottom:1px solid #e4e4e4}
.lb table.cfg td:first-child{white-space:nowrap;color:#555;padding-right:8px}
.lb .flt{display:flex;gap:6px;margin:6px 0}
.lb .flt select,.lb .flt input{font-size:15px;min-width:0}
.lb .flt input{flex:1 1 auto}
.lb .log{font-size:15px;color:#333;background:#f7f7f7;padding:4px;max-height:420px;overflow:auto}
.lb .log .ll{white-space:pre-wrap;word-break:break-word;border-bottom:1px solid #e6e6e6;padding:2px 0}
.lb .log .k-err{color:#b11}
.lb .log .k-set{color:#035a9e}
.lb .foot{margin-top:12px;font-size:15px;color:#555;display:flex;align-items:center;gap:6px}
.lbdot{display:inline-block;width:10px;height:10px;border-radius:50%;background:#bbb}
.lbdot.g{background:#2a2}.lbdot.a{background:#e9a000}.lbdot.r{background:#d22}
</style>
<div class="lb">
<div id="lbs-live"><?=lbs_render($hb)?></div>
<details><summary>Settings</summary><?=lbs_settings($hb)?></details>
<details id="logbox"><summary>Log</summary>
<div class="flt"><select id="fk"><option value="ev">Events</option><option value="set">Changes</option><option value="err">Problems</option><option value="pause">Paused</option><option value="all">All</option></select>
<select id="fu"><option value="0">All units</option><?php foreach ($units as $id => $n) echo '<option value="' . (int)$id . '">' . h($n) . '</option>'; ?></select>
<input id="ft" type="search" placeholder="search"></div>
<div class="log" id="log"><?=lbs_log_html(lbs_log_lines())?></div>
</details>
<div class="foot"><span id="lbdot" class="lbdot <?=is_array($hb) && $hb['fresh'] ? 'g' : 'r'?>"></span><span id="asof">as of <?=date('H:i:s')?></span><span style="margin-left:auto"><a href="./">Back to units</a></span></div>
</div>
</div></div>
<script>
(function(){
	var fk = document.getElementById('fk'), fu = document.getElementById('fu'), ft = document.getElementById('ft');
	function filt(){
		var k = fk.value, u = fu.value, t = ft.value.toLowerCase();
		document.querySelectorAll('#log .ll').forEach(function(l){
			var c = l.className, ok = (k === 'all') || (k === 'ev' ? c.indexOf('k-plan') < 0 : c.indexOf('k-' + k) >= 0);
			if (ok && u !== '0') ok = l.dataset.u === u;
			if (ok && t) ok = l.textContent.toLowerCase().indexOf(t) >= 0;
			l.style.display = ok ? '' : 'none';
		});
	}
	[fk, fu].forEach(function(x){ x.addEventListener('change', filt); }); ft.addEventListener('input', filt); filt();
	function hide(){ document.querySelectorAll('.lb .l2 .x').forEach(function(x){ if (x.scrollWidth > x.clientWidth + 1) x.style.display = 'none'; }); }
	hide();
	OEVLive.poll(function(){ return 'lbstate.php?detail=1' + (document.getElementById('logbox').open ? '&log=1' : ''); }, function(j){
		if (!j.ok) { OEVLive.dot('r', 'No load balancer heartbeat'); return; }
		document.getElementById('lbs-live').innerHTML = j.html; hide();
		if (j.log !== undefined) { var lg = document.getElementById('log'), top = lg.scrollTop; lg.innerHTML = j.log; filt(); lg.scrollTop = top; }
		var d = new Date(j.now * 1000); document.getElementById('asof').textContent = 'as of ' + d.toTimeString().slice(0, 8);
		OEVLive.light(j, 'Live: updated every 5 s');
	});
})();
</script>
</body></html>
