<?php
// php tests/sessions_diagnostics_ia_test.php — t1162 Sessions/Diagnostics IA (source, no live DB)
$app = dirname(__DIR__);
$f = 0; $n = 0;
function ck($m, $ok){ global $f,$n; $n++; echo ($ok?'PASS':'FAIL') . " $m\n"; if (!$ok) $f++; }

$lib = file_get_contents("$app/report7_lib.php");
$js = file_get_contents("$app/report7_embed.js");
$r7 = file_get_contents("$app/report7.php");
$sess = file_get_contents("$app/sessions.php");
$diag = file_get_contents("$app/diagnostics.php");

ck('report7_lib has r7_data + r7_embed_html + r7_css + r7_span_label',
	strpos($lib, 'function r7_data(') !== false
	&& strpos($lib, 'function r7_embed_html(') !== false
	&& strpos($lib, 'function r7_css(') !== false
	&& strpos($lib, 'function r7_span_label(') !== false);
ck('r7_data uses oev_visible_unit_ids (auth scope)', strpos($lib, 'oev_visible_unit_ids') !== false);
ck('r7_data keeps 5-min buckets / samples / segments / gaps / sessions list',
	strpos($lib, 'R7_STEP') !== false && strpos($lib, 'FROM samples') !== false
	&& strpos($lib, 'FROM segments') !== false && strpos($lib, "'list'") !== false);
ck('embed JS uses R7_JSON_URL refresh + chips/draw/list',
	strpos($js, 'R7_JSON_URL') !== false && strpos($js, 'function chips') !== false
	&& strpos($js, 'function draw') !== false && strpos($js, 'function list') !== false);
ck('report7.php JSON path + HTML redirect to sessions',
	strpos($r7, "isset(\$_GET['json'])") !== false
	&& strpos($r7, 'r7_data()') !== false
	&& strpos($r7, 'Location: sessions.php') !== false
	&& strpos($r7, 'function r7_data') === false); // no duplicated body
ck('sessions requires report7_lib and embeds overview before history',
	strpos($sess, "require_once __DIR__ . '/report7_lib.php'") !== false
	&& strpos($sess, 'r7_embed_html($R7)') !== false
	&& strpos($sess, 'r7_data()') !== false
	&& strpos($sess, 'Session history') !== false);
ck('sessions overview independent of filters (comment + order)',
	strpos($sess, 'independent of history filters') !== false
	&& strpos($sess, 'r7_embed_html') < strpos($sess, 'Session history'));
ck('sessions dropped monthly kWh By month section',
	strpos($sess, '<h4>By month</h4>') === false
	&& strpos($sess, 'kWh per month') === false
	&& strpos($sess, 'kWh per day') === false);
ck('sessions dropped faults/WiFi/collector blocks',
	strpos($sess, '<h4>Faults') === false
	&& strpos($sess, '<h4>WiFi drop-offs') === false
	&& strpos($sess, '<h4>Collector</h4>') === false
	&& strpos($sess, 'href="report7.php"') === false);
ck('sessions months view redirects to diagnostics before _head output',
	($iReq = strpos($sess, "oev_require('view')")) !== false
	&& ($iView = strpos($sess, "view'] == 'months'")) !== false
	&& ($iHead = strpos($sess, "include __DIR__ . '/_head.php'")) !== false
	&& $iReq < $iView && $iView < $iHead
	&& strpos($sess, 'Location: diagnostics.php') !== false
	&& strpos($sess, 'FROM samples WHERE (temp_c IS NOT NULL') === false);
ck('sessions keeps detail (?id=) and history filters + by unit + list',
	strpos($sess, 'SESSION DETAIL') !== false
	&& strpos($sess, 'name="from"') !== false
	&& strpos($sess, '<h4>By unit</h4>') !== false
	&& strpos($sess, '<h4>Sessions</h4>') !== false
	&& strpos($sess, 'svg_session_pair') !== false);
ck('diagnostics is real body not bridge placeholder',
	strpos($diag, 'layout is in progress') === false
	&& strpos($diag, 'use these while Session Reports') === false
	&& strpos($diag, '<h4>Faults') !== false
	&& strpos($diag, '<h4>WiFi drop-offs') !== false
	&& strpos($diag, 'id="monthly"') !== false
	&& strpos($diag, '<h4>Unit reporting</h4>') !== false
	&& strpos($diag, 'FROM events') !== false);
ck('diagnostics view auth + visible unit scope + footer',
	strpos($diag, "oev_require('view')") !== false
	&& strpos($diag, 'oev_visible_unit_ids') !== false
	&& strpos($diag, 'oev_home_footer') !== false);
ck('diagnostics monthly queries preserve thermal/WiFi meaning',
	strpos($diag, "kind = 'thermal' AND reason = 'thermal'") !== false
	&& strpos($diag, "to_val = 10") !== false
	&& strpos($diag, 'idle_avg') !== false
	&& strpos($diag, 'rssi_avg') !== false);
ck('diagnostics unit reporting uses last_ok_at / last_push_at',
	strpos($diag, 'last_ok_at') !== false && strpos($diag, 'last_push_at') !== false);
ck('sys_lib still owns site collector health for System status',
	strpos(file_get_contents("$app/sys_lib.php"), "'collector'") !== false
	&& strpos(file_get_contents("$app/sys_lib.php"), 'Data collector') !== false);
ck('avatar menu Diagnostics points at diagnostics.php',
	strpos(file_get_contents("$app/auth_lib.php"), 'href="diagnostics.php">Diagnostics</a>') !== false);
ck('report7_embed.js present as static asset', is_file("$app/report7_embed.js") && strlen($js) > 500);

foreach (array('report7_lib.php','report7.php','report7_embed.js','sessions.php','diagnostics.php') as $pg) {
	if (substr($pg, -3) === '.js') { ck("$pg non-empty", strlen(file_get_contents("$app/$pg")) > 100); continue; }
	exec('php -l ' . escapeshellarg("$app/$pg") . ' 2>&1', $o, $rc);
	ck("php -l $pg", $rc === 0);
	$o = array();
}

echo $f ? "$f of $n FAILED\n" : "all $n passed\n";
exit($f ? 1 : 0);
