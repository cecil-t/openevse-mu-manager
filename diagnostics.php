<?php
/******************************************************************************
 * diagnostics.php — faults, thermal, WiFi / unit health.
 * Full Diagnostics body lands with the Sessions restructure; this page is the
 * stable nav target (avatar menu) and currently bridges to the existing
 * monthly thermal / faults views on Sessions.
 ******************************************************************************/
require_once __DIR__ . '/auth_lib.php'; oev_require('view');
$title = 'Diagnostics';
$OEV_WIDE = 1; include __DIR__ . '/_head.php';
?>
<div class="sec">
<h3 style="margin:8px 0 6px 0;color:#333">Diagnostics</h3>
<p class="sm" style="color:#555;font:15px Arial,sans-serif;line-height:1.45">Faults, thermal events, WiFi signal, and unit reporting. The dedicated Diagnostics layout is in progress; use these while Session Reports stays focused on energy history.</p>
<p style="font:16px Arial,sans-serif;line-height:1.6;margin:10px 0">
<a href="sessions.php?view=months">Monthly temperatures, thermal events &amp; drop-offs</a><br>
<a href="sessions.php">Session list (recent faults &amp; thermal tags)</a>
</p>
</div>
<?=oev_home_footer()?>
</div></body></html>
