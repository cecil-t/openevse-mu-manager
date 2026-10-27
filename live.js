/* live.js - the live update shared by the home page, a unit's page and the load balancer page:
 * the coloured dot (#lbdot: g live, a late, r down, x none) and a poll of lbstate.php
 * every 5 s while the tab is visible (read only: lbstate.php never writes anything). */
var OEVLive = {
	dot: function(cls, t){ var d = document.getElementById('lbdot'); if (d) { d.className = 'lbdot ' + cls; d.title = t; } },
	// the usual light for a heartbeat reply; live = the green title
	light: function(j, live){
		if (!j.fresh) OEVLive.dot('r', 'Load balancer down: last heartbeat ' + Math.round(j.age) + ' s ago');
		else if (j.age > 15) OEVLive.dot('a', 'Load balancer data is ' + j.age + ' s old');
		else OEVLive.dot('g', live);
	},
	// url: string or function returning one; onData(json); onFail() optional. Returns the tick function.
	poll: function(url, onData, onFail){
		var fails = 0;
		function tick(){
			if (document.hidden) return;
			fetch(typeof url === 'function' ? url() : url, {cache: 'no-store'}).then(function(r){ if (!r.ok) throw r.status; return r.json(); })
				.then(function(j){ fails = 0; onData(j); })
				.catch(function(){ fails++; if (onFail) onFail(); OEVLive.dot(fails >= 3 ? 'r' : 'a', 'Live update failed (' + fails + 'x)'); });
		}
		document.addEventListener('visibilitychange', function(){ if (!document.hidden) tick(); });
		setInterval(tick, 5000);
		return tick;
	}
};
