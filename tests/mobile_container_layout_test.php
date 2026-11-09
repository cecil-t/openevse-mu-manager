<?php
// php tests/mobile_container_layout_test.php — t1209 shared mobile container (source assertions)
$app = dirname(__DIR__);
$f = 0; $n = 0;
function ck($m, $ok){ global $f,$n; $n++; echo ($ok?'PASS':'FAIL') . " $m\n"; if (!$ok) $f++; }

$css = file_get_contents("$app/style.css");
$hd = file_get_contents("$app/_head.php");

ck('CSS defines --oev-gutter-x / --oev-panel-mt / --oev-inset-x / --oev-foot-gap', strpos($css, '--oev-gutter-x') !== false && strpos($css, '--oev-panel-mt') !== false && strpos($css, '--oev-inset-x') !== false && strpos($css, '--oev-foot-gap') !== false);
ck('primary panels use shared horizontal inset', strpos($css, 'padding-left: var(--oev-inset-x)') !== false && strpos($css, 'padding-right: var(--oev-inset-x)') !== false);
ck('container/oev-main uses gutter padding', strpos($css, 'padding-left: var(--oev-gutter-x)') !== false && strpos($css, 'padding-right: var(--oev-gutter-x)') !== false);
ck('body margin 0 (gutters only from container)', (bool)preg_match('/^body\{\s*margin:\s*0/m', $css) || strpos($css, "body{\n\tmargin: 0;") !== false);
ck('_head emits container oev-main', strpos($hd, 'class="container oev-main"') !== false);
ck('no per-child desktop oev-foot/sec side margins', strpos($css, 'body.pgw .oev-foot{margin-left:8px') === false && strpos($css, 'body.pgw .container>.sec') === false);
ck('primary panels force full content width (kill 87% slidebox)', strpos($css, '.container > .slidebox') !== false && strpos($css, 'width: auto') !== false);
ck('desktop gutter var is 8px', strpos($css, ':root{--oev-gutter-x: 8px}') !== false || strpos($css, '--oev-gutter-x: 8px') !== false);

$ul = file_get_contents("$app/unitlist.php");
ck('unitlist no longer breaks out with 100vw width', strpos($ul, '100vw') === false && strpos($ul, 'nm-wrap') !== false);

$ac = file_get_contents("$app/account.php");
ck('account no mobile max-width:560 shrink', strpos($ac, 'max-width:560px') === false);

$iw = file_get_contents("$app/index_wide.php");
ck('index_wide no mobile margin:0 5px on #unit', strpos($iw, 'margin:0 5px') === false);

$un = file_get_contents("$app/unit.php");
ck('unit.php no mobile margin:0 5px on #ucard', strpos($un, 'margin:0 5px') === false);

echo $f ? "$f of $n FAILED\n" : "all $n passed\n";
exit($f ? 1 : 0);
