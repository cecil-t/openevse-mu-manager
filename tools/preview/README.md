# OpenEVSE MU Manager — shared page preview fixtures

One reusable fixture dataset and preview harness for layout screenshots and measurements. **Does not change live site code.**

## Contents

| File | Purpose |
|------|---------|
| `fixture-data.json` | Shared units, groups, sessions, reminders, user — varied realistic name lengths |
| `render.mjs` | Builds static HTML previews from fixture + app `style.css` / page `<style>` |
| `measure.mjs` | getBoundingClientRect layout checks + long-name overflow report |
| `sheet.mjs` | Contact sheet PNG at 412 CSS px |
| `run.sh` | One-shot: render → measure → sheet |
| `out/` | Generated HTML / measure.json / shots (gitignored) |

## CSRF

`fixture-data.json` sets `"csrf": ""`. Render never injects a visible `CSRF` stub (that was a prior fixture bug). Live pages use a hidden input via `oev_csrf_field()`.

## Home footer

Previews **do not force** a Home footer. Footer HTML is emitted only for pages that call `oev_home_footer()` in live code (`sessions`, `diagnostics`, `account`, `help`, `settings-*`, `unitlist`). **Not** on `index`, `unit`, or `login`.

## How to run

From the repo root (needs Node, system Chrome, and `puppeteer-core` resolvable — e.g. `npm i puppeteer-core` under `tools/preview` or `NODE_PATH` pointing at an install):

```bash
# Against the working tree (default)
./tools/preview/run.sh

# Against a copy of deployed files
SRC=/path/to/live-copy OUT=/tmp/oev-preview DEST=/workspace/preview-fixtures-412.png ./tools/preview/run.sh

# Custom widths
WIDTHS=360,412,1280 ./tools/preview/run.sh
```

Or step by step:

```bash
node tools/preview/render.mjs --src . --out tools/preview/out
node tools/preview/measure.mjs --out tools/preview/out --widths 360,390,412,1280
node tools/preview/sheet.mjs --out tools/preview/out --dest /workspace/preview-fixtures-412.png
```

## Fixture naming notes

Units include short (`A1`, `Bay3`, `Shop`), Greg-length (`N Penn: 2F (NACS)`), longer phrases (`Visitor Lot Level 2 Charger 12`), and an unbroken token (`WarehouseDockChargerNorth`). Groups: `Lot`, `N Penn Garage`, `Employee Parking Structure B`, plus ungrouped.
