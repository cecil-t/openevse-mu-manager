// node tests/session_graph_desktop_css_test.js — desktop session graph CSS (full width, compact height; pin fonts/strokes)
const fs = require('fs');
const src = fs.readFileSync(__dirname + '/../sessions.php', 'utf8');
let f = 0;
function ck(n, ok) { if (!ok) f++; console.log((ok ? 'PASS ' : 'FAIL ') + n); }
ck('no max-width:400px cap', !/body\.pgw \.sec>svg\{[^}]*max-width:\s*400px/.test(src));
ck('desktop height 340px', /body\.pgw \.sec>svg\{width:100%;height:340px;display:block\}/.test(src));
ck('non-scaling-stroke', /body\.pgw \.sec>svg path,body\.pgw \.sec>svg line\{vector-effect:non-scaling-stroke\}/.test(src));
ck('axis text 11px', /body\.pgw \.sec>svg text\{font-size:11px\}/.test(src));
ck('kW text 9px', /body\.pgw \.sec>svg text\[font-size="8"\]\{font-size:9px\}/.test(src));
ck('day text 10px', /body\.pgw \.sec>svg text\[font-size="9"\]\{font-size:10px\}/.test(src));
ck('amps stroke 1.8px', /body\.pgw \.sec>svg path\[stroke="#07d"\]\{stroke-width:1\.8px\}/.test(src));
ck('pilot dash + 1.2px', /path\[stroke-dasharray\]\{stroke-width:1\.2px;stroke-dasharray:4px 3px\}/.test(src));
ck('temp stroke 1.1px', /path:not\(\[stroke="#07d"\]\):not\(\[stroke-dasharray\]\)\{stroke-width:1\.1px\}/.test(src));
ck('viewBox still 360x190', /\$W = 360; \$H = 190/.test(src));
ck('width 100% retained', /viewBox='0 0 \$W \$H' width='100%'/.test(src));
ck('desktop-only media', /@media screen and \(min-width:700px\)\{[\s\S]*?height:340px/.test(src));
process.exit(f ? 1 : 0);
