// node tests/asof_freshness_test.js — "as of" yellow follows the shown data's age, not the page's
const fs = require('fs'), vm = require('vm');
let f = 0;
function ck(n, ok) { if (!ok) f++; console.log((ok ? 'PASS ' : 'FAIL ') + n); }
const ls = {}, wl = {}, timers = [];
const doc = { hidden: false, addEventListener: (e, cb) => ((ls[e] = ls[e] || []).push(cb)), getElementById: () => null };
const ctx = { document: doc, window: { addEventListener: (e, cb) => ((wl[e] = wl[e] || []).push(cb)) },
	setInterval: (cb, ms) => timers.push({ cb, ms }), fetch: () => new Promise(() => {}) };
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(__dirname + '/../live.js', 'utf8') + ';this.OEVLive = OEVLive;', ctx);
const L = ctx.OEVLive;
let clock = 0; const now = () => clock;
const el = () => ({ style: { backgroundColor: '' } });
const Y = '#ffa';
const T0 = 1794077305;   // server render time (s)

// fresh initial load
clock = T0 * 1000; let e = el(); let a = L.asOf(e, T0, { now, noTimer: true });
ck('fresh load not yellow', e.style.backgroundColor === '');
clock = (T0 + 299) * 1000; ck('4:59 not stale', a.check() === false && e.style.backgroundColor === '');
clock = (T0 + 300) * 1000; ck('5:00 stale', a.check() === true && e.style.backgroundColor === Y);

// cached page resumed 2 h later: yellow, then a fresh reply clears it
clock = (T0 + 7200) * 1000; e = el(); a = L.asOf(e, T0, { now, noTimer: true });
ck('cached page resumed → yellow', e.style.backgroundColor === Y);
a.update({ ok: true, fresh: true, now: T0 + 7200, time: T0 + 7198 });
ck('fresh reply clears yellow', e.style.backgroundColor === '' && a.ts === T0 + 7198);

// updates stop (missed polls): goes yellow again after 5 min of data age
clock = (T0 + 7198 + 301) * 1000; ck('no updates for 5 min → yellow', a.check() === true && e.style.backgroundColor === Y);
// intermittent miss shorter than 5 min stays clear
clock = (T0 + 7200) * 1000; a.update({ ok: true, fresh: true, now: T0 + 7200, time: T0 + 7200 });
clock = (T0 + 7200 + 60) * 1000; ck('1 min gap stays clear', a.check() === false);

// stale payload received now: LB down (fresh=false) keeps old ts → stays stale
e = el(); clock = (T0 + 1000) * 1000; a = L.asOf(e, T0, { now, noTimer: true });
a.update({ ok: true, fresh: false, now: T0 + 1000, time: T0 + 990 });
ck('LB-down reply stays yellow', e.style.backgroundColor === Y && a.ts === T0);
// a "fresh" reply whose heartbeat time is itself old stays yellow
a.update({ ok: true, fresh: true, now: T0 + 1000, time: T0 + 600 });
ck('old heartbeat time stays yellow', e.style.backgroundColor === Y);
// no load balancer (ok=false) does not mark fresh
e = el(); a = L.asOf(e, T0, { now, noTimer: true }); a.update({ ok: false, now: T0 + 1000 });
ck('no LB reply stays yellow', e.style.backgroundColor === Y);

// invalid / missing time ignored
e = el(); clock = (T0 + 1000) * 1000; a = L.asOf(e, T0, { now, noTimer: true });
a.update({ ok: true, fresh: true, now: T0 + 1000, time: 'x' }); a.update({ ok: true, fresh: true, now: T0 + 1000 });
ck('invalid time ignored', a.ts === T0 && e.style.backgroundColor === Y);
e = el(); a = L.asOf(e, undefined, { now, noTimer: true });
ck('missing initial ts → stale', e.style.backgroundColor === Y);

// browser clock skew: phone 10 min fast, data current → not yellow after first reply
e = el(); clock = (T0 + 600) * 1000; a = L.asOf(e, T0, { now, noTimer: true });
a.update({ ok: true, fresh: true, now: T0 + 2, time: T0 + 1 });
ck('phone clock 10 min fast, fresh data → clear', e.style.backgroundColor === '');
clock = (T0 + 600 + 200) * 1000; ck('skew offset kept for re-checks', a.check() === false);

// timers + resume hooks registered; resume re-checks only (no ts change)
timers.length = 0; for (const k in ls) delete ls[k]; for (const k in wl) delete wl[k];
e = el(); clock = T0 * 1000; a = L.asOf(e, T0, { now });
ck('15 s re-check timer', timers.some(t => t.ms === 15000));
clock = (T0 + 400) * 1000; (ls.visibilitychange || []).forEach(cb => cb());
ck('visibility resume with stale data → yellow', e.style.backgroundColor === Y && a.ts === T0);
a.update({ ok: true, fresh: true, now: T0 + 400, time: T0 + 399 }); e.style.backgroundColor = Y;
(wl.pageshow || []).forEach(cb => cb());
ck('pageshow re-check clears when data fresh', e.style.backgroundColor === '');

// index.php wiring
const idx = fs.readFileSync(__dirname + '/../index.php', 'utf8');
ck('index uses OEVLive.asOf on td_time', /OEVLive\.asOf\(document\.getElementById\('td_time'\)/.test(idx));
ck('index updates asOf on each reply', /apply\(j\);\s*asOf\.update\(j\);/.test(idx));
ck('old page-age check gone', !/var difference = /.test(idx));
process.exit(f ? 1 : 0);
