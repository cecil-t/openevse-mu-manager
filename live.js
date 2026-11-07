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
	},
	// "as of" freshness: el turns yellow (#ffa) when the shown data is >= limit s old.
	// ts = server time (s) of the shown data; offset maps the browser clock to the server's
	// (set from each reply's j.now, so a phone's clock skew doesn't count). Re-checked every
	// 15 s and when the tab comes back, so it also goes yellow if updates stop.
	asOf: function(el, ts, opt){
		opt = opt || {};
		var limit = opt.limit || 300, now = opt.now || function(){ return Date.now(); }, offset = 0;
		function ok(t){ return typeof t === 'number' && isFinite(t) && t > 0; }
		var o = {
			ts: ok(ts) ? ts : null,
			age: function(){ return o.ts === null ? Infinity : (now() + offset) / 1000 - o.ts; },
			check: function(){ var st = o.age() >= limit; if (el) el.style.backgroundColor = st ? '#ffa' : ''; return st; },
			// a reply: only fresh data with a valid time moves ts; the clock offset is kept either way
			update: function(j){
				if (j && ok(j.now)) offset = j.now * 1000 - now();
				if (j && j.ok && j.fresh && ok(j.time)) o.ts = j.time;
				return o.check();
			}
		};
		if (!opt.noTimer && typeof setInterval === 'function') setInterval(o.check, 15000);
		if (!opt.noTimer && typeof document !== 'undefined' && document.addEventListener) {
			document.addEventListener('visibilitychange', function(){ if (!document.hidden) o.check(); });
			if (typeof window !== 'undefined' && window.addEventListener) window.addEventListener('pageshow', o.check);
		}
		o.check();
		return o;
	}
};
