<?php
// shared by the tools: site helpers + the MQTT login file (users_lib.php)
$__site = getenv('OPENEVSE_SITE') ?: dirname(__DIR__, 2);   // the site folder
$__cwd = getcwd(); chdir($__site); require $__site . '/include_v5.php'; chdir($__cwd);
require_once __DIR__ . '/users_lib.php';
