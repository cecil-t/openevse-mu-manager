<?php
/******************************************************************************
 * OpenEVSE load-balance coordinator (runs in Docker, see ../README.md).
 *
 * Every few seconds: listens to the units' MQTT status (state changes, a unit's
 * "connected" announcement after a reboot), and every poll_sec reads every unit
 * over the web with the site's checked helpers (tagged RAPI with retries on WiFi
 * 2.x, the JSON API on v4/v5). Works out each group's split (lb_core.php) and,
 * in live mode, sets each unit's share as a TEMPORARY current (2.x '$SC N V',
 * v5 our claim) with every unit's SAVED current at its full rating (OpenEVSE as
 * designed). Lower values first, each write confirmed by a
 * read of the active value. A unit that restarted (MQTT "connected", or its
 * active value back at saved while its share is lower) gets its share again at
 * once. Idle pause: see lb_core.php. In dry-run mode it only logs.
 * Headroom: every 5 s it reads the amps of CHARGING units only (one tagged $GG,
 * ~60 ms each) and caps a car that draws well under its share (lb_hr_step in
 * lb_core.php); the spare amps go to the others. Raises wait 5 s after any lower
 * (the car's J1772 response time).
 *
 *   php coordinator.php              run (mode from lb.ini: dry_run = 1 / 0)
 *   php coordinator.php --once       one read + plan, print it, exit
 *   php coordinator.php --once --pretend=3,5   ... as if cars were plugged into units 3 and 5
 ******************************************************************************/
