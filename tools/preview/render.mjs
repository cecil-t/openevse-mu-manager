#!/usr/bin/env node
/**
 * Build static HTML page previews from shared fixture-data.json + app CSS/source.
 * Usage: node tools/preview/render.mjs [--src DIR] [--out DIR]
 * Default --src is repo root; --out is tools/preview/out
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '../..');
const args = process.argv.slice(2);
const srcIdx = args.indexOf('--src');
const outIdx = args.indexOf('--out');
const SRC = path.resolve(srcIdx >= 0 ? args[srcIdx + 1] : ROOT);
const OUT = path.resolve(outIdx >= 0 ? args[outIdx + 1] : path.join(__dirname, 'out'));

const data = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixture-data.json'), 'utf8'));
const CSRF = data.csrf ?? ''; // must be empty — never visible "CSRF" stub

function read(rel) {
  return fs.readFileSync(path.join(SRC, rel), 'utf8');
}
function pageStyles(rel) {
  const t = read(rel);
  return [...t.matchAll(/<style>([\s\S]*?)<\/style>/g)].map(m => m[1]).join('\n');
}
function headInlineCss() {
  const hd = read('_head.php');
  const m = hd.match(/<style>([\s\S]*?)<\/style>/);
  return m ? m[1] : '';
}
function esc(s) {
  return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function groupName(id) {
  const g = data.groups.find(x => x.id === id);
  return g ? g.name : 'No load group';
}
function unitsInGroup(gid) {
  return data.units.filter(u => u.group_id === gid);
}
function statePill(u) {
  const map = {
    ready: ['s1', 'Ready'], charging: ['s3', 'Charging'], connected: ['s2', 'Connected'],
    fault: ['serr', 'Fault'], offline: ['serr', 'Offline'], sleeping: ['s254', 'Sleeping'],
  };
  const [cls, label] = map[u.state] || ['', u.state];
  return `<span class="st ${cls}"><i>${esc(label)}</i></span>`;
}
function kw(u) {
  if (u.state !== 'charging' || u.amps == null) return '';
  const v = 240;
  return `<span class="kw"><i>${(u.amps * v / 1000).toFixed(1)}&nbsp;kW</i></span>`;
}

/** Pages that call oev_home_footer() in live code (never index/unit/login). */
const FOOTER_PAGES = new Set([
  'sessions', 'diagnostics', 'account', 'help', 'settings-system', 'settings-units', 'unitlist',
]);

function wrap(name, pageCss, bodyHtml, { wide = true } = {}) {
  const style = read('style.css');
  const headCss = headInlineCss();
  const bc = wide ? ' class="pgw"' : '';
  const foot = FOOTER_PAGES.has(name)
    ? '<div class="oev-foot" id="foot"><a href="./">Home</a></div>'
    : '<!-- no Home footer: page does not call oev_home_footer() -->';
  return `<!DOCTYPE html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(name)} — preview</title>
<style>${style}\n${headCss}\n${pageCss}</style>
</head><body${bc}>
<div class="container oev-main" id="main">
<div class="header" id="hdr"><h1>Open<span>EVSE</span></h1><h3>${esc(data.site_name)}</h3></div>
${bodyHtml}
${foot}
</div></body></html>`;
}

function renderIndex() {
  let body = `<div class="slidebox" style="text-align:left;" id="unit" data-primary>
<div class="uhead"><div class="h1">${data.units.length} units · ${data.groups.filter(g => g.id != null).length} load groups</div></div>`;
  for (const g of data.groups) {
    const us = unitsInGroup(g.id);
    if (!us.length && g.id != null) continue;
    const live = us.filter(u => u.state === 'charging').reduce((s, u) => s + (u.amps || 0), 0);
    const lim = g.limit_a != null ? `${live.toFixed(1)} / ${g.limit_a} A` : '';
    const cls = g.id == null ? 'lgh ung' : 'lgh';
    body += `<div class="${cls}"><span class="n">${esc(g.name)}</span>${lim ? `<span class="a">${esc(lim)}</span>` : ''}<span class="lb live">LB on</span></div>`;
    body += `<dl class="units">`;
    for (const u of (g.id == null ? data.units.filter(x => x.group_id == null) : us)) {
      body += `<div class="ub"><dt><a href="#">${esc(u.name)}</a><span class="pc">${u.pilot_a} A</span></dt>
<dd>${statePill(u)}${u.plugged ? ' <span class="ex pl"></span> ' : ''}${kw(u)}<span class="ss">${esc(u.last_session)}</span></dd></div>`;
    }
    body += `</dl>`;
  }
  body += `</div>`;
  return wrap('index', pageStyles('index.php') + '\n' + pageStyles('index_wide.php'), body, { wide: false });
}

