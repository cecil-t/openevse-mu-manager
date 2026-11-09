<?php
// php tests/footer_home_test.php — page-end Home footer is consistent (no back-arrow / Settings·Help footers).
$f = 0; $n = 0; function ck($m, $ok){ global $f, $n; $n++; if (!$ok) $f++; echo ($ok ? 'PASS ' : 'FAIL ') . $m . "\n"; }
$app = dirname(__DIR__);
require_once "$app/auth_lib.php";
ck('oev_home_footer exists', function_exists('oev_home_footer'));
$_SERVER['SCRIPT_NAME'] = '/openevse/index.php';
ck('home page: footer empty (no redundant Home)', oev_home_footer() === '');
$_SERVER['SCRIPT_NAME'] = '/openevse/account.php';
$h = oev_home_footer();
ck('account-like page: oev-foot Home strip, no arrow', $h === '<div class="oev-foot"><a href="./">Home</a></div>' && strpos($h, '&larr;') === false && strpos($h, 'Settings') === false);

$pages = array('account.php','settings.php','help.php','sessions.php','unitlist.php');
foreach ($pages as $p) {
	$src = file_get_contents("$app/$p");
	ck("$p uses oev_home_footer", strpos($src, 'oev_home_footer()') !== false);
	ck("$p has no &larr; Home footer", strpos($src, '&larr; Home') === false);
}
$unit = file_get_contents("$app/unit.php");
ck('unit.php has no page-end Home footer', strpos($unit, 'oev_home_footer') === false);
ck('unit.php still closes container cleanly', (bool)preg_match('#</script>\s*<\?=evse_rapi_stats_comment\(\)\?>\s*</div>\s*</body>#', $unit));
$acc = file_get_contents("$app/account.php");
ck('account footer no Settings·Help shortcuts', preg_match('/oev_home_footer[\s\S]{0,80}settings\.php/i', $acc) !== 1 && strpos($acc, '&middot; <a href="help.php">Help</a>') === false);
$help = file_get_contents("$app/help.php");
ck('help footer no Settings shortcut', strpos($help, 'settings.php">Settings') === false || strpos($help, 'oev_home_footer') !== false && !preg_match('/&larr; Home[\s\S]*settings\.php/', $help));
$idx = file_get_contents("$app/index.php");
ck('index has no page-end Home footer helper call', strpos($idx, 'oev_home_footer') === false);
$lbs = file_get_contents("$app/lbstatus.php");
ck('lbstatus foot says Home not Back to units', strpos($lbs, '>Home</a>') !== false && strpos($lbs, 'Back to units') === false);
$r7 = file_get_contents("$app/report7.php");
ck('report7 HTML redirects to sessions overview; no standalone Home chrome', strpos($r7, 'Location: sessions.php') !== false && strpos($r7, '&larr; Home') === false);
$sess = file_get_contents("$app/sessions.php");
ck('sessions detail keeps All sessions uplink; months redirects out', strpos($sess, '&larr; All sessions') !== false && strpos($sess, 'Location: diagnostics.php') !== false);
ck('sessions dropped Back to units', strpos($sess, 'Back to units') === false);
$login = file_get_contents("$app/login.php");
ck('login logged-in shortcut says Home', strpos($login, 'Go to the home page') === false && strpos($login, 'href="./">Home</a>') !== false);
// public safety: helper never emits view= token
ck('footer href is ./ only (public cookie path)', strpos($h, 'view=') === false && strpos($h, 'href="./"') !== false);

foreach (array('account.php','settings.php','help.php','unitlist.php','diagnostics.php') as $pg) {
	$src = file_get_contents("$app/$pg");
	ck("$pg footer after content card, before container close", (bool)preg_match('#</div>\s*<\?=oev_home_footer\(\)\?>\s*</div>\s*</body>#', $src));
}
$hd = file_get_contents("$app/_head.php");
ck('_head defines .oev-foot light strip', strpos($hd, '.oev-foot{') !== false && strpos($hd, 'background:rgba(246,246,246') !== false);
$css = file_get_contents("$app/style.css");
ck('_head oev-foot has no always-on pgw side margins', strpos($hd, 'body.pgw .oev-foot') === false);
ck('style.css gates oev-foot side margins at 700px with .sec', (bool)preg_match('/@media screen and \(min-width:700px\)\{[\s\S]*body\.pgw \.oev-foot\{margin-left:8px;margin-right:8px\}/', $css));

echo $f ? "$f of $n FAILED\n" : "all $n passed\n";
exit($f ? 1 : 0);
