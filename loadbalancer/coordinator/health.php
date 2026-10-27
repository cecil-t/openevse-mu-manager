<?php
// Docker health check: heartbeat written in the last 90 s
$d = getenv('LB_RUN_DIR') ?: '/run-lb';
$t = @filemtime("$d/heartbeat.json");
exit(($t && time() - $t < 90) ? 0 : 1);