function renderSessions() {
  const cards = data.sessions_sample.map(s =>
    `<div class="card"><a class="blk" href="#"><b>${esc(s.title)}</b><br>${esc(s.when)} · ${s.kwh} kWh · ${esc(s.dur)}</a></div>`
  ).join('\n');
  const tot = data.sessions_sample.reduce((a, s) => a + s.kwh, 0);
  const body = `<div class="sec" id="first" data-primary><h4>Last 7 days</h4>
<p class="sm">Solid = charging current; dashed = temperature. Units: ${data.units.map(u => esc(u.name)).join(', ')}</p>
<div class="sgd"><svg width="100%" height="140" style="background:#fff;border:1px solid #ccc"></svg>
<div class="chips" style="margin-top:8px">
${data.units.slice(0, 6).map(u => `<button type="button" class="chip"><span class="sw" style="background:${u.color}"></span><span class="nm">${esc(u.name)}</span></button>`).join('')}
</div></div></div>
<div class="sec"><h4>Session history</h4>
<div class="card" data-inner><b>${tot.toFixed(1)} kWh</b> in ${data.sessions_sample.length} sessions</div>
${cards}</div>`;
  return wrap('sessions', pageStyles('sessions.php') + '\n' + (fs.existsSync(path.join(SRC, 'report7_lib.php')) ? (() => { try { const t = read('report7_lib.php'); const m = t.match(/return <<<'CSS'\n([\s\S]*?)\nCSS;/); return m ? m[1] : ''; } catch { return ''; } })() : ''), body);
}

function renderDiagnostics() {
  const rows = data.units.map(u => {
    const red = (n, thr = 1) => n >= thr ? ' style="color:#c22;font-weight:bold"' : '';
    return `<div class="uh-r"><span class="uh-n">${esc(u.name)}</span>
<span class="uh-c" data-l="GFCI"${red(u.gfci)}>${u.gfci}</span>
<span class="uh-c" data-l="No ground"${red(u.nognd)}>${u.nognd}</span>
<span class="uh-c" data-l="Stuck"${red(u.stuck)}>${u.stuck}</span>
<span class="uh-c" data-l="WiFi restarts 7d"${red(u.wifi_rb7, 5)}>${u.wifi_rb7}</span></div>`;
  }).join('\n');
  const body = `<div class="sec diag-top" id="first" data-primary><h3>Diagnostics</h3>
<p class="sm">Faults, thermal events, and WiFi health for each unit.</p>
<form class="flt"><label>From <input type="date" value="2026-10-01"></label>
<label>To <input type="date" value="2026-11-09"></label><button type="submit">Show</button></form></div>
<div class="sec"><h4>Unit health</h4>
<p class="sm">Lifetime fault counts from each charger, and WiFi module restarts in the last 7 days (red when 5 or more).</p>
<div class="card uh" data-inner>
<div class="uh-h"><span>Unit</span><span>GFCI</span><span>No ground</span><span>Stuck</span><span>WiFi restarts (7d)</span></div>
${rows}</div></div>`;
  return wrap('diagnostics', pageStyles('diagnostics.php'), body);
}

