<?php
/******************************************************************************
 * run_lib.php - where MU Manager writes its run-time files.
 * Rule: nothing is ever written on the server outside the site folder. All caches,
 * temp files, logs and PHP sessions go under loadbalancer/run/ (never served over
 * the web: loadbalancer/.htaccess denies everything).
 * The load balancer container mounts loadbalancer/run as /run-lb (LB_RUN_DIR), so
 * the pages, the collector and the coordinator share one cache folder.
 * OEV_RUN_DIR (environment) overrides it, e.g. for the tests.
 ******************************************************************************/
if (!function_exists('oev_run_dir')) {
function oev_run_dir(){
	$d = getenv('OEV_RUN_DIR') ?: (getenv('LB_RUN_DIR') ?: __DIR__ . '/loadbalancer/run');
	return rtrim($d, '/');
}
// a sub-folder of the run folder (made on first use), e.g. 'cache', 'tmp', 'sessions'
function oev_run_sub($sub){
	$d = oev_run_dir() . '/' . $sub;
	if (!is_dir($d)) { $o = umask(0); @mkdir($d, 0770, true); umask($o); }
	return $d;
}
// login-method / digest / reply caches (shared by the pages, collector and coordinator)
function oev_cache_dir(){ return oev_run_sub('cache'); }
// app-local temp folder (use instead of sys_get_temp_dir(), which is the NAS's shared temp folder)
function oev_tmp_dir(){ return oev_run_sub('tmp'); }
}
// PHP's own error log: keep it inside the site folder too (web pages get this from .user.ini)
@ini_set('error_log', oev_run_dir() . '/php_errors.log');
