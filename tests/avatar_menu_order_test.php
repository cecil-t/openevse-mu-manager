<?php
// php tests/avatar_menu_order_test.php — avatar menu group order + role gates (t1165)
$app = dirname(__DIR__);
$f = 0; $n = 0;
function ck($m, $ok){ global $f,$n; $n++; echo ($ok?'PASS':'FAIL') . " $m\n"; if (!$ok) $f++; }

$src = file_get_contents("$app/auth_lib.php");
$start = strpos($src, 'function oev_userbar');
$end = strpos($src, 'function oev_home_footer');
ck('oev_userbar present', $start !== false && $end !== false && $end > $start);
$fn = substr($src, $start, $end - $start);

// Positions in the assembly (not the $sys = assignment): find Diagnostics href then relative order of later pieces
$nav = strpos($fn, '<a href="./">Home</a><a href="sessions.php">Session Reports</a><a href="diagnostics.php">Diagnostics</a>');
ck('nav group Home → Session Reports → Diagnostics contiguous', $nav !== false);

$unit = strpos($fn, "(\$adm ? '<a href=\"unitlist.php\">Unit List / Info</a>' : '')");
$sep1 = strpos($fn, '$sep', $nav);
$settings = strpos($fn, "(\$adm ? '<a href=\"settings.php\">Settings</a>' : '')");
$acct = strpos($fn, '<a href="account.php">Account Preferences</a>');
$sysUse = strpos($fn, "(\$adm ? \$sys : '')");
$help = strpos($fn, '<a href="help.php">Help</a>');
$logout = strpos($fn, 'value="logout"');

ck('Unit List after Diagnostics, admin-gated', $unit !== false && $unit > $nav);
ck('first separator after Unit List gate', $sep1 !== false && $sep1 > $unit);
ck('Settings after sep, admin-gated, before Account Preferences', $settings !== false && $settings > $sep1 && $settings < $acct);
ck('Account Preferences after Settings gate', $acct !== false && $acct > $settings);
$sep2 = strpos($fn, '$sep', $acct);
ck('second separator after Account Preferences', $sep2 !== false && $sep2 > $acct);
ck('System status ($sys) after second sep, before Help', $sysUse !== false && $sysUse > $sep2 && $sysUse < $help);
ck('Help after System status gate', $help !== false && $help > $sysUse);
ck('Log out after Help (existing form separator)', $logout !== false && $logout > $help);

ck('status OK / problem indicator preserved on System status link', strpos($fn, 'avok') !== false && strpos($fn, 'avbad') !== false && strpos($fn, 'oev_sys_problems') !== false);
ck('public role still Log in only', strpos($fn, "role'] === 'public'") !== false && strpos($fn, 'avlogin') !== false);
ck('$sep defined as hr.avsep', strpos($fn, "\$sep = '<hr class=\"avsep\">'") !== false);

$hd = file_get_contents("$app/_head.php");
ck('_head styles .avsep', strpos($hd, '.avm .avsep{') !== false);

$diag = file_get_contents("$app/diagnostics.php");
ck('diagnostics.php exists with view auth + Home footer', strpos($diag, "oev_require('view')") !== false && strpos($diag, 'oev_home_footer') !== false && strpos($diag, 'Diagnostics') !== false);
ck('diagnostics.php footer nesting inside container', (bool)preg_match('#</div>\s*<\?=oev_home_footer\(\)\?>\s*</div>\s*</body>#', $diag));

// No renamed URLs / header names
ck('Session Reports label unchanged', strpos($fn, 'Session Reports') !== false);
ck('Account Preferences label unchanged', strpos($fn, 'Account Preferences') !== false);
ck('Unit List / Info label unchanged', strpos($fn, 'Unit List / Info') !== false);
ck('System status label unchanged', strpos($fn, 'System status') !== false);

echo $f ? "$f of $n FAILED\n" : "all $n passed\n";
exit($f ? 1 : 0);
