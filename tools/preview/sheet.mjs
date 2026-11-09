#!/usr/bin/env node
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { createRequire } from 'module';
import { execFileSync } from 'child_process';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const args = process.argv.slice(2);
const outIdx = args.indexOf('--out');
const OUT = path.resolve(outIdx >= 0 ? args[outIdx + 1] : path.join(__dirname, 'out'));
const destIdx = args.indexOf('--dest');
const DEST = path.resolve(destIdx >= 0 ? args[destIdx + 1] : '/workspace/preview-fixtures-412.png');
const WIDTH = 412;
const pages = JSON.parse(fs.readFileSync(path.join(OUT, 'pages.json'), 'utf8'));
const shots = path.join(OUT, 'shots');
fs.mkdirSync(shots, { recursive: true });
const chrome = process.env.CHROME || '/usr/bin/google-chrome-stable';
for (const name of pages) {
  execFileSync(chrome, [
    '--headless=new', '--disable-gpu', '--hide-scrollbars',
    '--force-device-scale-factor=1', `--window-size=${WIDTH},560`,
    `--screenshot=${shots}/${name}.png`,
    'file://' + path.join(OUT, name + '.html'),
  ], { stdio: 'ignore' });
}
const require = createRequire(import.meta.url);
// Use sharp-less PIL via python for compositing (reliable on this box)
const py = `
from PIL import Image, ImageDraw, ImageFont
from pathlib import Path
pages = ${JSON.stringify(pages)}
shots = Path(${JSON.stringify(shots)})
cols = 5
crop_h = 260
label_h = 24
cell_w, cell_h = ${WIDTH}, crop_h + label_h
rows = (len(pages) + cols - 1) // cols
sheet = Image.new('RGB', (cols * cell_w, rows * cell_h), (35, 35, 35))
draw = ImageDraw.Draw(sheet)
try:
    font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', 13)
except Exception:
    font = ImageFont.load_default()
meta = {}
import json
meta_path = Path(${JSON.stringify(OUT)}) / 'meta.json'
if meta_path.exists():
    meta = json.loads(meta_path.read_text())
foot = set(meta.get('footer_pages') or [])
for i, n in enumerate(pages):
    im = Image.open(shots / f'{n}.png').convert('RGB').crop((0, 0, ${WIDTH}, crop_h))
    r, c = divmod(i, cols)
    x, y = c * cell_w, r * cell_h
    sheet.paste(im, (x, y))
    draw.rectangle([x, y + crop_h, x + ${WIDTH}, y + cell_h], fill=(15, 15, 15))
    label = n if n in foot or n in ('sessions','diagnostics','account','help','settings-system','settings-units','unitlist') else n + ' (no Home footer)'
    # footer_pages from meta
    fp = set(meta.get('footer_pages') or [])
    label = n if n in fp else n + ' (no Home footer)'
    draw.text((x + 6, y + crop_h + 5), label, fill=(255, 255, 255), font=font)
sheet.save(${JSON.stringify(DEST)})
print('wrote', ${JSON.stringify(DEST)}, sheet.size)
`;
fs.writeFileSync(path.join(OUT, '_sheet.py'), py);
execFileSync('python3', [path.join(OUT, '_sheet.py')], { stdio: 'inherit' });