$cfg = parse_ini_file(getenv('LB_CONFIG') ?: __DIR__ . '/lb.ini', false, INI_SCANNER_TYPED);
// time zone: lb.ini, else TZ, else the site's (Settings > Preferences, applied when include_v5.php loads below), else the container's
$__tz = trim((string)($cfg['timezone'] ?? '')) ?: (string)getenv('TZ');
if ($__tz !== '' && in_array($__tz, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) date_default_timezone_set($__tz);
// the OpenEVSE site folder: OPENEVSE_SITE (loadbalancer/.env, passed in by docker-compose.yml), else lb.ini site_dir
$SITE = rtrim((string)(getenv('OPENEVSE_SITE') ?: ($cfg['site_dir'] ?? '')), '/');
if ($SITE === '' || !is_file($SITE . '/include_v5.php')) { fwrite(STDERR, "OpenEVSE site folder not found: set OPENEVSE_SITE in loadbalancer/.env (or site_dir in lb.ini)\n"); exit(1); }
$RUN  = rtrim(getenv('LB_RUN_DIR') ?: $cfg['run_dir'], '/');
$DRY  = (string)getenv('LB_DRY_RUN') !== '' ? (bool)(int)getenv('LB_DRY_RUN') : (bool)($cfg['dry_run'] ?? true);   // LB_DRY_RUN (loadbalancer/.env) wins over lb.ini
$opts = getopt('', ['once', 'pretend:', 'pretend-charging:']);
$ONCE = isset($opts['once']);
$PRETEND = isset($opts['pretend']) ? array_map('intval', explode(',', $opts['pretend'])) : [];
// --pretend-charging=3:3.2,5:30  : as if those units had a car charging at that many amps (forces dry run)
$PRETEND_CHG = [];
if (isset($opts['pretend-charging'])) { foreach (explode(',', $opts['pretend-charging']) as $pc) { [$pi, $pa] = array_pad(explode(':', $pc), 2, 0); $PRETEND_CHG[(int)$pi] = (float)$pa; $PRETEND[] = (int)$pi; } $DRY = true; }
if ($ONCE) $DRY = true;   // --once never writes

require __DIR__ . '/lb_core.php';
require __DIR__ . '/mqtt.php';
$cwd = getcwd(); chdir($SITE); require $SITE . '/include_v5.php'; chdir($cwd);

function lb_log($msg){
	global $RUN, $ONCE;
	$line = date('Y-m-d H:i:s') . ' ' . $msg . "\n";
	if ($ONCE) { echo $line; return; }
	@file_put_contents("$RUN/coordinator.log", $line, FILE_APPEND | LOCK_EX);
	if (@filesize("$RUN/coordinator.log") > 2000000) @rename("$RUN/coordinator.log", "$RUN/coordinator.log.1");
}
function lb_state_load(){ global $RUN; $s = @json_decode((string)@file_get_contents("$RUN/state.json"), true); return is_array($s) ? $s : ['units'=>[]]; }
function lb_state_save($s){ global $RUN, $ONCE; if ($ONCE) return; $t = "$RUN/state.json.tmp"; file_put_contents($t, json_encode($s)); rename($t, "$RUN/state.json"); }
function lb_write_json($name, $data){ global $RUN; $t = "$RUN/$name.tmp"; file_put_contents($t, json_encode($data, JSON_PRETTY_PRINT)); rename($t, "$RUN/$name"); }

// Groups and their units from the site's database (read-only); unit secrets stay in memory.
function lb_load_config(){
	global $SITE;
	$db = new PDO('sqlite:' . $SITE . '/openevse.db', null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$groups = [];
	foreach ($db->query('SELECT id, name, max_load FROM load_groups') as $g) $groups[(int)$g['id']] = ['id'=>(int)$g['id'], 'name'=>$g['name'], 'limit'=>(int)$g['max_load'], 'units'=>[]];
	$cols = array_column($db->query('PRAGMA table_info(evses)')->fetchAll(PDO::FETCH_ASSOC), 'name');
	$units = [];
	foreach ($db->query('SELECT * FROM evses WHERE enabled AND loadgroupid IS NOT NULL') as $u) {
		$gid = (int)$u['loadgroupid'];
		if (!isset($groups[$gid])) continue;
		$u = oev_unit_row($u);   // login decrypted (site secret_lib.php; key folder mounted read-only)
		$u['prio'] = (in_array('lb_priority', $cols) && $u['lb_priority'] !== null && $u['lb_priority'] !== '') ? (int)$u['lb_priority'] : null;
		$units[(int)$u['id']] = $u;
		$groups[$gid]['units'][] = (int)$u['id'];
	}
	// enabled units not in a load group: monitored only (read, MQTT, heartbeat), never written - see lb_write_allowed()
	foreach ($db->query('SELECT * FROM evses WHERE enabled') as $u) {
		if (isset($units[(int)$u['id']])) continue;
		$u = oev_unit_row($u);
		$u['prio'] = null; $u['monitor'] = true;
		$units[(int)$u['id']] = $u;
	}
	return [$groups, $units];
}
// write guards: every write the coordinator makes goes through one of these
function lb_claim_send($unit, $props){ if (!lb_write_allowed($unit)) { lb_log("REFUSED claim for u{$unit['id']} (monitored only, not in a load group)"); return ['ok'=>false, 'msg'=>'refused (monitor only)']; } return evse_claim($unit, $props); }
function lb_claim_release($unit){ if (!lb_write_allowed($unit)) { lb_log("REFUSED claim release for u{$unit['id']} (monitored only)"); return ['ok'=>false, 'msg'=>'refused (monitor only)']; } return evse_release_claim($unit); }
function lb_save_current($unit, $a){ if (!lb_write_allowed($unit)) { lb_log("REFUSED saved current for u{$unit['id']} (monitored only)"); return ['ok'=>false, 'msg'=>'refused (monitor only)']; } return evse_set_current($unit, $a); }

// One unit's read as a generator for evse_par_run (all units in parallel).
// -> ['ok','api','plugged','state','charging','amps','setting']
function lb_read_gen($unit, $api){
	// setting = ACTIVE current (what the pilot offers now), saved = the unit's saved current, hmax = hardware max
	$r = ['ok'=>false, 'api'=>$api, 'plugged'=>null, 'state'=>null, 'charging'=>false, 'amps'=>null, 'setting'=>null, 'saved'=>null, 'hmax'=>null];
	if ($api == 'http') {
		$s = yield from evse_par_get('status');
		$c = yield from evse_par_get('config');
		if (!$s['ok'] || !is_array($s['json'])) return $r;
		$j = $s['json'];
		$r['state'] = isset($j['state']) ? (int)$j['state'] : null;
		$r['plugged'] = isset($j['vehicle']) ? ((int)$j['vehicle'] === 1) : null;
		$r['charging'] = ($r['state'] === 3);
		$r['amps'] = isset($j['amp']) ? round($j['amp'] / 1000, 1) : null;
		$r['setting'] = isset($j['pilot']) ? (int)$j['pilot'] : null;
		$r['saved'] = ($c['ok'] && isset($c['json']['max_current_soft'])) ? (int)$c['json']['max_current_soft'] : null;
		$r['hmax'] = ($c['ok'] && isset($c['json']['max_current_hard'])) ? (int)$c['json']['max_current_hard'] : null;
		$r['ok'] = ($r['plugged'] !== null);
		return $r;
	}
	$key = $unit['evseurl'];
	$gs = yield from evse_par_gs($key);
	$ge = yield from evse_par_rapi('$GE', null, $key);
	$gc = yield from evse_par_rapi('$GC', null, $key);   // "min hwmax active saved"
	if ($gs === null) return $r;
	if ($gc !== null) { $t = preg_split('/\s+/', trim($gc)); $r['saved'] = (int)$t[3]; $r['hmax'] = (int)$t[1]; }
	$r['state'] = $gs['code'];
	$r['plugged'] = evse_gs_connected($gs);
	$r['charging'] = ($gs['code'] == 3);
	if ($ge !== null) { $t = preg_split('/\s+/', trim($ge)); $r['setting'] = (int)$t[0]; }
	if ($r['charging']) {
		$gg = yield from evse_par_rapi('$GG', null, $key);
		if ($gg !== null) { $t = explode(' ', $gg); $r['amps'] = max(0, (int)$t[0]) / 1000; }
	} else $r['amps'] = 0.0;
	$r['ok'] = true;
	return $r;
}

// amps only (fast headroom read, charging units): RAPI $GG / v5 status
function lb_amps_gen($unit, $api){
	if ($api == 'http') {
		$s = yield from evse_par_get('status');
		if (!$s['ok'] || !is_array($s['json'])) return null;
		return ['amps'=>isset($s['json']['amp']) ? round($s['json']['amp'] / 1000, 1) : null, 'charging'=>((int)($s['json']['state'] ?? 0) === 3)];
	}
	$gg = yield from evse_par_rapi('$GG', null, $unit['evseurl']);
	if ($gg === null) return null;
	$t = explode(' ', $gg);
	return ['amps'=>max(0, (int)$t[0]) / 1000, 'charging'=>null];   // charging state comes from MQTT / the 20 s read
}

// every RAPI command the coordinator sends goes through here: only whitelisted ones (lb_cmd_allowed)
function lb_rapi_send($unit, $cmd, $label){
	if (!lb_cmd_allowed_for($unit, $cmd)) { lb_log("REFUSED command '$cmd' for u{$unit['id']} (" . (lb_write_allowed($unit) ? 'not allowed' : 'not in a load group: read only') . ")"); return ['ok'=>false, 'msg'=>'refused']; }
	return evse_rapi_action($unit, $cmd, $label);
}
const LB_STATE_NAMES = [1=>'Not connected', 2=>'Connected', 3=>'Charging', 254=>'Sleeping', 255=>'Disabled'];
function lb_state_name($s){ return $s === null ? 'unknown' : (LB_STATE_NAMES[$s] ?? "state $s"); }

function lb_reset_request_globals(){
	// the site helpers keep per-request budgets/memos; one loop = one "request"
	unset($GLOBALS['__evse_rapi_budget'], $GLOBALS['__evse_rapi_memo'], $GLOBALS['__evse_rapi_stats']);
}

// ACTIVE current of a unit right now (tagged read): 2.x $GE, v5 status pilot
// (v5 applies a claim within a second or so: re-read up to 4 times until it shows $expect)
function lb_read_setting($unit, $expect = null){
	for ($i = 0; $i < 4; $i++) {
		lb_reset_request_globals();
		if (evse_is_http($unit)) { $r = evse_http($unit, 'GET', 'status'); $v = ($r['ok'] && isset($r['json']['pilot'])) ? (int)$r['json']['pilot'] : null; }
		else { $ge = rapi($unit, '$GE'); $v = (is_string($ge) && $ge !== 'Error') ? (int)preg_split('/\s+/', trim($ge))[0] : null; }
		if ($expect === null || $v === $expect || !evse_is_http($unit)) return $v;
		usleep(500000);
	}
	return $v;
}
// SAVED current: 2.x $GC 4th field, v5 config max_current_soft
function lb_read_saved($unit){
	lb_reset_request_globals();
	if (evse_is_http($unit)) { $r = evse_http($unit, 'GET', 'config'); return ($r['ok'] && isset($r['json']['max_current_soft'])) ? (int)$r['json']['max_current_soft'] : null; }
	$gc = rapi($unit, '$GC');
	if (!is_string($gc) || $gc === 'Error') return null;
	$t = preg_split('/\s+/', trim($gc)); return count($t) == 4 ? (int)$t[3] : null;
}
// unit's rating for the split: its breaker, never above its hardware max
function lb_rating($id){ global $units, $state; $b = (int)$units[$id]['breaker']; $h = (int)($state['units'][$id]['hmax'] ?? 0); return $h > 0 ? min($b ?: $h, $h) : $b; }

// ---------------------------------------------------------------- main
$mq = null; $mqTopics = [];
$state = lb_state_load();
$readings = []; $plans = []; $lastPlanSig = []; $lastRemind = 0; $actions = $state['actions'] ?? [];
$dirty = []; $nextPoll = 0; $nextCfg = 0; $nextDetect = 0; $groups = []; $units = []; $api = [];
$started = time(); $nextMq = 0; $lastWrite = []; $nextFast = 0; $lastLower = 0; $force = false;
lb_log('started' . ($DRY ? ' in test mode (no changes sent)' : '') . ($ONCE ? ' --once' : '') . ($PRETEND ? ' pretend plugged: ' . implode(',', $PRETEND) : ''));

while (true) {
	$now = time();
	if ($now >= $nextCfg) {
		try { [$groups, $units] = lb_load_config(); } catch (Exception $e) { lb_log('ERROR reading openevse.db: ' . $e->getMessage()); if ($ONCE) exit(1); sleep(10); continue; }
		$nextCfg = $now + (int)$cfg['config_reload_sec'];
	}
	if ($now >= $nextDetect) {   // web API type per unit (firmware can change)
		foreach ($units as $id => $u) { $d = evse_detect($u, true); if ($d['api'] != 'offline') $api[$id] = $d['api']; }
		$nextDetect = $now + 600;
	}

	// MQTT: connect / listen (status changes trigger an early read of that unit)
	if (!$ONCE && !empty($cfg['mqtt_host'])) {
		if (!$mq || !$mq->connected) {
			if ($now >= $nextMq) {
				$mq = new LbMqtt($cfg['mqtt_host'], (int)$cfg['mqtt_port'], 'openevse-lb-' . getmypid(), (string)getenv('MQTT_USER'), (string)getenv('MQTT_PASS'), 30,
					['topic'=>$cfg['mqtt_prefix'] . '/lb/status', 'message'=>'offline', 'retain'=>true]);
				if ($mq->connect()) {
					$mq->subscribe([$cfg['mqtt_prefix'] . '/+', $cfg['mqtt_prefix'] . '/+/state', $cfg['mqtt_prefix'] . '/+/vehicle', $cfg['mqtt_prefix'] . '/+/amp', $cfg['mqtt_prefix'] . '/+/pilot', $cfg['mqtt_prefix'] . '/+/rapi/out']);
					$mq->publish($cfg['mqtt_prefix'] . '/lb/status', $DRY ? 'dry-run' : 'live', true);
					lb_log('MQTT connected');
				} else { lb_log('MQTT connect failed, retry in 30 s'); $nextMq = $now + 30; }
			}
		}
		if ($mq && $mq->connected) {
			foreach ($mq->loop(1.0) as [$topic, $msg]) {
				if (!preg_match('#^' . preg_quote($cfg['mqtt_prefix'], '#') . '/u(\d+)(?:/(.*))?$#', $topic, $m)) continue;
				$id = (int)$m[1]; $k = $m[2] ?? '';
				$state['units'][$id]['mqtt_seen'] = $now;
				if ($k === '' && $msg === 'connected') { $dirty[$id] = $now + 2; $state['units'][$id]['mqtt_boot'] = $now; lb_log("u$id: MQTT reconnected (restart or WiFi): checking" . ($DRY ? '' : ' and re-sending its setting')); }
				if ($k === '' && $msg === 'disconnected') lb_log("u$id: MQTT disconnected");
				if ($k === 'state' || $k === 'vehicle') { $prev = $state['units'][$id]["mq_$k"] ?? null; $state['units'][$id]["mq_$k"] = $msg; if ($prev !== null && $prev !== $msg) $dirty[$id] = ($k === 'state' && !empty($state['units'][$id]['sleepfree'])) ? $now : $now + 2; }   // a sleep-free unit waking: no 2 s debounce (its 6 A were lent out)
				if ($k === 'amp') $state['units'][$id]['mq_amp'] = round((float)$msg / 1000, 1);
				// pilot above its share (controller restarted at its saved value?): read + re-apply now
				if ($k === 'pilot' && is_numeric($msg) && isset($state['units'][$id]['plan_target']) && $state['units'][$id]['plan_target'] !== null && (int)$msg > (int)$state['units'][$id]['plan_target'] && empty($state['units'][$id]['lb_paused'])) {
					if (!isset($dirty[$id]) || $dirty[$id] > $now) { $dirty[$id] = $now; lb_log("u$id: MQTT shows $msg A, above its {$state['units'][$id]['plan_target']} A: checking now"); }
				}
			}
			if (!$mq->connected) lb_log('MQTT connection lost');
		} else sleep(1);
	} elseif (!$ONCE) sleep(1);

	// headroom: amps of charging units every 5 s (cheap: only units charging at the last read)
	if (!$ONCE && $now >= $nextFast && $readings) {
		$nextFast = $now + LB_HR['fast_sec'];
		$fast = [];
		foreach ($units as $id => $u) if (isset($api[$id]) && (!empty($readings[$id]['charging']) || isset($PRETEND_CHG[$id]) || !empty($state['units'][$id]['hr']['charge_start']))) $fast[$id] = lb_amps_gen($u, $api[$id]);
		if ($fast) {
			lb_reset_request_globals();
			$fr = evse_par_run($units, $fast);
			foreach ($fast as $id => $_) {
				$x = $fr[$id] ?? null;
				if (!is_array($x)) continue;
				if ($x['amps'] !== null) $readings[$id]['amps'] = $x['amps'];
				if ($x['charging'] !== null) $readings[$id]['charging'] = $x['charging'];
				if (isset($PRETEND_CHG[$id])) { $readings[$id]['amps'] = $PRETEND_CHG[$id]; $readings[$id]['charging'] = true; }
				$ev = lb_hr_feed($id, $now);
				if ($ev) $force = true;
			}
		}
	}

	$dueDirty = array_keys(array_filter($dirty, function($t) use ($now){ return $t <= $now; }));
	if ($now < $nextPoll && !$dueDirty && !$ONCE && !$force) { lb_heartbeat(); continue; }

	// read every unit (parallel), checked like the site's pages
	lb_reset_request_globals();
	$gens = [];
	foreach ($units as $id => $u) if (isset($api[$id])) $gens[$id] = lb_read_gen($u, $api[$id]);
	$res = $gens ? evse_par_run($units, $gens) : [];
	foreach ($units as $id => $u) {
		$r = $res[$id] ?? ['ok'=>false, 'plugged'=>null, 'setting'=>null, 'state'=>null, 'amps'=>null, 'charging'=>false, 'api'=>$api[$id] ?? 'offline'];
		if (in_array($id, $PRETEND)) { $r['plugged'] = true; $r['pretend'] = true; }
		if (isset($PRETEND_CHG[$id])) { $r['charging'] = true; $r['amps'] = $PRETEND_CHG[$id]; }
		$us = &$state['units'][$id];
		if ($r['ok']) {
			$ps = $us['state'] ?? null;
			if (!$ONCE && $ps !== null && $r['state'] !== null && $ps !== $r['state'])
				lb_log("u$id {$u['name']}: state " . lb_state_name($ps) . ' -> ' . lb_state_name($r['state']) . ($ps === 254 ? ' (woke: schedule or manual)' : ($r['state'] === 254 ? ' (asleep: schedule or manual)' : '')) . ($r['plugged'] ? ', car plugged in' : ''));
			if ($r['state'] !== null) $us['state'] = $r['state'];
			if ($r['plugged'] && empty($us['plugged'])) { $us['plug_since'] = $now; if (!$ONCE) lb_log("u$id {$u['name']}: car plugged in"); }
			if (!$r['plugged'] && !empty($us['plugged'])) { unset($us['plug_since']); if (!$ONCE) lb_log("u$id {$u['name']}: car unplugged"); }
			$us['plugged'] = $r['plugged'];
			if ($r['setting'] !== null) $us['setting'] = $r['setting'];
			if ($r['saved'] !== null) $us['saved'] = $r['saved'];
			if ($r['hmax'] !== null) $us['hmax'] = $r['hmax'];
			// we paused it, but it is running again (restarted / someone re-enabled it): forget our pause
			if (!empty($us['lb_paused']) && $r['state'] !== null && $r['state'] !== (($r['api'] ?? '') === 'http' ? 254 : 255)) { lb_log("u$id {$u['name']}: no longer paused (now " . lb_state_name($r['state']) . ': restart or manual)'); unset($us['lb_paused']); }
			$us['last_ok'] = $now; $us['fails'] = 0;
		} else {
			$us['fails'] = ($us['fails'] ?? 0) + 1;
			if ($us['fails'] == 3) lb_log("u$id {$u['name']}: no reply 3 times in a row");
		}
		unset($us);
		$readings[$id] = $r;
	}
	$doPoll = ($now >= $nextPoll || $dueDirty || $ONCE);
	if (!$ONCE) foreach ($units as $id => $u) if ($readings[$id]['ok']) lb_hr_feed($id, $now);
	$dirty = array_diff_key($dirty, array_flip($dueDirty));
	$nextPoll = $now + (int)$cfg['poll_sec'];
	$force = false;

	// plan per group
	$plans = [];
	foreach ($groups as $gid => $g) {
		if (!$g['units']) continue;
		$in = []; $pausable = []; $pk = $DRY ? 'dry_paused' : 'lb_paused';
		foreach ($g['units'] as $id) {
			$r = $readings[$id]; $us = $state['units'][$id] ?? [];
			// a read that failed this time: use the last good one if it is recent (2 polls), else "unknown"
			$raw = $r['ok'] ? $r['plugged'] : ((isset($us['last_ok']) && $now - $us['last_ok'] <= 2 * (int)$cfg['poll_sec'] + 5) ? ($us['plugged'] ?? null) : null);
			$plugged = lb_effective_plugged($raw, $r['ok'] ? $r['state'] : ($us['state'] ?? null), !empty($us[$pk]));
			if (!empty($r['pretend'])) $plugged = true;
			// may be paused while idle: readable, nothing plugged in, awake (state 1) or already paused by us; never a unit asleep on its schedule
			$pausable[$id] = $r['ok'] && $raw === false && empty($r['pretend']) && ($r['state'] === 1 || !empty($us[$pk]));
			$in[] = ['id'=>$id, 'rating'=>lb_rating($id), 'plugged'=>$plugged, 'plug_since'=>$us['plug_since'] ?? (!empty($r['pretend']) ? $now : null), 'prio'=>$units[$id]['prio'], 'setting'=>$us['setting'] ?? null,
				'cap'=>$plugged ? ($us['hr']['cap'] ?? null) : null, 'charging'=>!empty($r['charging']) && $plugged, 'lbpause'=>false];
		}
		// sleep-free: a unit asleep on its schedule (not paused by us) holds 6 A uncounted while MQTT is up
		$sc = []; foreach ($g['units'] as $id) { $r = $readings[$id]; if ($r['ok'] && $r['state'] === 254 && empty($state['units'][$id][$pk]) && empty($r['pretend'])) $sc[] = $id; }
		$sf = lb_sleep_free($sc, $g['limit'], $mq && $mq->connected);
		foreach ($in as $k => $x) { $in[$k]['sleepfree'] = in_array($x['id'], $sf, true); $state['units'][$x['id']]['sleepfree'] = $in[$k]['sleepfree']; }
		$gs = &$state['groups'][$gid];
		$wasArmed = !empty($gs['parm']['armed']);
		$gs['parm'] = lb_pause_arm($gs['parm'] ?? [], lb_pause_wanted($g['limit'], $in), $now);
		$armed = !empty($gs['parm']['armed']); unset($gs);
		if ($armed !== $wasArmed) lb_log("group {$g['name']}: pausing idle chargers " . ($armed ? 'on (2+ cars charging, one below its saved amps)' : 'off (not needed for 60 s)'));
		foreach ($in as $k => $x) $in[$k]['lbpause'] = $armed && $pausable[$x['id']] && !empty($state['units'][$x['id']][$pk]);
		$p = lb_plan($g['limit'], $in);
		$settings = []; $savedv = []; $ratings = []; $chg = [];
		foreach ($g['units'] as $id) { $settings[$id] = $state['units'][$id]['setting'] ?? null; $savedv[$id] = $state['units'][$id]['saved'] ?? null; $ratings[$id] = lb_rating($id); $chg[$id] = !empty($readings[$id]['charging']); }
		$p['writes'] = lb_write_order($p, $settings, $savedv, $ratings, $chg);
		$p['pause_req'] = []; foreach ($g['units'] as $id) if (($armed && $pausable[$id]) || !empty($p['units'][$id]['pause'])) $p['pause_req'][] = $id;
		// a unit whose allocation went up gets the 3 min headroom grace (its car needs time to ramp)
		foreach ($p['units'] as $id => $x) {
			$prevT = $state['units'][$id]['plan_target'] ?? null;
			if ($x['target'] !== null && $prevT !== null && $x['target'] > $prevT && isset($state['units'][$id]['hr'])) $state['units'][$id]['hr']['last_raise'] = $now;
			$state['units'][$id]['plan_target'] = $x['target'];
		}
		$p['pauses'] = $p['pause_req'];
		$plans[$gid] = $p;

		// log the plan when it changes (and a reminder every 30 min in dry run)
		$sig = json_encode([$p['writes'], $p['pauses'], $p['warn']]);
		if ($ONCE || ($lastPlanSig[$gid] ?? '') !== $sig || ($DRY && $p['writes'] && $now - $lastRemind > 1800)) {
			$lastPlanSig[$gid] = $sig; $lastRemind = $now;
			$desc = [];
			foreach ($p['units'] as $id => $x) $desc[] = "u$id " . $x['why'];
			lb_log("group {$g['name']}: plan {$p['total']} of {$g['limit']} A: " . implode('; ', $desc));
			foreach ($p['warn'] as $w) lb_log("group {$g['name']}: WARNING $w");
			if ($DRY) foreach ($p['writes'] as [$id, $from, $to, $kind]) {
				lb_log("test mode: would set u$id {$units[$id]['name']} current $from -> ($to A)" . ($kind == 'full' ? ', saved ' . lb_rating($id) . ' A' : ''));
				$actions[] = ['t'=>$now, 'unit'=>$id, 'from'=>$from, 'to'=>$to, 'kind'=>$kind, 'dry'=>true, 'ok'=>null];
			}
			if ($DRY) foreach ($p['pauses'] as $id) if (empty($state['units'][$id]['dry_paused'])) lb_log("test mode: would pause u$id {$units[$id]['name']}: " . lb_steps_text(lb_pause_steps($api[$id] ?? 'rapi', true)));
		}
		// dry run: pause bookkeeping only
		if ($DRY) foreach ($g['units'] as $id) {
			$us = &$state['units'][$id]; $want = in_array($id, $p['pauses']);
			if ($want && empty($us['dry_paused'])) $us['dry_paused'] = $now;
			elseif (!$want && !empty($us['dry_paused'])) { lb_log("test mode: would resume u$id {$units[$id]['name']}: " . lb_steps_text(lb_pause_steps($api[$id] ?? 'rapi', false, $p['units'][$id]['target'] ?? LB_MIN_AMPS))); unset($us['dry_paused']); }
			unset($us);
		}
		if ($DRY) continue;
		// LIVE writes: lowers first; raises (and 'full' = save the rating, then the share again at once)
		// wait 5 s after the last lower, a raise at most every 30 s per unit
		$lowered = false; $skipped = false;
		foreach ($p['writes'] as [$id, $from, $to, $kind]) {
			$raise = ($kind === 'full') || $to > $from;
			if ($raise && ($lowered || $now - $lastLower < LB_HR['settle_sec'] || ($kind !== 'full' && $now - ($lastWrite[$id] ?? 0) < LB_HR['up_every']))) { $force = true; $skipped = true; continue; }
			if ($raise) $lastWrite[$id] = $now;
			$u = $units[$id]; $R = lb_rating($id); $msg = '';
			if (evse_is_http($u)) {
				$props = (!empty($state['units'][$id]['lb_paused']) ? ['state'=>'disabled'] : []) + ['charge_current'=>(int)$to, 'max_current'=>(int)$to, 'auto_release'=>false];
				$res1 = lb_claim_send($u, $props); $msg = $res1['msg'];
				if ($kind === 'full' && !empty($res1['ok'])) { $res2 = lb_save_current($u, $R); $msg .= '; saved ' . $R . ' A: ' . $res2['msg']; }
			} else {
				if ($kind === 'full') { $res2 = lb_rapi_send($u, '$SC ' . $R, 'Save current'); $msg = $res2['msg'] . '; '; }
				$res1 = lb_rapi_send($u, '$SC ' . (int)$to . ' V', 'Set current (temporary)'); $msg .= $res1['msg'];
			}
			$conf = lb_read_setting($u, (int)$to);
			$sv = ($kind === 'full') ? lb_read_saved($u) : null;
			$ok = ($conf === (int)$to) && ($kind !== 'full' || $sv === $R);
			$a = ['t'=>$now, 'unit'=>$id, 'from'=>$from, 'to'=>$to, 'kind'=>$kind, 'dry'=>false, 'ok'=>$ok];
			lb_log("set u$id {$u['name']} current $from -> ($to A)" . ($kind === 'full' ? ", saved $R A" : '') . ': ' . ($ok ? 'confirmed' : 'NOT confirmed (unit reads ' . var_export($conf, true) . ($kind === 'full' ? ', saved ' . var_export($sv, true) : '') . ')'));
			if ($conf !== null) $state['units'][$id]['setting'] = $conf;
			if ($sv !== null) $state['units'][$id]['saved'] = $sv;
			if (!$raise) { $lowered = true; $lastLower = $now; }
			$actions[] = $a;
			if (!$ok && !$raise) { lb_log("u$id: lowering not confirmed, no raises this time"); $skipped = true; break; }
		}
		// pause idle units (they draw nothing: no settle needed); resume once the others are lowered and settled
		foreach ($g['units'] as $id) {
			$us = &$state['units'][$id]; $tg = $p['units'][$id]['target'] ?? LB_MIN_AMPS;
			$want = in_array($id, $p['pauses']);
			if ($want && empty($us['lb_paused'])) {
				if (lb_do_steps($units[$id], lb_pause_steps($api[$id] ?? 'rapi', true, $us['setting'] ?? LB_MIN_AMPS), 'paused (' . ($p['units'][$id]['pause'] ? 'no room' : 'idle') . ')')) { $us['lb_paused'] = $now; $force = true; }
			} elseif (!$want && !empty($us['lb_paused'])) {
				if ($skipped || $now - $lastLower < LB_HR['settle_sec'] || ($us['setting'] ?? 99) > $tg) { $force = true; }
				elseif (lb_do_steps($units[$id], lb_pause_steps($api[$id] ?? 'rapi', false, $us['setting'] ?? $tg), 'resumed' . (!empty($readings[$id]['plugged']) ? ' (car plugged in)' : ' (not needed)'))) { unset($us['lb_paused']); $force = true; }
			}
			unset($us);
		}
		if ($force) $nextFast = min($nextFast, $now + 1);
	}
	$actions = array_slice($actions, -30);
	$state['actions'] = $actions;
	lb_state_save($state);
	lb_heartbeat();
	if ($ONCE) { foreach ($plans as $gid => $p) echo json_encode(['group'=>$gid, 'total'=>$p['total'], 'writes'=>$p['writes'], 'pauses'=>$p['pauses'], 'warn'=>$p['warn']]), "\n"; exit(0); }
}

// run pause/resume steps (live only); each step confirmed by the unit's reply
function lb_do_steps($unit, array $steps, $what){
	foreach ($steps as [$type, $arg, $delay]) {
		if ($type === 'rapi') $r = lb_rapi_send($unit, $arg, $what);
		elseif ($type === 'claim') $r = lb_claim_send($unit, $arg);
		else $r = lb_claim_release($unit);
		lb_log("$what u{$unit['id']} {$unit['name']}: " . (!empty($r['ok']) ? 'ok' : 'FAILED (' . ($r['msg'] ?? '') . ')'));
		if (empty($r['ok'])) return false;
	}
	return true;
}

// feed one unit's latest reading to its headroom state machine; logs and returns the event (or null)
function lb_hr_feed($id, $now){
	global $readings, $state, $plans, $units, $DRY;
	$r = $readings[$id] ?? null; if (!$r || !$r['ok'] || !empty($units[$id]['monitor'])) return null;
	$us = &$state['units'][$id];
	$alloc = $us['plan_target'] ?? null;          // dry run: what we WOULD have set
	if (!$DRY && isset($us['setting'])) $alloc = $us['setting'];   // live: the unit's confirmed current
	$charging = !empty($r['charging']) && !empty($r['plugged']);
	if (!$charging && empty($us['hr'])) return null;
	[$us['hr'], $ev] = lb_hr_step($us['hr'] ?? [], ['charging'=>$charging, 'amps'=>$r['amps'], 'alloc'=>$alloc], $now);
	if ($ev) lb_log(($DRY ? 'test mode: ' : '') . lb_hr_event_text($id, $units[$id]['name'], $ev));
	return $ev;
}

function lb_heartbeat(){
	global $ONCE, $DRY, $groups, $units, $readings, $plans, $state, $mq, $started, $cfg;
	if ($ONCE) return;
	static $last = 0; if (time() - $last < 5) return; $last = time();
	$out = ['time'=>time(), 'mode'=>$DRY ? 'dry-run' : 'live', 'started'=>$started, 'mqtt'=>($mq && $mq->connected), 'poll_sec'=>(int)$cfg['poll_sec'], 'groups'=>[]];
	foreach ($groups as $gid => $g) {
		$gu = []; $live = 0.0;
		foreach ($g['units'] as $id) {
			$r = $readings[$id] ?? []; $us = $state['units'][$id] ?? [];
			$amps = $r['amps'] ?? null; if ($amps !== null) $live += $amps;
			$p = $plans[$gid]['units'][$id] ?? null;
			$gu[] = ['id'=>$id, 'name'=>$units[$id]['name'], 'api'=>$r['api'] ?? null, 'ok'=>$r['ok'] ?? false, 'state'=>$r['state'] ?? null,
				'plugged'=>$r['plugged'] ?? null, 'amps'=>$amps, 'setting'=>$us['setting'] ?? null, 'target'=>$p['target'] ?? null, 'pause'=>$p['pause'] ?? false,
				'why'=>$p['why'] ?? null, 'plug_since'=>$us['plug_since'] ?? null, 'last_ok'=>$us['last_ok'] ?? null, 'mqtt_seen'=>$us['mqtt_seen'] ?? null,
				'saved'=>$us['saved'] ?? null, 'rating'=>lb_rating($id), 'lb_paused'=>!empty($us['lb_paused']) || (!empty($us['dry_paused']) && $DRY), 'cap'=>$us['hr']['cap'] ?? null, 'charging'=>$r['charging'] ?? false, 'sleepfree'=>!empty($p['sleepfree'])];
		}
		$out['groups'][] = ['id'=>$gid, 'name'=>$g['name'], 'limit'=>$g['limit'], 'live_amps'=>round($live, 1), 'active_total'=>array_sum(array_map(function($x){ return ($x['lb_paused'] || $x['sleepfree']) ? 0 : (int)$x['setting']; }, $gu)),
			'plan_total'=>$plans[$gid]['total'] ?? null, 'pause_armed'=>!empty($state['groups'][$gid]['parm']['armed']), 'warn'=>$plans[$gid]['warn'] ?? [], 'units'=>$gu];
	}
	// monitored units (not in a load group): same fields, read only
	$out['monitor'] = [];
	foreach ($units as $id => $u) {
		if (empty($u['monitor'])) continue;
		$r = $readings[$id] ?? []; $us = $state['units'][$id] ?? [];
		$out['monitor'][] = ['id'=>$id, 'name'=>$u['name'], 'api'=>$r['api'] ?? null, 'ok'=>$r['ok'] ?? false, 'state'=>$r['state'] ?? null, 'plugged'=>$r['plugged'] ?? null,
			'amps'=>$r['amps'] ?? null, 'setting'=>$us['setting'] ?? null, 'saved'=>$us['saved'] ?? null, 'last_ok'=>$us['last_ok'] ?? null, 'mqtt_seen'=>$us['mqtt_seen'] ?? null,
			'charging'=>$r['charging'] ?? false, 'monitor'=>true];
	}
	$out['actions'] = array_slice($state['actions'] ?? [], -10);
	lb_write_json('heartbeat.json', $out);
	if ($mq && $mq->connected) { static $lp = 0; if (time() - $lp >= 60) { $mq->publish($cfg['mqtt_prefix'] . '/lb/status', $out['mode'], true); $lp = time(); } }
}
