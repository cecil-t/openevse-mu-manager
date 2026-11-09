#!/usr/bin/env node
/**
 * Layout + overflow measurements for tools/preview/out HTML.
 * Usage: node tools/preview/measure.mjs [--out DIR] [--widths 360,390,412,1280]
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import puppeteer from 'puppeteer-core';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const args = process.argv.slice(2);
const outIdx = args.indexOf('--out');
const OUT = path.resolve(outIdx >= 0 ? args[outIdx + 1] : path.join(__dirname, 'out'));
const wIdx = args.indexOf('--widths');
const WIDTHS = (wIdx >= 0 ? args[wIdx + 1] : '360,390,412,1280').split(',').map(Number);
const CHROME = process.env.CHROME || '/usr/bin/google-chrome-stable';
const pages = JSON.parse(fs.readFileSync(path.join(OUT, 'pages.json'), 'utf8'));
const NEST_PAGES = new Set(['account', 'settings-system', 'settings-units', 'unitlist', 'sessions', 'diagnostics']);

const browser = await puppeteer.launch({
  executablePath: CHROME, headless: 'new',
  args: ['--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--force-device-scale-factor=1'],
});
const rows = [];
const overflows = [];

for (const w of WIDTHS) {
  const page = await browser.newPage();
  await page.setViewport({ width: w, height: 1400, deviceScaleFactor: 1 });
  for (const name of pages) {
    await page.goto('file://' + path.join(OUT, name + '.html'), { waitUntil: 'domcontentloaded' });
    const m = await page.evaluate((nestOk) => {
      const main = document.getElementById('main');
      const hdr = document.getElementById('hdr');
      const first = document.querySelector('[data-primary]');
      const foot = document.getElementById('foot');
      const inner = document.querySelector('[data-inner]');
      const panels = [...document.querySelectorAll('.container > .sec, .container > .pgcard, .container > .slidebox, #unit, #ucard')];
      const last = panels[panels.length - 1];
      const fr = first.getBoundingClientRect();
      const hr = hdr.getBoundingClientRect();
      let nestL = null, nestR = null;
      if (inner) {
        const prim = inner.closest('.sec, .pgcard, .slidebox, #unit, #ucard');
        if (prim) {
          const pr = prim.getBoundingClientRect(), ir = inner.getBoundingClientRect();
          nestL = +(ir.left - pr.left).toFixed(2);
          nestR = +(pr.right - ir.right).toFixed(2);
          if (ir.width < prim.clientWidth * 0.92) nestR = nestL;
        }
      }
      // Overflow / truncation findings on name-like nodes (skip intentional scrollers / zero-box)
      const findings = [];
      const candidates = document.querySelectorAll('.uh-n, .ync-name, .chip .nm, .lgh .n, dt a, summary, #ucard > div');
      candidates.forEach((el, i) => {
        if (i > 120) return;
        const t = (el.textContent || '').trim();
        if (t.length < 12) return; // only longer names
        if (el.clientWidth < 4 || el.clientHeight < 4) return; // not laid out / zero box
        // Inside intentional horizontal scroller (unit list table) — not a layout break
        if (el.closest('.nm-wrap, .tbl-scroll, .sgd')) return;
        const cs = getComputedStyle(el);
        const spills = el.scrollWidth > el.clientWidth + 1;
        const ellipsis = cs.textOverflow === 'ellipsis' && spills;
        const rect = el.getBoundingClientRect();
        const shell = el.closest('.container');
        const sr = shell ? shell.getBoundingClientRect() : null;
        const outsideShell = sr ? (rect.right > sr.right + 2 || rect.left < sr.left - 2) : false;
        if (spills || outsideShell) {
          findings.push({
            text: t.slice(0, 52),
            len: t.length,
            tag: el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).trim().split(/\s+/)[0] : ''),
            spills, outsideShell, ellipsis,
            scrollW: el.scrollWidth, clientW: el.clientWidth,
          });
        }
      });
      return {
        pgw: document.body.classList.contains('pgw'),
        hasFoot: !!foot,
        cardL: +fr.left.toFixed(2), cardR: +fr.right.toFixed(2), cardW: +fr.width.toFixed(2),
        gap: +(fr.top - hr.bottom).toFixed(2),
        nestL, nestR,
        footGap: (foot && last) ? +(foot.getBoundingClientRect().top - last.getBoundingClientRect().bottom).toFixed(2) : null,
        overflowX: document.documentElement.scrollWidth > window.innerWidth + 0.5,
        findings,
      };
    }, NEST_PAGES.has(name));
    m.nestCheck = NEST_PAGES.has(name);
    rows.push({ width: w, page: name, ...m });
    for (const f of m.findings) overflows.push({ width: w, page: name, ...f });
  }
  await page.close();
}
await browser.close();

function check(subset, keys, label) {
  const stats = {}; let pass = true;
  for (const k of keys) {
    const vals = subset.map(r => r[k]).filter(v => v != null);
    if (!vals.length) { stats[k] = { pass: true, span: 0 }; continue; }
    const min = Math.min(...vals), max = Math.max(...vals), span = +(max - min).toFixed(2);
    const ok = span <= 0.5; if (!ok) pass = false;
    stats[k] = { min, max, span, pass: ok };
  }
  return { label, pass, stats, overflow: subset.filter(r => r.overflowX).map(r => r.page) };
}

console.log('W    PAGE             cardL cardW  gap nestL nestR footGap');
for (const r of rows) {
  console.log(String(r.width).padStart(4), r.page.padEnd(16),
    r.cardL.toFixed(1).padStart(5), r.cardW.toFixed(1).padStart(5), r.gap.toFixed(1).padStart(4),
    String(r.nestCheck ? r.nestL : '-').padStart(5), String(r.nestCheck ? r.nestR : '-').padStart(5),
    String(r.footGap ?? '-').padStart(7));
}
let allPass = true;
console.log('\n=== LAYOUT CHECKS (±0.5px) ===');
for (const w of WIDTHS) {
  const all = rows.filter(r => r.width === w);
  const shell = w < 700 ? all : all.filter(r => r.pgw || r.page === 'index');
  const nest = all.filter(r => r.nestCheck && (w < 700 || r.pgw || r.page === 'index'));
  const foot = all.filter(r => r.hasFoot && (w < 700 || r.pgw || r.page === 'index'));
  for (const g of [
    check(shell, ['cardL', 'cardR', 'cardW', 'gap'], `@${w} shell`),
    check(nest, ['nestL', 'nestR'], `@${w} nest`),
    check(foot, ['footGap'], `@${w} footGap`),
  ]) {
    const fails = Object.entries(g.stats).filter(([, v]) => !v.pass).map(([k, v]) => `${k} span=${v.span}`);
    console.log(`${g.label}: ${g.pass ? 'PASS' : 'FAIL ' + fails.join('; ')}${g.overflow.length ? ' pageOverflow=' + g.overflow : ''}`);
    if (!g.pass || g.overflow.length) allPass = false;
  }
}

console.log('\n=== NAME OVERFLOW / TRUNCATION (report only; no site fixes) ===');
if (!overflows.length) console.log('(none detected)');
else {
  const byPage = {};
  for (const o of overflows) {
    const k = `${o.page}@${o.width}`;
    if (!byPage[k]) byPage[k] = [];
    byPage[k].push(o);
  }
  for (const [k, list] of Object.entries(byPage)) {
    console.log(k + ':');
    for (const o of list.slice(0, 8)) {
      console.log(`  - ${o.tag} "${o.text}" len=${o.len} spills=${o.spills} outsideShell=${o.outsideShell} ellipsis=${o.ellipsis} (${o.clientW}/${o.scrollW}px)`);
    }
  }
}

const report = { rows, overflows, allPass, widths: WIDTHS };
fs.writeFileSync(path.join(OUT, 'measure.json'), JSON.stringify(report, null, 2));
console.log(allPass ? '\nLAYOUT ALL PASS' : '\nLAYOUT NEEDS ATTENTION');
process.exit(0); // overflow findings are informational — do not fail the tooling run