function renderAccount() {
  const csrf = CSRF; // empty string — hidden input would be empty; never literal "CSRF"
  let panels = '';
  for (const u of data.units.slice(0, 5)) {
    const rems = data.reminders_by_unit[String(u.id)] || [];
    const remSum = rems.length
      ? rems.map(r => esc(r.label)).join(', ')
      : 'Reminder Off';
    panels += `<details class="ync" ${u.id === 4 ? 'open' : ''} id="notify-u${u.id}">
<summary><span class="ync-name">${esc(u.name)}</span>
<span class="ync-b">Errors On</span><span class="ync-b">Reboots Off</span><span class="ync-b">Summary Off</span>
<span class="ync-b">Stopped On</span><span class="ync-b">${rems.length ? '<b>' + remSum + '</b>' : remSum}</span></summary>
<div class="yn-rem"><div class="note">Plug-in reminder: if nothing is plugged in at or shortly after this time, you get one email. Goes only to your account email.</div>
${rems.map(r => `<form method="post" class="rem-row">${csrf}
<div class="rem-f"><label>Plug in by</label><input type="time" value="${esc(r.at_time)}"></div>
<button type="submit" class="go">Save</button><button type="submit" class="del">Remove</button></form>`).join('')}
<form method="post" class="rem-row rem-add${rems.length ? ' rem-add-after' : ''}">${csrf}
<div class="rem-f"><label>${rems.length ? 'Add another' : 'Plug in by'}</label><input type="time" value="21:00"></div>
<button type="submit" class="go">Add reminder</button></form>
</div></details>`;
  }
  const body = `<div class="slidebox ac pgcard" id="first" data-primary>
<div class="card" data-inner><b>${esc(data.user.name)}</b><br>${esc(data.user.email)}<br>Admin: can view and change everything. Sees all load groups.</div>
<div class="card" id="notify"><b>Email notifications</b>
<div class="note" style="margin:4px 2px 0">Per charger (tap to edit): <b>Default</b> follows your checkboxes above.
<strong>Errors</strong> = faults/offline; <strong>Reboots</strong> = charger reboots; <strong>Session summary</strong> = when unplugged; <strong>Charging stopped</strong> = stopped while still plugged in.
<strong>Plug-in reminders</strong> live on each charger below (your account email only).</div>
${panels}</div></div>`;
  return wrap('account', pageStyles('account.php'), body);
}

function renderHelp() {
  const body = `<div class="slidebox hp pgcard" id="first" data-primary>
<h2>Using this site</h2>
<p>${esc(data.help_blurb)}</p>
<ul><li>Home lists every unit, including long names like <b>${esc(data.units.find(u => u.name.length > 20).name)}</b>.</li>
<li>Session Reports: energy history and the 7-day chart.</li>
<li>Account Preferences: email alerts and plug-in reminders.</li>
<li>Settings (admins): units, load groups (${data.groups.filter(g => g.id).map(g => esc(g.name)).join('; ')}), and System status.</li></ul>
<h2>Load balancer</h2>
<p>When several cars charge at once, the load balancer shares the circuit so you do not trip the breaker.</p>
</div>`;
  return wrap('help', pageStyles('help.php'), body);
}

function renderSettingsSystem() {
  const reporting = data.units.map(u => {
    const ok = !u.offline;
    return `<div class="sys"><span class="ic ${ok ? 'ok' : 'bad'}">${ok ? '✓' : '!'}</span><div><b>${esc(u.name)}</b> · ${ok ? 'reading 8s ago · push 8s ago' : 'no check-in (offline)'}</div></div>`;
  }).join('\n');
  const body = `<div class="slidebox set pgcard s-system" id="first" data-primary>
<div class="tabs"><a class="on" href="#">System status</a><a href="#">Units</a><a href="#">Prefs</a></div>
<h3>Collector</h3>
<div class="card" data-inner>
<div class="sys"><span class="ic ok">✓</span><div><b>Session collector</b><div class="note">Last run 12s ago · OK</div></div></div>
<div class="sys"><span class="ic ok">✓</span><div><b>Notifier</b><div class="note">Last run 2m ago · OK</div></div></div>
</div>
<h3>Unit reporting</h3>
<div class="card"><div class="note">When each unit last delivered a good reading (collector or push).</div>
${reporting}</div></div>`;
  return wrap('settings-system', pageStyles('settings.php'), body);
}

function renderSettingsUnits() {
  const cards = data.units.map((u, i) => {
    const open = i < 2 ? ' open' : '';
    return `<details class="card"${i === 0 ? ' data-inner' : ''}${open}><summary>${esc(u.name)} <span>· ${esc(groupName(u.group_id))}${u.offline ? ' · offline' : ''}</span></summary>
<label>Name</label><input type="text" value="${esc(u.name)}">
<label>URL</label><input type="url" value="http://${esc(u.ip)}/">
<label>Max A</label><input type="number" value="${u.max_a}">
<button type="submit" class="go">Save</button></details>`;
  }).join('\n');
  const body = `<div class="slidebox set pgcard s-units" id="first" data-primary>
<div class="tabs"><a href="#">System status</a><a class="on" href="#">Units</a></div>
<div class="note" style="margin:2px 0 8px 0"><a href="#" style="color:#035a5a;font-weight:bold">Unit List (table / CSV)</a></div>
${cards}</div>`;
  return wrap('settings-units', pageStyles('settings.php'), body);
}

