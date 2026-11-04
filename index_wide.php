<?php
/* Home page, wide screens (700 px+): units as a table grouped by load group, sortable, searchable;
 * clicking a row opens its details in place (read only: controls stay on the unit page).
 * Phones keep the two-line list above (dl.units), unchanged. Data: $W / $wgroups from index.php. */
if (!isset($W, $wgroups)) { http_response_code(404); exit; }
$wnu = count($W);
$wdata = array('units'=>$W, 'groups'=>array_values($wgroups), 'now'=>time(), 'pub'=>oev_is_public(), 'wq'=>$with ? '&with=' . implode(',', $with) : '');
?>
<style>
#wide{text-align:left}
#unit dl.units,#unit .uhead{display:none}
#unit.slidebox{background:#fff}
@media screen and (min-width:700px){
	body{zoom:100% !important}
	.container{max-width:1200px}
	#unit.slidebox{max-width:none;width:auto;margin:0 8px;padding:10px 12px}
}
#wide .bar{display:flex;flex-wrap:wrap;gap:6px 10px;align-items:center;margin-bottom:10px}
#wide .bar input{padding:6px 8px;border:1px solid #bbb;border-radius:5px;font-size:15px;flex:1;min-width:140px;max-width:260px}
#wide .chip{padding:5px 10px;border-radius:14px;border:1px solid #00706f;color:#00706f;font-size:13px;background:#fff;cursor:pointer;font-family:inherit}
#wide .chip.on{background:#00706f;color:#fff}
#wide .cnt{font-size:13px;color:#666;margin-left:auto}
#wt{border-collapse:collapse;width:100%;background:#fff;table-layout:fixed;font:15px/1.4 Arial,sans-serif;color:#000}
#wt th,#wt td{border-bottom:1px solid #e3e3e3;padding:6px;text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#wt th{position:sticky;top:0;z-index:5;box-shadow:0 1px 0 #ccc;background:#f2f2f2;font-size:13px;color:#222}
#wt col.cu{width:19%}#wt col.cs{width:13%}#wt col.cd{width:13%}#wt col.ce{width:16%}#wt col.ch{width:18%}#wt col.cl{width:12%}#wt col.cn{width:9%}
#wt .num{text-align:right}
#wt td.stc{overflow:visible;text-overflow:clip}
#wt td.dp,#wt th.dp{text-align:right;padding-right:22px}
#wt tr.u{cursor:pointer}#wt tr.u:hover,#wt tr.u.op{background:#f4fbfb}
#wt tr.u td:first-child a{color:#000;font-weight:bold;text-decoration:none}
#wt tr.g td{white-space:normal;background:#035a5a;color:#fff;font-weight:bold;font-size:14px;cursor:pointer;user-select:none}
#wt tr.g.ung td{background:#777}
#wt tr.g .m{font-weight:normal;opacity:.85}
#wt .bal{display:inline-block;width:90px;height:6px;background:#ffffff44;border-radius:3px;vertical-align:middle;margin-left:8px}
#wt .bal i{display:block;height:6px;background:#7fe0d0;border-radius:3px}
#wt .bal i.hi{background:#ffb74d}
#wt .st{display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;font-weight:bold;background:rgba(136,136,136,.16);color:#555}
#wt .st.s1{background:rgba(0,170,0,.15);color:#060}
#wt .st.s2{background:rgba(0,170,119,.16);color:#064}
#wt .st.s3{background:rgba(0,119,221,.15);color:#035a9e}
#wt .st.s254,#wt .st.s255{background:rgba(136,34,221,.13);color:#5a1a96}
#wt .st.serr{background:rgba(255,34,34,.13);color:#a11}
#wt img.plug{height:1.4em;margin-left:6px;vertical-align:middle;opacity:.8}
#wt tr.u td:first-child img.plug{float:right;margin:0 0 0 4px}
#wide .pw{display:none}
#wt .gp{white-space:nowrap}#wt .gp .gn{overflow:hidden;text-overflow:ellipsis;min-width:0}#wt .gp .m{flex:none}
#wt .x{color:#aaa}
#wt .ses .t{color:#666;margin-left:6px}
#wt .run{color:#c62828;font-weight:600}
#wt .ck{background:#ffa;font-size:11px;margin-left:4px;padding:0 3px}
#wt tr.det>td{background:#f7fafa;white-space:normal;padding:12px;cursor:default}
#wt .dg{display:grid;grid-template-columns:1.2fr 1fr 1.2fr;grid-template-rows:auto 1fr;gap:0 18px}
#wt .dg h4{margin:0 0 6px;color:#035a5a;font-size:15px}
#wt .dg table{width:100%;background:none;border-collapse:collapse;table-layout:auto}
#wt .dg td{font-size:13px;padding:3px 6px;border-bottom:1px solid #e3e3e3;white-space:normal;overflow:visible;color:#000}
#wt .dg td:first-child{color:#666;width:42%}
#wt .dg a{color:#035a5a}
#wt .dg>div:nth-child(1){grid-column:1;grid-row:1}
#wt .dg>div:nth-child(2){grid-column:2;grid-row:1;display:flex;flex-direction:column}
#wt .dg>div:nth-child(2) h4.lbh{margin-top:auto;padding-top:12px}
#wt .dg>div:nth-child(3){grid-column:3;grid-row:1/3}
#wt .dg>.strip{grid-column:1/3;grid-row:2;align-self:end;display:flex;flex-wrap:nowrap;align-items:center;gap:6px 28px;padding:3px 6px;font-size:13px}
#wt .strip b{color:#035a5a}
#wt .strip a.open{color:#035a5a;font-weight:bold}
#wt .td{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px;vertical-align:-1px}
#wt .td.g{background:#2a2}#wt .td.y{background:#e6b800}#wt .td.r{background:#d22}#wt .td.b{background:#1e6fd9}
#wt .lbd{display:inline-block;width:8px;height:8px;border-radius:50%;background:#2a2;vertical-align:1px}#wt .lbd.r{background:#d22}
#wt .bars{display:inline-flex;align-items:flex-end;gap:2px;height:14px;vertical-align:middle}
#wt .bars i{width:4px;background:#ccc}#wt .bars i:nth-child(1){height:20%}#wt .bars i:nth-child(2){height:40%}#wt .bars i:nth-child(3){height:60%}#wt .bars i:nth-child(4){height:80%}#wt .bars i:nth-child(5){height:100%}#wt .bars i.on{background:#035a5a}
#unit table.list{background:none}
#wt .ok{color:#11632f;font-weight:bold}#wt .e{color:#a11;font-weight:bold}
#wt .ld{color:#aaa;font-style:italic}
.c-w{display:none}.c-x{display:none}
@media (min-width:900px){#wt .c-w{display:table-cell}#wt col.c-w{display:table-column}}
@media (min-width:1000px){#wt .c-x{display:table-cell}#wt col.c-x{display:table-column}}
@media (max-width:999px){#wt{table-layout:auto}#wt col{width:auto !important}}
@media (max-width:899px){#wt .dg{display:flex;flex-direction:column}#wt .dg>.strip{flex-wrap:wrap;margin-top:8px}}
@media screen and (max-width:699px){#wide .pw{display:inline}#wide .gp.pw{display:flex}#wide .dw{display:none}
	#wide .bar{gap:6px}#wide .bar input{flex:1 1 180px;max-width:none;min-width:0;padding:5px 7px}#wide .chip{padding:4px 9px;font-size:12px}
	#wt tr.u td:first-child{font-size:14px}#wt tr.u td:first-child img.plug{height:1em;margin-top:2px}#wt .bal{display:none}#wide .cnt{display:none}#wt th,#wt td{padding:6px 4px}
	#wt{table-layout:fixed}#wt col.cu{width:51% !important}#wt col.cs{width:31% !important}#wt col.cd{width:18% !important}#wt th{font-size:12px;white-space:normal;overflow:visible;text-overflow:clip;vertical-align:bottom}#wt td.dp,#wt th.dp{padding-right:4px}
	#wt img.plug{margin-left:3px}
	#unit.slidebox{width:auto;max-width:none;margin:0 5px;padding:8px}}
</style>
<div id="wide">
<div class="bar"><input id="wq" type="search" placeholder="Search units&hellip;" aria-label="Search units">
<button type="button" class="chip" data-s="group">By group</button><button type="button" class="chip" data-s="charging">By charging</button><button type="button" class="chip" data-s="problems">By problems</button><button type="button" class="chip" data-s="name">By name</button>
<span class="cnt"><?=(int)$nenabled?> unit<?=$nenabled == 1 ? '' : 's'?><?=$ngroups ? ' &middot; ' . (int)$ngroups . ' load group' . ($ngroups == 1 ? '' : 's') : ''?></span></div>
<table id="wt"></table>
</div>
<script>
var OEWide = (function(){
	var D = <?=json_encode($wdata, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR)?>;
	var skew = Date.now() / 1000 - D.now;   // browser clock - server clock
	var U = D.units, G = {}, info = {}, loading = {};
	D.groups.forEach(function(g){ G[g.id] = g; });
	var ngroups = D.groups.length, big = U.length > 50 || ngroups > 5;
	var KS = 'oemu-groups', mem = {}; try { mem = JSON.parse(localStorage.getItem(KS) || '{}') || {}; } catch (e) {}
	var P = new URLSearchParams(location.search), forceId = parseInt(P.get('open') || '', 10) || null, openId = forceId;
	try { if (!openId) openId = parseInt(sessionStorage.getItem('oemu-open') || '', 10) || null; } catch (e) {}
	var sort = 'group'; try { sort = localStorage.getItem('oemu-sort') || 'group'; } catch (e) {}
	var q = ''; try { q = sessionStorage.getItem('oemu-q') || ''; } catch (e) {}
	var DASH = '<span class="x">\u2014</span>';
	function esc(s){ return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
	function v(x){ return (x === null || x === undefined || x === '') ? DASH : x; }
	function fmtA(a){ return (Math.round(a * 10) / 10).toString(); }
	function ago(t){
		if (!t) return DASH;
		var s = Math.max(0, Math.round(Date.now() / 1000 - skew - t));
		return s < 120 ? s + ' s' : s < 7200 ? Math.round(s / 60) + ' min' : s < 172800 ? Math.round(s / 3600) + ' h' : Math.round(s / 86400) + ' d';
	}
	function problem(u){ return u.code === -1 || u.pcls === 'serr' || u.stale || u.clock_off; }
	function gOpen(k, us){
		if (forceId && us.some(function(u){ return u.id === forceId; })) return true;
		return (k in mem) ? !!mem[k] : !big;
	}
	function drawing(u){ return u.amps === null ? DASH : '<span class="dw"><b>' + fmtA(u.amps) + '</b> / ' + (u.pilot === null ? '\u2014' : u.pilot) + ' A</span><span class="pw"><b>' + fmtA(u.amps) + '</b> A</span>'; }
	function share(u){
		if (!u.g) return '<span class="x">not balanced</span>';
		if (u.share === null) return DASH;
		return u.share + ' A / ' + (u.amps ? fmtA(u.amps) + ' A' : '<span class="x">0 A</span>');
	}
	function row(u, hid){
		var ses = u.ses ? (u.ses.kwh === null ? '' : '<b>' + esc(u.ses.kwh) + '</b> kWh') + '<span class="t">' + esc(u.ses.dur) + '</span>' : DASH;
		var sch = u.sch ? '<span' + (u.run ? ' class="run" title="Scheduled charge running"' : '') + '>' + esc(u.sch) + '</span>' : DASH;
		return '<tr class="u' + (u.id === openId ? ' op' : '') + '" data-id="' + u.id + '" data-in="' + u.g + '"' + hid + '>'
			+ '<td>' + (u.plugged ? '<img src="media/plug.svg" class="plug" alt="Connected" title="EV Connected">' : '') + '<a href="' + esc(u.link) + '" title="' + esc(u.name) + '">' + esc(u.name) + '</a>' + (window.OEWS ? OEWS.dot(u.id) : '') + (u.test ? ' <span class="x">(test)</span>' : '') + '</td>'
			+ '<td class="stc"><span class="st ' + u.pcls + '" title="' + esc(u.pill) + '">' + esc(u.pill) + '</span></td>'
			+ '<td class="num dp" data-k="drw">' + drawing(u) + '</td>'
			+ '<td class="c-w ses">' + ses + '</td>'
			+ '<td class="c-x">' + sch + (u.clock_off ? '<small class="ck" title="Unit clock is off from server time">clock off</small>' : '') + '</td>'
			+ '<td class="c-x" data-k="shr">' + share(u) + '</td>'
			+ '<td class="c-w num' + (u.code === -1 ? ' x' : '') + '" data-k="seen">' + ago(u.seen) + '</td></tr>';
	}
	function det(u, hid){
		var d = Object.assign({}, u.d, info[u.id] || {}), ld = loading[u.id] ? '<span class="ld">reading\u2026</span>' : DASH;
		function f(k){ return (d[k] === undefined || d[k] === '') ? ((k in LAZY) && loading[u.id] ? ld : DASH) : d[k]; }
		function tr(l, k){ return '<tr><td>' + l + '</td><td>' + f(k) + '</td></tr>'; }
		var g = G[u.g];
		var state = (u.plugged ? '<img src="media/plug.svg" class="plug" alt=""> ' : '') + esc(u.pill);
		var amps = u.amps === null ? '' : fmtA(u.amps) + ' amps', pw = (u.amps !== null && u.volts) ? (Math.round(u.amps * u.volts / 100) / 10) + ' kW' : '';
		if (amps) d.amps = amps; if (pw) d.power = pw;
		return '<tr class="det" data-in="' + u.g + '"' + hid + '><td colspan="7"><div class="dg">'
			+ '<div><h4>Status</h4><table><tr><td>State</td><td>' + state + '</td></tr>' + tr('Override', 'ovr') + tr('Pilot', 'pilot') + tr('Current', 'amps') + tr('Voltage', 'volts') + tr('Power', 'power') + tr('Temp', 'temp') + tr('Session Energy', 'energy') + tr('Charge Time', 'ct') + '</table></div>'
			+ '<div><h4>Schedule &amp; limits</h4><table>' + tr('Start / Stop', 'start') + tr('Limits', 'lim') + tr('Level', 'level') + '</table>'
			+ '<h4 class="lbh">Load balancer</h4><table><tr><td>Group</td><td>' + (g ? esc(g.name) : 'No load group') + '</td></tr>'
			+ '<tr><td>Share</td><td>' + (g && u.share !== null ? u.share + ' A of ' + g.limit + ' A' : (g ? DASH : 'not balanced')) + '</td></tr>'
			+ tr('Max A', 'maxa') + '<tr><td>Drawing</td><td>' + (u.amps === null ? DASH : fmtA(u.amps) + ' A') + '</td></tr></table></div>'
			+ '<div><h4>Settings &amp; Info</h4><table>' + tr('Last check-in', 'checkin') + tr('Safety Checks', 'checks') + tr('Time', 'time') + tr('Uptime', 'uptime') + tr('Firmware / WiFi', 'fw') + tr('API', 'api') + tr('Hostname', 'host') + tr('IP address', 'ip') + tr('SSID', 'ssid') + tr('WiFi signal', 'rssi') + '</table></div>'
			+ '<div class="strip"><a class="open" href="' + esc(u.link) + '">Open unit page</a>'
			+ (D.pub ? '' : '<span><b>Last Session</b> ' + v(d.last) + '</span><span><b>Lifetime</b> ' + v(d.life) + '</span>') + '</div>'
			+ '</div></td></tr>';
	}
	var LAZY = {ovr:1, checks:1, time:1, fw:1, host:1, ssid:1, energy:1, lim:1, level:1, uptime:1, start:1, temp:1, rssi:1, volts:1, pilot:1};
	function gHead(g, us){
		var k = String(g ? g.id : 0), op = gOpen(k, us), ar = op ? '\u25BE' : '\u25B8';
		if (!g) return '<tr class="g ung" data-g="0"><td colspan="7">' + ar + ' No load group <span class="m">&middot; ' + us.length + ' unit' + (us.length == 1 ? '' : 's') + '</span></td></tr>';
		var pct = g.limit ? Math.min(100, g.amps / g.limit * 100) : 0;
		return '<tr class="g" data-g="' + g.id + '"><td colspan="7"><span class="pw gp"><span>' + ar + '\u00a0</span><span class="gn">' + esc(g.name) + '</span><span class="m">\u00a0&middot; Using ' + fmtA(g.amps) + ' A of ' + g.limit + ' A</span></span><span class="dw">' + ar + ' ' + esc(g.name) + ' <span class="m">&middot; ' + g.limit + ' A limit &middot; <span class="gu">' + fmtA(g.amps) + '</span> A in use &middot; ' + us.length + ' unit' + (us.length == 1 ? '' : 's') + '</span><span class="bal"><i class="' + (pct > 90 ? 'hi' : '') + '" style="width:' + pct.toFixed(0) + '%"></i></span></span></td></tr>';
	}
	function byName(a, b){ return a.name.localeCompare(b.name, undefined, {numeric: true}); }
	function render(){
		var h = '<colgroup><col class="cu"><col class="cs"><col class="cd"><col class="ce c-w"><col class="ch c-x"><col class="cl c-x"><col class="cn c-w"></colgroup>'
			+ '<tr><th>Unit</th><th>Status</th><th class="num dp"><span class="dw">Drawing / Pilot</span><span class="pw">Draw</span></th><th class="c-w ses">Session</th><th class="c-x">Schedule</th><th class="c-x">LB share / actual</th><th class="c-w num">Last seen</th></tr>';
		var qq = q.trim().toLowerCase();
		var us = U.filter(function(u){ return !qq || u.name.toLowerCase().indexOf(qq) >= 0 || (G[u.g] && G[u.g].name.toLowerCase().indexOf(qq) >= 0); });
		function rows(list, hid){ var o = ''; list.forEach(function(u){ o += row(u, hid); if (u.id === openId) o += det(u, hid); }); return o; }
		if (sort === 'group') {
			var order = D.groups.map(function(g){ return g.id; }); if (U.some(function(u){ return !u.g; })) order.push(0);
			order.forEach(function(gid){
				var list = us.filter(function(u){ return u.g === gid; }); if (!list.length) return;
				var op = qq ? true : gOpen(String(gid), list);
				h += gHead(G[gid] || null, list) + rows(list, op ? '' : ' style="display:none"');
			});
		} else {
			var list = us.slice();
			if (sort === 'name') list.sort(byName);
			else if (sort === 'charging') list.sort(function(a, b){ return ((b.code === 3) - (a.code === 3)) || (b.plugged - a.plugged) || byName(a, b); });
			else list.sort(function(a, b){ return (problem(b) - problem(a)) || byName(a, b); });
			h += rows(list, '');
		}
		if (!us.length) h += '<tr><td colspan="7" class="x">No units match.</td></tr>';
		var t = document.getElementById('wt'); t.innerHTML = h;
		document.querySelectorAll('#wide .chip').forEach(function(c){ c.classList.toggle('on', c.dataset.s === sort); });
		bind(t);
		if (window.OEWS) OEWS.rendered(t);   // index.php?ws=1 test only
	}
	function bind(t){
		t.querySelectorAll('tr.g').forEach(function(r){ r.onclick = function(){
			var k = r.dataset.g, cells = t.querySelectorAll('tr[data-in="' + k + '"]'), o = cells.length && cells[0].style.display === 'none';
			if (forceId && cells.length && !o) forceId = null;
			mem[k] = o; try { localStorage.setItem(KS, JSON.stringify(mem)); } catch (e) {}
			cells.forEach(function(x){ x.style.display = o ? '' : 'none'; });
			var c = r.firstChild; c.innerHTML = c.innerHTML.replace(/[\u25BE\u25B8]/g, o ? '\u25BE' : '\u25B8');
		}; });
		t.querySelectorAll('tr.u').forEach(function(r){ r.onclick = function(ev){
			if (ev.target.closest('a')) return;   // the unit name opens the unit page
			if (!window.matchMedia('(min-width:700px)').matches) { location.href = r.querySelector('td a').href; return; }   // phones: no expand, open the unit page
			var id = parseInt(r.dataset.id, 10);
			openId = (openId === id) ? null : id;
			try { if (openId) sessionStorage.setItem('oemu-open', openId); else sessionStorage.removeItem('oemu-open'); } catch (e) {}
			render();
			if (openId) fetchInfo(openId);
		}; });
	}
	// one read of the unit when its row is opened (unitinfo.php: the same read unit.php does), never repeated automatically
	function fetchInfo(id){
		if (info[id] || loading[id]) return;
		loading[id] = true; render();
		fetch('unitinfo.php?unitid=' + id + D.wq, {cache: 'no-store'}).then(function(r){ return r.json(); })
			.then(function(j){ info[id] = (j && j.ok) ? j.d : {}; }).catch(function(){ info[id] = {}; })
			.then(function(){ delete loading[id]; render(); });
	}
	// live update (index.php's 5 s load balancer poll)
	function unit(x){
		var u = U.filter(function(y){ return y.id === x.id; })[0]; if (!u || !x.ok) return;
		var F = window.OEWS ? OEWS.fresh : function(){ return false; };   // index.php?ws=1 test: values the live feed sent in the last 15 s stay
		u.paused = !!x.paused; u.stale = x.stale;
		if (!F(u.id, 'state') || x.paused) { u.code = x.code; u.pill = x.pill; u.pcls = x.pill_cls; }
		if (!F(u.id, 'vehicle')) u.plugged = x.plugged;
		if (!F(u.id, 'amp') || !F(u.id, 'state')) u.amps = x.charging && x.amps !== null ? Math.round(x.amps * 10) / 10 : 0;
		if (x.setting !== null && !F(u.id, 'pilot')) u.pilot = x.setting;
		if (u.g && x.setting !== null) u.share = x.setting;
		if (x.checkin !== null) { u.seen = Math.max(u.seen || 0, Math.round(Date.now() / 1000 - skew) - x.checkin); u.d.checkin = '<span class="lbd ' + (x.stale ? 'r' : 'g') + '"></span> ' + (x.checkin < 120 ? x.checkin + ' s ago' : x.checkin < 7200 ? Math.round(x.checkin / 60) + ' min ago' : (Math.round(x.checkin / 360) / 10) + ' h ago'); }
		dirty = true;
	}
	function group(g){ if (G[g.id]) { G[g.id].amps = g.live_amps; G[g.id].limit = g.limit; dirty = true; } }
	var dirty = false;
	setInterval(function(){
		if (document.hidden) return;
		if (dirty) { dirty = false; render(); }
		else document.querySelectorAll('#wt td[data-k="seen"]').forEach(function(c){ var u = U.filter(function(y){ return y.id == c.parentNode.dataset.id; })[0]; if (u) c.innerHTML = ago(u.seen); });
	}, 5000);
	var qi = document.getElementById('wq'); qi.value = q;
	qi.oninput = function(){ q = qi.value; try { sessionStorage.setItem('oemu-q', q); } catch (e) {} render(); };
	document.querySelectorAll('#wide .chip').forEach(function(c){ c.onclick = function(){ sort = c.dataset.s; try { localStorage.setItem('oemu-sort', sort); } catch (e) {} render(); }; });
	render();
	if (openId && window.matchMedia('(min-width:700px)').matches) { fetchInfo(openId); var r = document.querySelector('#wt tr.u[data-id="' + openId + '"]'); if (r && P.get('open')) r.scrollIntoView({block: 'center'}); }
	return {unit: unit, group: group, U: U, refresh: render};
})();
</script>
