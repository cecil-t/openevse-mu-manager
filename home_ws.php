<?php
/* home_ws.php - live feed for the home page (on by default; index.php?ws=0 leaves it out).
 * One stream per tab (home_ws_bridge.php) for the enabled, online units; values from the feed show as they
 * arrive, the 5 s load balancer poll fills in the rest (and takes a value back 15 s after the feed last sent it).
 * Per unit: a small dot after its name, blue ring while its feed is live; its two-line tooltip has one owner (tip()).
 * A changed value flashes #fff8d1 (0.1 s, then a 1.2 s fade); Last seen / Last check-in never flash. */
if (!isset($W) || !function_exists('h')) { http_response_code(404); exit; }
$hws_ids = array(); foreach ($W as $x) if (!$x['test'] && $x['code'] !== -1) $hws_ids[] = (int)$x['id'];
?>
<style>
#lbdot.wslive{box-shadow:inset 0 0 0 2px #0077dd}
#wt td.vchg,#wt .dg td.vchg{background-color:#fff8d1 !important;transition:none}
#wt td.vfade{transition:background-color 1.2s ease-out}
</style>
<script>
var OEWS = (function(){
	var IDS = <?=json_encode($hws_ids)?>, W = window.OEWide;
	if (!W || !W.U || !IDS.length) return null;
	var S = {}, at = {}, es = null, retry = null, last = 0, pend = false, prev = {};
	IDS.forEach(function(id){ S[id] = {st: 'connecting', last: 0, until: 0}; });
	function U(id){ return W.U.filter(function(u){ return u.id === id; })[0]; }
	function fresh(id, k){ return !!(at[id] && at[id][k] && Date.now() - at[id][k] < 15000); }
	function secs(ms){ var s = Math.max(0, Math.round(ms / 1000)); return s < 120 ? s + ' s' : Math.round(s / 60) + ' min'; }
	// the one owner of a unit dot's tooltip: line 1 how the unit connection is going, line 2 its detail
	function tip(id){
		var s = S[id]; if (!s) return 'Updating every 5 seconds';
		if (s.st === 'live') return 'Live: updating instantly from the unit\n' + (s.last ? 'Last update ' + secs(Date.now() - s.last) + ' ago' : 'Connected, no changes yet');
		if (s.st === 'connecting') return 'Live feed connecting\nUpdating every 5 seconds';
		if (s.st === 'reconnecting') return 'Live feed reconnecting\nUpdating every 5 seconds';
		if (s.st === 'busy') return 'Live feed limit reached for this unit\nUpdating every 5 seconds';
		if (s.st === 'full') return 'Live feeds all in use on the server\nUpdating every 5 seconds';
		return 'Live feed stopped (the unit did not accept it)\nUpdating every 5 seconds';
	}
	function dot(){ return ''; }   // no per-unit dots: one ring on the "as of" dot while any feed is live
	function paint(){ var d = document.getElementById('lbdot'); if (d) d.classList.toggle('wslive', IDS.some(function(id){ return S[id].st === 'live'; })); }
	setInterval(paint, 1000);
	if (window.OEVLive) { var od = OEVLive.dot; OEVLive.dot = function(c, t){ od(c, t); paint(); }; }   // the 5 s poll resets the dot's class: put the ring back at once
	function refresh(){ var n = Date.now(); if (n - last >= 250) { last = n; W.refresh(); } else if (!pend) { pend = true; setTimeout(function(){ pend = false; last = Date.now(); W.refresh(); }, 250); } }
	// after each table render: flash the cells whose text really changed (not Last seen / Last check-in)
	function rendered(t){
		paint();
		var now = {};
		t.querySelectorAll('tr.u').forEach(function(r){ var id = r.dataset.id; [].forEach.call(r.cells, function(c, i){ if (c.dataset.k === 'seen') return; key(now, 'r' + id + ':' + i, c); }); });
		t.querySelectorAll('tr.det').forEach(function(r){ var p = r.previousElementSibling, id = p ? p.dataset.id : ''; r.querySelectorAll('.dg table tr').forEach(function(tr){ var l = tr.cells[0] ? tr.cells[0].textContent : ''; if (l === 'Last check-in' || !tr.cells[1]) return; key(now, 'd' + id + ':' + l, tr.cells[1]); }); });
		prev = now;
	}
	function key(now, k, c){
		var tx = c.textContent; now[k] = tx; c.classList.add('vfade');
		if (k in prev && prev[k] !== tx && !/\u2026$/.test(prev[k])) { c.classList.add('vchg'); setTimeout(function(){ c.classList.remove('vchg'); }, 100); }
	}
	function upd(j){
		var id = j.id, u = U(id), now = Date.now(); if (!u) return;
		at[id] = at[id] || {}; for (var k in j) at[id][k] = now;
		S[id].st = 'live'; S[id].last = now;
		if (j.state !== undefined && !u.paused) { u.code = j.state; u.pill = j.state_text; u.pcls = j.state_cls; if (j.state !== 3) u.amps = u.code === -1 ? null : 0; }
		if (j.vehicle !== undefined) u.plugged = !!j.vehicle;
		if (j.amp !== undefined && u.code === 3) u.amps = Math.round(j.amp / 100) / 10;
		if (j.pilot !== undefined && j.pilot > 0) { u.pilot = j.pilot; u.d.pilot = j.pilot + ' A' + ((u.saved !== null && j.pilot < u.saved) ? ' / ' + u.saved + ' A' : '') + (u.volts ? ' &middot; ' + (Math.round(j.pilot * u.volts / 100) / 10) + ' kW' : ''); }
		if (j.voltage !== undefined && u.code === 3 && j.voltage >= 90 && j.voltage <= 260) u.d.volts = Math.round(j.voltage) + ' volts';
		if (j.temp_html) u.d.temp = j.temp_html;
		if (j.energy_text !== undefined && u.ses) u.d.energy = j.energy_text;
		refresh();
	}
	function want(){ var n = Date.now(); return IDS.filter(function(id){ return S[id].until <= n; }); }
	function again(ms){ if (es) { es.close(); es = null; } if (!retry) retry = setTimeout(function(){ retry = null; start(); }, ms); }
	function start(){
		if (document.hidden) { retry = setTimeout(function(){ retry = null; start(); }, 2000); return; }
		var ids = want(); if (!ids.length) { again(15000); return; }
		var got = false;
		es = new EventSource('home_ws_bridge.php?units=' + ids.join(','));
		es.addEventListener('hello', function(){ got = true; });
		es.addEventListener('connected', function(m){ var id = JSON.parse(m.data).id; at[id] = at[id] || {}; at[id][''] = Date.now(); S[id].st = 'live'; paint(); });
		es.addEventListener('u', function(m){ upd(JSON.parse(m.data)); });
		es.addEventListener('busy', function(m){ var id = JSON.parse(m.data).id; S[id].st = 'busy'; S[id].until = Date.now() + 15000; paint(); });
		es.addEventListener('fail', function(m){ var id = JSON.parse(m.data).id; S[id].st = 'fail'; S[id].until = Date.now() + 300000; paint(); });   // refused: try again in 5 min
		es.addEventListener('drop', function(m){ var id = JSON.parse(m.data).id; S[id].st = 'reconnecting'; paint(); });
		es.addEventListener('end', function(){ es.close(); es = null; start(); });   // planned 25 s rollover: next stream at once, live units stay live (ring steady)
		es.onerror = function(){   // closed for good (503 = all server feed slots in use, or no answer): 5 s poll, a new try after 15 s
			if (!es) return;
			if (es.readyState === 2 || !got) { ids.forEach(function(id){ if (S[id].st !== 'fail' && S[id].st !== 'busy') S[id].st = got ? 'reconnecting' : 'full'; }); paint(); again(15000); }
		};
	}
	start();
	setTimeout(W.refresh, 0);   // after OEWS is assigned, so the first render has the dots
	return {fresh: fresh, dot: dot, rendered: rendered};
})();
</script>
