<?php
if (!function_exists("h")) { http_response_code(404); exit; }
// shared page header for the v5 pages
// site name (Settings > Preferences): page titles and the line under the logo
$oev_site = function_exists('oev_site_name') ? oev_site_name() : 'OpenEVSE MU Manager';
$title = (isset($title) && $title !== '') ? $title . ' - ' . $oev_site : $oev_site;
$oev_sub = trim(preg_replace('/^\s*open\s*evse\b[\s:-]*/i', '', $oev_site));   // the logo already says OpenEVSE
if ($oev_sub === '') $oev_sub = $oev_site;
?><!DOCTYPE html>
<html lang="en">
<head>
	<title><?=h($title)?></title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="mobile-web-app-capable" content="yes">
	<link rel="manifest" href="./manifest.json">
	<link rel=icon type="image/png" href="media/favicon-32x32.png" sizes="32x32">
	<link rel="stylesheet" type="text/css" href="style.css">
	<script src="live.js?v=<?=(int)@filemtime(__DIR__ . '/live.js')?>"></script>
	<style>form.inl{display:inline;margin:0}form.inl button{background:none;border:0;padding:0;cursor:pointer;font:inherit}.api{font-size:70%;color:#888}.container{position:relative}
.v,span.v,b.v{color:#000 !important;font-weight:600;overflow-wrap:break-word}
.av{position:absolute;top:8px;right:2px;z-index:30;text-align:left}
.avb{width:44px;height:44px;padding:5px;margin:0;background:none;border:0;cursor:pointer;-webkit-tap-highlight-color:transparent}
.avb .avc{display:block;width:34px;height:34px;border-radius:50%;background:#00807f;color:#fff;font:bold 15px/34px Arial,sans-serif;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.35);border:0;padding:0;margin:0}
.avdot{position:absolute;top:4px;right:4px;width:11px;height:11px;border-radius:50%;background:#d22;border:2px solid #fff;pointer-events:none}
.avm{position:absolute;right:4px;top:44px;background:#fff;border:1px solid #bbb;border-radius:8px;box-shadow:0 4px 14px rgba(0,0,0,.25);min-width:220px;max-width:280px;padding:4px 0}
.avm .avn{padding:8px 14px 9px 14px;border-bottom:1px solid #ddd;color:#222;font:bold 17px Arial,sans-serif;word-break:break-word}
.avm .avn span{display:block;font-weight:normal;color:#555;font-size:16px;margin-top:2px}
.avm a,.avm button{display:block;width:100%;box-sizing:border-box;padding:10px 14px;margin:0;color:#035a5a;text-decoration:none;background:none;border:0;border-radius:0;text-align:left;font:17px Arial,sans-serif;cursor:pointer}
.avm a:hover,.avm button:hover{background:#eef6f6}
.avm form{margin:0;border-top:1px solid #ddd}
.avm a span.avok,.avm a span.avbad{display:inline;border:0;padding:0;margin:0 0 0 6px;background:none;box-shadow:none;font:bold 17px Arial,sans-serif}.avm a span.avok{color:#2a7a2a}.avm a span.avbad{color:#b11}
.avlogin{display:inline-block;margin-top:6px;padding:6px 12px;border-radius:16px;background:#00807f;color:#fff !important;font:bold 16px Arial,sans-serif;text-decoration:none;box-shadow:0 1px 3px rgba(0,0,0,.35)}</style>
</head>
<body>
<div class="container">
<div class="header" onclick="location.href='./<?=$wq?>';" style="cursor:pointer;"><h1>Open<span>EVSE</span></h1><h3><?=h($oev_sub)?></h3></div>
<?php if (function_exists('oev_userbar') && empty($GLOBALS['OEV_NOBAR'])) echo oev_userbar(); ?>