function renderUnitlist() {
  const rows = data.units.map(u =>
    `<tr><td class="num">${u.id}</td><td>${esc(u.name)}</td><td>${esc(u.ip)}</td>
<td>${esc(u.host)}</td><td class="sm">${esc(u.mac)}</td><td class="sm">${esc(u.serial)}</td>
<td class="num">${u.max_a}</td><td class="num">${u.pilot_a}</td></tr>`
  ).join('\n');
  const body = `<div class="nm pgcard" id="first" data-primary>
<div class="nm-top"><h2>Unit List</h2><a class="btn" href="#">Download CSV</a>
<a class="lnk" href="#">Settings &gt; Units</a><span>Read from the units at 12:40 am EST</span></div>
<p style="margin:0 0 8px 0;color:#555">Unit names link to each unit's own web page when you're on the same network as the units.</p>
<div class="nm-wrap" data-inner><table>
<tr><th>#</th><th>Name</th><th>IP</th><th>Hostname</th><th>MAC</th><th>Controller Serial</th><th>Max A</th><th>Pilot</th></tr>
${rows}</table></div>
<div class="nm-foot">* Long names and hostnames exercise overflow. Read only: nothing is changed on the units.</div></div>`;
  return wrap('unitlist', pageStyles('unitlist.php'), body);
}

function renderUnit() {
  const u = data.units.find(x => x.name.includes('NACS')) || data.units[3];
  const body = `<div class="slidebox pgcard" id="ucard" data-primary>
<div style="font-weight:bold;margin:0 0 6px 0;color:#035a5a">${esc(u.name)}</div>
<table class="stats"><tr><th class="s1">Ready</th><th>Temp ${u.temp_f ?? '—'} °F</th></tr>
<tr><td>Pilot</td><td>${u.pilot_a} A</td></tr>
<tr><td>Energy</td><td>12.4 kWh</td></tr>
<tr><td>Session</td><td>not plugged in</td></tr></table></div>`;
  return wrap('unit', pageStyles('unit.php'), body);
}

function renderLogin() {
  const body = `<div class="slidebox lg" id="first" data-primary>
<form>${CSRF}
<label>Email</label><input type="email" value="">
<label>Password</label><input type="password">
<button type="submit">Log in</button></form>
<div class="note">You stay logged in on this device until you log out.</div></div>`;
  return wrap('login', pageStyles('login.php'), body, { wide: false });
}

const pages = {
  index: renderIndex,
  sessions: renderSessions,
  diagnostics: renderDiagnostics,
  account: renderAccount,
  help: renderHelp,
  'settings-system': renderSettingsSystem,
  'settings-units': renderSettingsUnits,
  unitlist: renderUnitlist,
  unit: renderUnit,
  login: renderLogin,
};

fs.mkdirSync(OUT, { recursive: true });
const names = [];
for (const [name, fn] of Object.entries(pages)) {
  const html = fn();
  if (html.includes('>CSRF<') || /\bCSRF\b/.test(html.replace(/<!--[\s\S]*?-->/g, ''))) {
    console.error('FATAL: visible CSRF stub in', name);
    process.exit(1);
  }
  fs.writeFileSync(path.join(OUT, name + '.html'), html);
  names.push(name);
}
fs.writeFileSync(path.join(OUT, 'pages.json'), JSON.stringify(names, null, 2));
fs.writeFileSync(path.join(OUT, 'meta.json'), JSON.stringify({
  src: SRC, out: OUT, csrf: CSRF, footer_pages: [...FOOTER_PAGES],
  units: data.units.map(u => ({ id: u.id, name: u.name, len: u.name.length })),
  groups: data.groups.map(g => ({ id: g.id, name: g.name, len: g.name.length })),
}, null, 2));
console.log('Rendered', names.length, 'pages →', OUT);
