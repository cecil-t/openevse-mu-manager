<?php
/******************************************************************************
 * include_v5.php - OpenEVSE WiFi v4/v5 HTTP API client, with RAPI fallback.
 *
 * - Units running WiFi v4.x / v5.x (ESP32) are driven through the JSON HTTP API
 *   (/status, /config, /override, /claims, /limit, /schedule, /restart, /time).
 * - Older units (ESP8266 WiFi 2.x/3.x) fall back to the legacy rapi() + get_*()
 *   functions in include.php, so a mixed fleet works during the rollout.
 *
 * API reference: https://github.com/OpenEVSE/openevse_esp32_firmware/blob/master/api.yml
 * Credentials are read from openevse.db and are never printed or logged.
 ******************************************************************************/

// Load the legacy library ($evses, rapi(), get_status(), ...). It uses paths
// relative to the site folder, so include it from there.
$__v5_cwd = getcwd();
chdir(__DIR__);
include_once 'include.php';
require_once __DIR__ . '/evse_prefs.php';   // TEMP_UNIT (display only)
require_once __DIR__ . '/util_lib.php';     // h(), oev_sess_ro()
chdir($__v5_cwd);

define('EVSE_DB', 'sqlite:' . __DIR__ . '/openevse.db');
// Our client id for claims (load balancing). Must stay below 2^31: the WiFi
// firmware parses the id with String::toInt(), which saturates bigger values.
define('EVSE_CLAIM_CLIENT', 0x48530001);   // "HS" + 1 = 1213399041
define('EVSE_CONNECT_TIMEOUT', 2);          // seconds
define('EVSE_TIMEOUT', 6);                  // seconds, whole request
define('EVSE_RETRIES', 2);                  // extra tries for GETs / failed connects

/*------------------------------------------------------------------------------
 * Unit list. Same as $evses from connection.php, but can also pull in disabled
 * units by id for testing (e.g. ?with=4) without touching `enabled` in the DB.
 *----------------------------------------------------------------------------*/
function evse_units($with_ids = array()){
	global $evses;
	$units = is_array($evses) ? $evses : array();
	$with_ids = array_filter(array_map('intval', (array)$with_ids));
	if ($with_ids) {
		$dbh = new PDO(EVSE_DB);
		$st  = $dbh->prepare('SELECT * FROM evses WHERE id = ?');
		foreach ($with_ids as $id) {
			if (isset($units[$id])) continue;
			$st->execute(array($id));
			if ($row = $st->fetch(PDO::FETCH_ASSOC)) { if (PHP_SAPI !== 'cli' && function_exists('oev_unit_visible') && !oev_unit_visible($row)) continue; $row['_test'] = true; $units[$id] = oev_unit_row($row); }   // password decrypted (secret_lib.php); web: user's scope only
		}
		$dbh = null;
		uasort($units, function($a, $b){ return strcmp($a['name'], $b['name']); });
	}
	return $units;
}

// ids passed as ?with=4 or ?with=4,6
function evse_with_param(){
	if (!isset($_GET['with'])) return array();
	return array_filter(array_map('intval', explode(',', $_GET['with'])));
}

/*------------------------------------------------------------------------------
 * Low level HTTP helper. Returns
 *   ['ok'=>bool, 'code'=>int, 'json'=>mixed|null, 'raw'=>string, 'error'=>string]
 * ok = 2xx response with valid JSON (or $want_json=false).
 * GETs retry on any failure; writes retry only when the connection failed
 * (code 0), so a write is never sent twice after the unit accepted it.
 *----------------------------------------------------------------------------*/
function evse_http($unit, $method, $path, $body = null, $want_json = true){
	$url = rtrim($unit['evseurl'], '/') . '/' . ltrim($path, '/');
	$tries = 1 + EVSE_RETRIES;
	$out = array('ok'=>false, 'code'=>0, 'json'=>null, 'raw'=>'', 'error'=>'');
	$dig_retry = 0;
	for ($i = 0; $i < $tries; $i++) {
		if ($i > 0) usleep(250000);
		$was_digest = (evse_auth_mode($unit['evseurl']) === 'digest');
		$ch = curl_init($url);
		$opts = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => EVSE_CONNECT_TIMEOUT,
			CURLOPT_TIMEOUT        => EVSE_TIMEOUT,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => array('Accept: application/json'),
		);
		if ($body !== null) {
			$opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body);
			$opts[CURLOPT_HTTPHEADER] = array('Accept: application/json', 'Content-Type: application/json');
		}
		$hdrs = $opts[CURLOPT_HTTPHEADER]; unset($opts[CURLOPT_HTTPHEADER]);
		curl_setopt_array($ch, $opts);
		evse_curl_auth($ch, $unit['evseurl'], $unit['username'], $unit['password'], $hdrs, $method, $url);   // Basic / preemptive Digest (include.php)
		$raw  = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err  = curl_error($ch);
		// 401: Basic -> Digest, or a stale Digest challenge -> fresh one; resend (not a retry; a 401'd write wasn't carried out)
		$again = evse_auth_retry($ch, $unit['evseurl'], $code, $unit['username'], $unit['password']) && (!$was_digest || $dig_retry++ < 1);
		curl_close($ch);
		if ($again) { $i--; continue; }

		$out['code'] = $code;
		$out['raw']  = ($raw === false) ? '' : $raw;
		$out['error'] = $err;
		$out['json'] = null;
		if ($raw !== false && $raw !== '') {
			$j = json_decode($raw, true);
			if (json_last_error() === JSON_ERROR_NONE) $out['json'] = $j;
		}
		$is2xx = ($code >= 200 && $code < 300);
		if ($is2xx && (!$want_json || $out['json'] !== null)) { $out['ok'] = true; return $out; }
		if ($is2xx && $want_json) $out['error'] = 'invalid JSON';
		elseif ($code > 0 && $err == '') $out['error'] = 'HTTP ' . $code . (isset($out['json']['msg']) ? ': ' . $out['json']['msg'] : '');
		// 4xx is a real answer, don't retry it
		if ($code >= 400 && $code < 500) return $out;
		if ($method != 'GET' && $code != 0) return $out;
	}
	return $out;
}

// POST a WiFi 2.x form (saveemoncms, savemqtt, ...) with the unit's login (Basic or Digest, up to 3 tries).
// Returns array(ok, http code); ok = the unit answered "Saved:". The reply echoes secrets, so it is never kept.
function evse_post_2x($u, $path, $fields){
	$url = rtrim($u['evseurl'], '/') . '/' . $path; $code = 0; $resp = '';
	for ($a = 0; $a < 3; $a++) {
		$ch = curl_init($url);
		curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8, CURLOPT_POST => 1, CURLOPT_POSTFIELDS => http_build_query($fields)));
		evse_curl_auth($ch, $u['evseurl'], $u['username'], $u['password'], array('Content-Type: application/x-www-form-urlencoded'), 'POST', $url);
		$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$again = ($a < 2) && evse_auth_retry($ch, $u['evseurl'], $code, $u['username'], $u['password']);
		curl_close($ch);
		if (!$again) break;
	}
	$ok = ($code == 200 && strpos((string)$resp, 'Saved:') === 0); unset($resp);
	return array($ok, $code);
}

function evse_get($unit, $path){ $r = evse_http($unit, 'GET', $path); return $r['ok'] ? $r['json'] : null; }

/*------------------------------------------------------------------------------
 * API detection. WiFi v4+/v5 (ESP32) => 'http'. WiFi 2.x/3.x (ESP8266) =>
 * 'rapi'. No answer => 'offline'. Cached per unit for the request.
 *----------------------------------------------------------------------------*/
function evse_detect($unit, $refresh = false){
	$cache = &$GLOBALS['__evse_detect_cache'];
	if (!is_array($cache)) $cache = array();
	$key = $unit['evseurl'];
	if (!$refresh && isset($cache[$key])) return $cache[$key];
	$r = evse_http($unit, 'GET', 'config');
	$info = array('api'=>'offline', 'version'=>'', 'config'=>null, 'code'=>$r['code']);
	if ($r['ok'] && is_array($r['json'])) {
		$ver = isset($r['json']['version']) ? (string)$r['json']['version'] : '';
		$info['version'] = $ver;
		$info['config']  = $r['json'];
		$major = (int)ltrim($ver, 'vV');
		$info['api'] = ($major >= 4) ? 'http' : 'rapi';
	} elseif ($r['code'] == 200) {
		$info['api'] = 'rapi';   // answered, but not with JSON: assume old firmware
	}
	return $cache[$key] = $info;
}

// fill the detect cache from an already-fetched /config (parallel summary)
function evse_detect_prime($unit, $info){ $GLOBALS['__evse_detect_cache'][$unit['evseurl']] = $info; }

function evse_is_http($unit){ $d = evse_detect($unit); return $d['api'] == 'http'; }

function evse_state_text($code){
	switch ((int)$code) {
		case 0:   return 'Starting';
		case 1:   return 'Ready';
		case 2:   return 'Not Charging';
		case 3:   return 'Charging';
		case 4:   return 'Error: Vent Required';
		case 5:   return 'Error: Diode Check';
		case 6:   return 'Error: GFCI Fault';
		case 7:   return 'Error: Earth Ground';
		case 8:   return 'Error: Stuck Relay';
		case 9:   return 'Error: GFCI Test Failed';
		case 10:  return 'Error: Over Temperature';
		case 11:  return 'Error: Over Current';
		case 254: return 'Sleeping';
		case 255: return 'Disabled';
	}
	return 'Error';
}

function evse_elapsed_text($sec){
	$sec = (int)$sec;
	if ($sec <= 120) return $sec . ' seconds';
	if ($sec < 3600) return round($sec / 60) . ' minutes';
	if ($sec < 86400) return round($sec / 3600, 1) . ' hours';
	return intdiv($sec, 86400) . 'd ' . intdiv($sec % 86400, 3600) . 'h';
}

/*------------------------------------------------------------------------------
 * Full status in the same array shape as legacy get_status(), plus extra keys
 * ('api', 'override', 'claims', 'limit', 'schedule', ...). Returns an int HTTP
 * code on connection failure, like get_status().
 *----------------------------------------------------------------------------*/
// $pre_status: /status already fetched (array), or false if that fetch failed
function evse_status($unit, $full = true, $pre_status = null){
	$d = evse_detect($unit);
	if ($d['api'] == 'offline') return (int)$d['code'];
	if ($d['api'] == 'rapi') {
		$e = get_status($unit);
		if (is_array($e)) {
			$e['api'] = 'rapi';
			// state + plug-in from the validated $GS (same reply get_state() used: memoized in rapi());
			// unconfirmed => the session logger's last confirmed state if fresh, else unknown
			$gs = evse_rapi_gs($unit);
			if ($gs === null) evse_logger_state($unit, $e);
			else evse_apply_rapi_gs($e, $gs);
			get_voice($unit, $e);   // rebuild the spoken status with the checked values
			evse_clock_check($unit, $e);
		}
		return $e;
	}

	$s = ($pre_status !== null) ? $pre_status : evse_get($unit, 'status');
	if (!is_array($s)) return -1;
	$c = $d['config'];
	$e = array('api' => 'http');

	$e['statecode']   = (int)$s['state'];
	$e['state']       = evse_state_text($s['state']);
	$e['status_text'] = isset($s['status']) ? $s['status'] : '';        // active / disabled / sleeping
	$e['connected']   = !empty($s['vehicle']) ? 1 : 0;
	$e['elapsed_sec'] = isset($s['elapsed']) ? (int)$s['elapsed'] : 0;
	$e['elapsed']     = evse_elapsed_text($e['elapsed_sec']);
	$e['amps']        = isset($s['amp']) ? round($s['amp'] / 1000, 2) : 0;   // mA -> A
	$e['pilot']       = isset($s['pilot']) ? (int)$s['pilot'] : 0;
	$e['max_current'] = isset($s['max_current']) ? (int)$s['max_current'] : 0;
	$e['uptime']      = isset($s['uptime']) ? (int)$s['uptime'] : null;   // WiFi module uptime, seconds

	// service level: status has the live level, config 'service' 0=auto 1=L1 2=L2
	$e['level']     = !empty($s['service_level']) ? (int)$s['service_level'] : 2;
	$e['autolevel'] = (isset($c['service']) && (int)$c['service'] == 0) ? 'Auto' : 'Manual';
	// voltage: override / voltmeter / assumed for the level (volts_lib.php); v5's 'voltage'
	// is only a reading while charging (it is a 240 default until v5 reads $GG)
	list($e['volts'], $e['volts_src']) = evse_volts_info($unit, $e['level'], ((int)$s['state'] == 3 && isset($s['voltage'])) ? $s['voltage'] : null);
	$e['power']     = round($e['amps'] * $e['volts'] / 1000, 1);

	$e['check_diode']  = !empty($c['diode_check']);
	$e['check_vent']   = !empty($c['vent_check']);
	$e['check_ground'] = !empty($c['ground_check']);
	$e['check_relay']  = !empty($c['relay_check']);
	$e['check_gfci']   = !empty($c['gfci_check']);
	$e['check_temp']   = !empty($c['temp_check']);
	$e['auto_start']   = !empty($c['default_state']);

	$e['energy']      = round((isset($s['session_energy']) ? $s['session_energy'] : 0) / 1000, 1);  // Wh -> kWh
	$e['lifetimeKWH'] = round(isset($s['total_energy']) ? $s['total_energy'] : 0, 2);               // kWh

	$t = (isset($s['temp']) && is_numeric($s['temp']) && $s['temp'] !== false) ? $s['temp'] : null;
	$e['temp'] = ($t !== null && $t > -600 && $t < 2500) ? round($t / 10 * 9 / 5 + 32) : 'Error';

	// unit's local time
	$lt = isset($s['local_time']) ? strtotime($s['local_time']) : false;
	if ($lt) {
		$off = isset($s['offset']) ? $s['offset'] : '+0000';
		$dt = new DateTime('@' . $lt); $dt->setTimezone(new DateTimeZone($off));
		$e['time'] = $dt->format('m/d H:i');
		$e['tyear'] = (int)$dt->format('Y'); $e['tmonth'] = (int)$dt->format('n'); $e['tday'] = (int)$dt->format('j');
		$e['thour'] = (int)$dt->format('G'); $e['tmin'] = (int)$dt->format('i'); $e['tsec'] = (int)$dt->format('s');
		$e['epoch'] = $lt;
	} else {
		$e['time'] = 'Error';
	}

	$e['firmware']  = isset($c['firmware']) ? $c['firmware'] : '';
	$e['version']   = isset($c['version']) ? $c['version'] : '';
	$e['protocol']  = isset($c['protocol']) ? $c['protocol'] : '';
	$e['ssid']      = isset($c['ssid']) ? $c['ssid'] : '';
	$e['hostname']  = isset($c['hostname']) ? (string)$c['hostname'] : '';
	$e['srssi']     = (isset($s['srssi']) && is_numeric($s['srssi'])) ? (int)$s['srssi'] : null;
	$e['ipaddress'] = isset($s['ipaddress']) ? $s['ipaddress'] : '';
	$e['max_current_soft'] = isset($c['max_current_soft']) ? (int)$c['max_current_soft'] : null;
	$e['max_current_hard'] = isset($c['max_current_hard']) ? (int)$c['max_current_hard'] : null;
	$e['min_current_hard'] = isset($c['min_current_hard']) ? (int)$c['min_current_hard'] : 6;
	$e['manual_override']  = !empty($s['manual_override']);
	$e['gfcicount']  = isset($s['gfcicount']) ? (int)$s['gfcicount'] : 0;
	$e['nogndcount'] = isset($s['nogndcount']) ? (int)$s['nogndcount'] : 0;
	$e['stuckcount'] = isset($s['stuckcount']) ? (int)$s['stuckcount'] : 0;

	$e['timelimit'] = 'None'; $e['timelimitcode'] = 0; $e['chargelimit'] = 'None';
	$e['timerstart'] = 'None'; $e['timerend'] = 'None';
	if ($full) {
		$e['override'] = evse_get($unit, 'override');
		$e['claims']   = evse_get($unit, 'claims');
		$lim = evse_get($unit, 'limit');
		$e['limit'] = $lim;
		if (is_array($lim) && isset($lim['type'])) {
			if ($lim['type'] == 'time' && $lim['value'] > 0) {
				$m = (int)$lim['value'];
				$e['timelimit'] = ($m <= 60) ? $m . ' minutes' : round($m / 60, 2) . ' hours';
				$e['timelimitcode'] = (int)round($m / 15);
			} elseif ($lim['type'] == 'energy' && $lim['value'] > 0) {
				$e['chargelimit'] = round($lim['value'] / 1000, 1) . ' kWh';
			} elseif ($lim['type'] != 'none') {
				$e['chargelimit'] = $lim['type'] . ' ' . $lim['value'];
			}
		}
		$sch = evse_get($unit, 'schedule');
		$e['schedule'] = $sch;
		$t = evse_timer_from_schedule($sch);
		if ($t) { $e['timerstart'] = $t[0]; $e['timerend'] = $t[1]; }
	}

	evse_clock_check($unit, $e);
	get_voice($unit, $e);   // legacy text-to-speech string, works on the same keys
	return $e;
}


/*------------------------------------------------------------------------------
 * CLOCK CHECK against server time (the same clock the Time sync pushes).
 * Sets $e['clock_offset'] (unit - server, seconds, or null if unreadable),
 * $e['clock_off'] (true if more than EVSE_CLOCK_TOLERANCE s off) and
 * $e['ntp'] (v5 unit syncing from a time server).
 *----------------------------------------------------------------------------*/
define('EVSE_CLOCK_TOLERANCE', 60);   // seconds
define('EVSE_LOCAL_TZ', function_exists('oev_timezone') ? oev_timezone() : date_default_timezone_get());   // 2.8.2 controllers keep local time: the site's time zone (Settings > Preferences; blank = the server's)

// $GT on a RAPI unit ("yy m d H i s", local time), guarded against crossed
// replies: needs two good reads that agree within 3 s. Returns epoch or null.
// one $GT reply -> epoch (unit local time = the site's time zone), or null if malformed / crossed
function evse_gt_parse($r){
	$t = is_string($r) ? preg_split('/\s+/', trim($r)) : array();
	if (count($t) != 6 || !ctype_digit(implode('', $t))) return null;
	list($y, $mo, $d, $h, $mi, $se) = array_map('intval', $t);
	if (!($y <= 99 && $mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31 && $h <= 23 && $mi <= 59 && $se <= 59)) return null;
	$dt = DateTime::createFromFormat('Y-n-j G:i:s', sprintf('%d-%d-%d %d:%02d:%02d', 2000 + $y, $mo, $d, $h, $mi, $se), new DateTimeZone(EVSE_LOCAL_TZ));
	return $dt ? $dt->getTimestamp() : null;
}

function evse_rapi_time($unit){
	return evse_gt_parse(rapi($unit, '$GT'));   // sequence id checked: one reply is enough
}

function evse_clock_check($unit, &$e){
	$e['clock_offset'] = null; $e['clock_off'] = false; $e['ntp'] = false;
	if (isset($e['api']) && $e['api'] == 'http') {
		$d = evse_detect($unit);
		$e['ntp'] = !empty($d['config']['sntp_enabled']);
		if (isset($e['epoch']) && $e['epoch']) $e['clock_offset'] = (int)$e['epoch'] - time();
	} else {
		$ep = evse_rapi_time($unit);
		if ($ep !== null) $e['clock_offset'] = $ep - time();
	}
	if ($e['clock_offset'] !== null) $e['clock_off'] = abs($e['clock_offset']) > EVSE_CLOCK_TOLERANCE;
}

// Automatic clock sync state kept by the collector (clock_logic.php), read-only. null if none yet.
function evse_clock_state($unit){
	try {
		$db = oev_sess_ro();
		$st = $db->prepare('SELECT clock_checked_at, clock_offset, clock_err, clock_synced_at, clock_sync_from, clock_fail, clock_ntp, clock_note FROM unit_state WHERE unit_id = ?');
		$st->execute(array((int)$unit['id']));
		$row = $st->fetch(PDO::FETCH_ASSOC);
		$db = null;
		return ($row && $row['clock_checked_at']) ? $row : null;
	} catch (Exception $ex) { return null; }
}

// Uptime seconds -> "45m", "4h 31m", "3d 2h"
function evse_uptime_text($sec){
	$sec = max(0, (int)$sec);
	$d = intdiv($sec, 86400); $h = intdiv($sec % 86400, 3600); $m = intdiv($sec % 3600, 60);
	if ($d) return $d . 'd ' . $h . 'h';
	if ($h) return $h . 'h ' . $m . 'm';
	return $m . 'm';
}

// Uptime for the unit page: ['text'=>..., 'title'=>...]. v5: /status uptime.
// 2.x has no uptime: an upper bound from the session logger's last detected WiFi
// restart (comm_sent counter reset) in sessions.db, read-only.
function evse_uptime_info($unit, $e){
	if (isset($e['api']) && $e['api'] == 'http' && isset($e['uptime']) && $e['uptime'] !== null)
		return array('text'=>evse_uptime_text($e['uptime']), 'title'=>'WiFi module uptime');
	$boot = null;
	try {
		$db = oev_sess_ro();
		$st = $db->prepare('SELECT boot_at FROM unit_state WHERE unit_id = ?');
		$st->execute(array((int)$unit['id']));
		$v = $st->fetchColumn();
		if ($v) $boot = (int)$v;
		$db = null;
	} catch (Exception $ex) { $boot = null; }
	// boot_at for 2.x = the last poll before a detected restart, so the real uptime is shorter than this
	if ($boot && $boot <= time())
		return array('text'=>'< ' . evse_uptime_text(time() - $boot), 'title'=>'WiFi module restarted after ' . date('n/j g:i a', $boot) . ' (2.x does not report uptime)');
	return array('text'=>"\u{2014}", 'title'=>'Unknown: no WiFi reboot detected since session logging started');
}

// e.g. "−62 min", "+75 s", "−3.5 h", "+2 days"
function evse_offset_text($sec){
	$sign = ($sec < 0) ? "\u{2212}" : '+';
	$a = abs((int)$sec);
	if ($a < 120)    return $sign . $a . ' s';
	if ($a < 7200)   return $sign . round($a / 60) . ' min';
	if ($a < 172800) return $sign . round($a / 3600, 1) . ' h';
	return $sign . round($a / 86400) . ' days';
}

/*------------------------------------------------------------------------------
 * Timer <-> /schedule. The old daily start/stop timer ($ST) maps to two
 * repeating events: start => active, stop => disabled, every day.
 * (WiFi v5 imports an existing $ST timer the same way on first boot.)
 *----------------------------------------------------------------------------*/
function evse_timer_from_schedule($sch){
	if (!is_array($sch) || !$sch) return null;
	$start = null; $end = null;
	foreach ($sch as $ev) {
		if (!isset($ev['state'], $ev['time'])) continue;
		if ($ev['state'] == 'active'   && $start === null) $start = substr($ev['time'], 0, 5);
		if ($ev['state'] == 'disabled' && $end === null)   $end   = substr($ev['time'], 0, 5);
	}
	if ($start === null && $end === null) return null;
	return array($start ?: 'None', $end ?: 'None');
}

/*------------------------------------------------------------------------------
 * ACTIONS. Each returns ['ok'=>bool, 'msg'=>string]. HTTP units use the JSON
 * API; RAPI units send the same RAPI command the old pages sent.
 *----------------------------------------------------------------------------*/
function evse_result($r, $what){
	if (is_array($r) && isset($r['ok'])) {
		if ($r['ok']) return array('ok'=>true, 'msg'=>"$what: done");
		return array('ok'=>false, 'msg'=>"$what failed (" . ($r['error'] ?: 'HTTP ' . $r['code']) . ')');
	}
	return array('ok'=>false, 'msg'=>"$what failed");
}

function evse_rapi_action($unit, $cmd, $what){
	$r = rapi($unit, $cmd);
	if ($r === -1 || $r === 'Error') return array('ok'=>false, 'msg'=>"$what failed (RAPI $cmd)");
	if ($r === -2) return array('ok'=>true, 'msg'=>"$what: sent, reply not confirmed (RAPI $cmd)");
	return array('ok'=>true, 'msg'=>"$what: done (RAPI $cmd)");
}

// GET /override returns {} / [] when no manual override is set
function evse_override_active($unit){
	$o = evse_get($unit, 'override');
	return is_array($o) && !empty($o);
}

/*------------------------------------------------------------------------------
 * SLEEP / WAKE - one state-aware control (replaces Sleep/Enable + Button).
 * RAPI units (WiFi 2.x): $FS/$FE are undone within ~1 s by the controller's
 *   delay timer when they go against the timer window (they don't set the
 *   timer's manual-override flag). $F1 (front-panel short press) does, so we
 *   send $F1 - but only after re-reading the state, because $F1 just flips
 *   whatever state the unit is in. Not sent in a fault ($F1 does nothing then).
 * v5 units: manual override claims. Never PATCH /override (it only releases an
 *   existing override, so it is not a reliable toggle).
 *----------------------------------------------------------------------------*/

// Classify a state code: 'awake' (ready/connected/charging), 'asleep'
// (sleeping/disabled), 'fault', 'starting' or 'unknown'.
function evse_sleep_class($code){
	// RAPI $GS gives 2-digit hex ("fe", "0a"); legacy get_state passes it through
	if (is_string($code) && preg_match('/^[0-9a-f]{2}$/i', $code) && !ctype_digit($code)) $code = hexdec($code);
	$code = (int)$code;
	if ($code >= 1 && $code <= 3) return 'awake';
	if ($code == 254 || $code == 255) return 'asleep';
	if ($code >= 4 && $code <= 11) return 'fault';
	if ($code == 0) return 'starting';
	return 'unknown';
}

// Delay between RAPI retries: same 1/10 s as the retries in include.php.
define('EVSE_RAPI_RETRY_US', 100000);   // kept for old callers (midpoint); retries now use evse_rapi_retry_delay_us() (random 75-125 ms)

// $GS on a RAPI unit, guarded against crossed replies (the 2.x WiFi often
// returns the answer to another command: $GE "48 0001", $GU "0 12623336",
// $GT "26 10 24 ...", ...). A good reply is "ss elapsed pp vvvv": 2-hex-digit
// state, decimal elapsed seconds, 2-hex-digit pilot state, 4-hex-digit vflags
// (older firmware sends only "ss elapsed"). Up to 4 reads; needs two
// consecutive good replies that agree on state, pilot and vflags.
// Returns ['code'=>int, 'elapsed'=>int, 'pilot'=>int|null, 'vflags'=>int|null] or null.
function evse_gs_parse($r){
	$t = is_string($r) ? preg_split('/\s+/', trim($r)) : array();
	$n = count($t);
	$ok = ($n == 2 || $n == 4) && preg_match('/^[0-9a-f]{2}$/i', $t[0]) && ctype_digit($t[1]);
	if ($ok && $n == 4) $ok = preg_match('/^[0-9a-f]{2}$/i', $t[2]) && preg_match('/^[0-9a-f]{4}$/i', $t[3]);
	if (!$ok) return null;
	$gs = array('code'=>hexdec($t[0]), 'elapsed'=>(int)$t[1],
		'pilot'=>($n == 4 ? hexdec($t[2]) : null), 'vflags'=>($n == 4 ? hexdec($t[3]) : null));
	if (evse_sleep_class($gs['code']) == 'unknown' || ($gs['pilot'] !== null && $gs['pilot'] > 7)) return null;
	return $gs;
}

// With RAPI sequence ids (include.php) one reply carrying our id is our answer, so the
// old "two reads that agree" rule (up to 4 reads) is gone; rapi() retries within budget.
function evse_rapi_gs($unit){
	return evse_gs_parse(rapi($unit, '$GS'));
}

// one $GU reply -> [session Ws, lifetime Wh] or null (exactly two decimal numbers)
function evse_gu_parse($r){
	$t = is_string($r) ? preg_split('/\s+/', trim($r)) : array();
	if (count($t) != 2 || !ctype_digit($t[0]) || !ctype_digit($t[1])) return null;
	return array((int)$t[0], (int)$t[1]);
}

// Session logger's last confirmed state (sessions.db unit_state, read-only) when this
// page load couldn't confirm $GS: used only if the logger polled within 10 minutes.
function evse_logger_state($unit, &$e){
	$row = null;
	try {
		$db = oev_sess_ro();
		$st = $db->prepare('SELECT state, connected, last_poll_at FROM unit_state WHERE unit_id = ?');
		$st->execute(array((int)$unit['id']));
		$row = $st->fetch(PDO::FETCH_ASSOC);
		$db = null;
	} catch (Exception $ex) { $row = null; }
	if ($row && $row['state'] !== null && $row['last_poll_at'] && time() - (int)$row['last_poll_at'] < 600) {
		$e['statecode'] = (int)$row['state'];
		$e['state'] = evse_state_text($row['state']);
		$e['connected'] = $row['connected'] ? 1 : 0;
		$e['state_src'] = 'logger';
		$e['state_title'] = 'Unit reply not confirmed; last state the logger saw at ' . date('g:i a', (int)$row['last_poll_at']);
		return true;
	}
	$e['statecode'] = -1; $e['state'] = "\u{2014}"; $e['connected'] = 0; $e['state_src'] = 'none';
	$e['state_title'] = 'Unit reply not confirmed';
	return false;
}

// session logger's last accepted lifetime counter (kWh) if < 1 h old, else null
function evse_logger_lifetime_kwh($unit){
	try {
		$db = oev_sess_ro();
		$st = $db->prepare('SELECT lifetime_wh, lifetime_at FROM unit_state WHERE unit_id = ?');
		$st->execute(array((int)$unit['id']));
		$row = $st->fetch(PDO::FETCH_ASSOC);
		$db = null;
		if ($row && $row['lifetime_wh'] !== null && $row['lifetime_at'] && time() - (int)$row['lifetime_at'] < 3600)
			return round($row['lifetime_wh'] / 1000, 2);
	} catch (Exception $ex) {}
	return null;
}


// get_settings()'s parsing of a $GE reply (include.php), for the parallel path
function evse_parse_settings(&$unit, &$evse, $reply){
	$settings = explode(' ', (string)$reply);
	if (!isset($settings[1])) $settings[1] = '0000';
	$evse['pilot'] = $settings[0];
	if ( !is_numeric($evse['pilot']) ) { $evse['pilot'] = 0; }
	$flags = '';
	for ($i = 0; $i < 4; $i++) { $f = base_convert(substr($settings[1], $i, 1), 16, 2); $flags .= str_repeat("0", 4 - strlen($f)) . $f; }
	$flags = strrev($flags);
	$evse['check_diode']  = !boolval(substr($flags, 1,  1));
	$evse['check_vent']   = !boolval(substr($flags, 2,  1));
	$evse['check_ground'] = !boolval(substr($flags, 3,  1));
	$evse['check_relay']  = !boolval(substr($flags, 4,  1));
	$evse['auto_start']   = !boolval(substr($flags, 5,  1));
	$evse['check_gfci']   = !boolval(substr($flags, 9,  1));
	$evse['check_temp']   = !boolval(substr($flags, 10, 1));
	$evse['autolevel'] = !substr($flags, 5, 1) ? 'Auto' : 'Manual';
	$evse['level'] = substr($flags, 0, 1) + 1;
	list($evse['volts'], $evse['volts_src']) = evse_volts_info($unit, $evse['level'], $evse['volts_reported'] ?? null);
	$evse['power'] = round($evse['amps'] * $evse['volts'] / 1000, 1);
}

// EV plugged in: state B/C (2/3), or ECVF_EV_CONNECTED (0x0100) in vflags
function evse_gs_connected($gs){
	if (!is_array($gs)) return false;
	if ($gs['code'] == 2 || $gs['code'] == 3) return true;
	return $gs['vflags'] !== null && ($gs['vflags'] & 0x0100);
}

// Put a validated $GS into a legacy status array. Unconfirmed => not connected.
function evse_apply_rapi_gs(&$e, $gs){
	if (!is_array($gs)) { $e['connected'] = 0; return; }
	$e['statecode'] = $gs['code'];
	$e['state'] = evse_state_text($gs['code']);
	$e['elapsed_sec'] = $gs['elapsed'];
	$e['elapsed'] = evse_elapsed_text($gs['elapsed']);
	$e['connected'] = evse_gs_connected($gs) ? 1 : 0;
}

// State code only (Sleep/Wake checks)
function evse_rapi_state($unit){
	$gs = evse_rapi_gs($unit);
	return $gs === null ? null : $gs['code'];
}

// $want = 'sleep' or 'wake'
function evse_sleepwake($unit, $want){
	$want  = ($want == 'wake') ? 'wake' : 'sleep';
	$label = ($want == 'wake') ? 'Wake' : 'Sleep';

	if (evse_is_http($unit)) {
		if ($want == 'sleep')
			return evse_result(evse_http($unit, 'POST', 'override', array('state'=>'disabled', 'auto_release'=>true)), 'Sleep');
		$r = evse_enable($unit);
		$r['msg'] = str_replace('Enable', 'Wake', $r['msg']);
		return $r;
	}

	$code = evse_rapi_state($unit);
	if ($code === null) return array('ok'=>false, 'msg'=>"$label: couldn't read the unit's state, nothing sent");
	$cls = evse_sleep_class($code);
	if ($cls == 'fault') return array('ok'=>false, 'msg'=>"$label: unit is in a fault (" . evse_state_text($code) . "), nothing sent");
	if ($cls == 'starting') return array('ok'=>false, 'msg'=>"$label: unit is starting up, nothing sent");
	if ($want == 'sleep' && $cls == 'asleep') return array('ok'=>true, 'msg'=>'Already sleeping, nothing sent');
	if ($want == 'wake'  && $cls == 'awake')  return array('ok'=>true, 'msg'=>'Already awake, nothing sent');

	$r = rapi($unit, '$F1');   // -2 = sent, reply not ours: carried on, the state re-read below tells
	if ($r === -1 || $r === 'Error') return array('ok'=>false, 'msg'=>"$label failed (RAPI \$F1)");
	// wait past the delay timer's 1 s check, which can undo the change
	usleep(1500000);
	$after = evse_rapi_state($unit);
	if ($after === null) return array('ok'=>true, 'msg'=>"$label: sent, state not confirmed");
	$acls = evse_sleep_class($after);
	if ($want == 'wake' && $acls == 'asleep') {
		// The controller clears the timer override whenever it goes to "not
		// connected", so with no car plugged in the timer puts it straight back.
		$tm = '';
		$gd = rapi($unit, '$GD');   // sequence id + shape checked in rapi()
		$t = evse_rapi_ok($gd) ? preg_split('/\s+/', trim($gd)) : array();
		if (count($t) == 4 && implode('', $t) !== '0000') $tm = sprintf(' (schedule %02d:%02d-%02d:%02d)', $t[0], $t[1], $t[2], $t[3]);
		return array('ok'=>false, 'msg'=>"Wake: went back to sleep$tm. Outside the schedule window it only stays awake with a car plugged in");
	}
	if ($want == 'sleep' && $acls != 'asleep') return array('ok'=>false, 'msg'=>"Sleep: sent, but unit is " . evse_state_text($after));
	return array('ok'=>true, 'msg'=>"$label: done, " . ($acls == 'asleep' ? 'now sleeping' : 'now awake'));
}

function evse_enable($unit){
	if (!evse_is_http($unit)) return evse_sleepwake($unit, 'wake');
	// clear any manual override; if something else (e.g. the schedule) still
	// holds it asleep, force it awake with an override that releases on unplug
	if (evse_override_active($unit)) {
		$r = evse_http($unit, 'DELETE', 'override');
		if (!$r['ok']) return evse_result($r, 'Enable');
		usleep(500000);
	}
	$s = evse_get($unit, 'status');
	if (is_array($s) && isset($s['status']) && $s['status'] != 'active') {
		return evse_result(evse_http($unit, 'POST', 'override', array('state'=>'active', 'auto_release'=>true)), 'Enable');
	}
	return array('ok'=>true, 'msg'=>'Enable: done');
}
// "Button": state-aware toggle (no PATCH /override)
function evse_button($unit){
	if (evse_is_http($unit)) {
		$s = evse_get($unit, 'status');
		if (!is_array($s) || !isset($s['state'])) return array('ok'=>false, 'msg'=>"Couldn't read the unit's state, nothing sent");
		$cls = evse_sleep_class($s['state']);
	} else {
		$code = evse_rapi_state($unit);
		if ($code === null) return array('ok'=>false, 'msg'=>"Couldn't read the unit's state, nothing sent");
		$cls = evse_sleep_class($code);
	}
	return evse_sleepwake($unit, $cls == 'asleep' ? 'wake' : 'sleep');
}

// "Back to schedule" (v5): drop the manual override.
function evse_clear_override($unit){
	if (!evse_is_http($unit)) return array('ok'=>false, 'msg'=>'No override on RAPI units');
	// DELETE with no override set answers HTTP 500, so check first
	if (!evse_override_active($unit)) return array('ok'=>true, 'msg'=>'No override set');
	return evse_result(evse_http($unit, 'DELETE', 'override'), 'Back to schedule');
}

// Pilot / max charge current (the old $SC). Persistent soft max in /config.
function evse_set_current($unit, $amps){
	$amps = (int)round($amps);
	$cap  = (int)$unit['breaker'];
	if (!evse_is_http($unit)) {
		if ($amps < 6 || $amps > min(80, $cap ?: 80)) return array('ok'=>false, 'msg'=>"Current $amps A out of range");
		return evse_rapi_action($unit, '$SC ' . $amps, 'Set current');
	}
	$d = evse_detect($unit);
	$min = isset($d['config']['min_current_hard']) ? (int)$d['config']['min_current_hard'] : 6;
	$max = isset($d['config']['max_current_hard']) ? (int)$d['config']['max_current_hard'] : 80;
	if ($cap > 0) $max = min($max, $cap);
	if ($amps < $min || $amps > $max) return array('ok'=>false, 'msg'=>"Current $amps A out of range ($min-$max A)");
	$r = evse_result(evse_http($unit, 'POST', 'config', array('max_current_soft'=>$amps)), "Set current to $amps A");
	evse_detect($unit, true);
	return $r;
}

// Service level: 1, 2 or 'A' (auto). Config 'service' 0=auto 1=L1 2=L2.
function evse_set_level($unit, $level){
	$level = strtoupper(trim((string)$level));
	if (!in_array($level, array('1', '2', 'A'), true)) return array('ok'=>false, 'msg'=>'Level must be 1, 2 or A');
	if (!evse_is_http($unit)) return evse_rapi_action($unit, '$SL ' . $level, 'Set level');
	$svc = ($level == 'A') ? 0 : (int)$level;
	$r = evse_result(evse_http($unit, 'POST', 'config', array('service'=>$svc)), 'Set level ' . $level);
	evse_detect($unit, true);
	return $r;
}

// Charge limits. v5 keeps ONE limit (time OR energy) and auto-releases it on
// unplug. 0 clears the limit.
function evse_set_energy_limit($unit, $kwh){
	$kwh = (int)$kwh;
	if (!evse_is_http($unit)) return evse_rapi_action($unit, '$SH ' . $kwh, 'Energy limit');
	if ($kwh <= 0) return evse_clear_limit($unit, 'energy');
	return evse_result(evse_http($unit, 'POST', 'limit', array('type'=>'energy', 'value'=>$kwh * 1000, 'auto_release'=>true)), "Energy limit $kwh kWh");
}

// $code = 15-minute steps, like the old $S3 (0 = none)
function evse_set_time_limit($unit, $code){
	$code = (int)$code;
	if (!evse_is_http($unit)) return evse_rapi_action($unit, '$S3 ' . $code, 'Time limit');
	if ($code <= 0) return evse_clear_limit($unit, 'time');
	return evse_result(evse_http($unit, 'POST', 'limit', array('type'=>'time', 'value'=>$code * 15, 'auto_release'=>true)), 'Time limit ' . ($code * 15) . ' min');
}

// Clear the limit (only if it is of $type, when given)
function evse_clear_limit($unit, $type = null){
	$cur = evse_get($unit, 'limit');
	if (!is_array($cur) || !isset($cur['type'])) return array('ok'=>true, 'msg'=>'No limit set');
	if ($type !== null && $cur['type'] != $type) return array('ok'=>true, 'msg'=>"No $type limit set");
	return evse_result(evse_http($unit, 'DELETE', 'limit'), 'Clear limit');
}

// Daily start/stop timer, "HH:MM" each. Both empty or 00:00 = clear.
function evse_set_timer($unit, $start, $end){
	$ok = function($t){ return preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $t); };
	$start = trim($start) ?: '00:00'; $end = trim($end) ?: '00:00';
	if (!$ok($start) || !$ok($end)) return array('ok'=>false, 'msg'=>'Times must be HH:MM');
	if (!evse_is_http($unit)) return evse_rapi_action($unit, '$ST ' . str_replace(':', ' ', $start) . ' ' . str_replace(':', ' ', $end), 'Set schedule');
	// remove existing events first
	$sch = evse_get($unit, 'schedule');
	if (is_array($sch)) foreach ($sch as $ev) if (isset($ev['id'])) evse_http($unit, 'DELETE', 'schedule/' . (int)$ev['id']);
	if ($start == '00:00' && $end == '00:00') return array('ok'=>true, 'msg'=>'Schedule cleared');
	$days = array('sunday','monday','tuesday','wednesday','thursday','friday','saturday');
	$ev = array(
		array('id'=>1, 'state'=>'active',   'time'=>$start . ':00', 'days'=>$days),
		array('id'=>2, 'state'=>'disabled', 'time'=>$end . ':00',   'days'=>$days),
	);
	return evse_result(evse_http($unit, 'POST', 'schedule', $ev), "Schedule $start-$end");
}

// Old $S1 time sync. v5 units normally use NTP; only push the time when NTP is off.
function evse_sync_time($unit, $epoch = null){
	if ($epoch === null) $epoch = time();
	if (!evse_is_http($unit)) return evse_rapi_action($unit, '$S1 ' . date('y n j G i s', $epoch), 'Sync time');
	$d = evse_detect($unit);
	if (!empty($d['config']['sntp_enabled'])) return array('ok'=>true, 'msg'=>'Unit uses NTP, nothing to sync');
	$tz = isset($d['config']['time_zone']) ? $d['config']['time_zone'] : 'UTC0';
	return evse_result(evse_http($unit, 'POST', 'time', array('sntp_enabled'=>false, 'time_zone'=>$tz, 'time'=>gmdate('Y-m-d\TH:i:s\Z', $epoch))), 'Sync time');
}

// Reboot. 'evse' = controller (old $FR), 'gateway' = WiFi module.
function evse_restart($unit, $device = 'evse'){
	if (!evse_is_http($unit)) return evse_rapi_action($unit, '$FR', 'Reboot');
	$device = ($device == 'gateway') ? 'gateway' : 'evse';
	return evse_result(evse_http($unit, 'POST', 'restart', array('device'=>$device)), 'Restart ' . $device);
}

/*------------------------------------------------------------------------------
 * CLAIMS - used by the load balancer (loadbalancer/coordinator/coordinator.php).
 * Our claim sits at API priority 500: above timers/divert, below manual
 * override (1000) and limits. Use max_current to cap a unit's share of the
 * load group; the unit still honours charge_current <= max_current <= soft max.
 *----------------------------------------------------------------------------*/
function evse_claim($unit, $props, $client = EVSE_CLAIM_CLIENT){
	if (!evse_is_http($unit)) return array('ok'=>false, 'msg'=>'Claims need WiFi v4+');
	$allowed = array_intersect_key($props, array_flip(array('state','charge_current','max_current','auto_release')));
	return evse_result(evse_http($unit, 'POST', 'claims/' . (int)$client, $allowed), 'Claim');
}
function evse_release_claim($unit, $client = EVSE_CLAIM_CLIENT){
	if (!evse_is_http($unit)) return array('ok'=>false, 'msg'=>'Claims need WiFi v4+');
	$r = evse_http($unit, 'DELETE', 'claims/' . (int)$client);
	if ($r['code'] == 404) return array('ok'=>true, 'msg'=>'No claim to release');
	return evse_result($r, 'Release claim');
}

/*------------------------------------------------------------------------------
 * UNIT PAGE EXTRAS: WiFi signal, hostname, fault counts, last session.
 * v5 units already have srssi / hostname / counts from evse_status(). RAPI
 * units: the WiFi module's /status + /config JSON, and $GF for the counts.
 *----------------------------------------------------------------------------*/
// $GF "gfi nognd stuck" (hex), guarded against crossed replies: exactly three
// 1-4 digit hex numbers, two consecutive reads agreeing. Returns [g, n, s] or null.
function evse_rapi_faults($unit){
	$r = rapi($unit, '$GF');   // sequence id + "3 hex numbers" shape checked in rapi()
	if (!evse_rapi_ok($r)) return null;
	return array_map('hexdec', preg_split('/\s+/', trim($r)));
}

function evse_unit_extras($unit, &$e){
	if (!isset($e['srssi'])) $e['srssi'] = null;
	if (!isset($e['hostname'])) $e['hostname'] = '';
	$e['faults_src'] = 'status';
	if (isset($e['api']) && $e['api'] == 'http') return;
	$st = rapi($unit, 'status');
	$st = is_string($st) ? json_decode($st, true) : null;
	if (is_array($st)) {
		if (isset($st['srssi']) && is_numeric($st['srssi'])) $e['srssi'] = (int)$st['srssi'];
		foreach (array('gfcicount', 'nogndcount', 'stuckcount') as $k) if (isset($st[$k]) && is_numeric($st[$k])) $e[$k] = (int)$st[$k];
	}
	$f = evse_rapi_faults($unit);
	if ($f !== null) { list($e['gfcicount'], $e['nogndcount'], $e['stuckcount']) = $f; $e['faults_src'] = 'controller'; }
	$cf = rapi($unit, 'config');
	$cf = is_string($cf) ? json_decode($cf, true) : null;
	if (is_array($cf) && isset($cf['hostname'])) $e['hostname'] = (string)$cf['hostname'];
}

// newest session for the unit from sessions.db (read-only), or null
// newest session for the unit: $open = true -> the in-progress one, false -> last completed one
function evse_last_session($unit, $open = false){
	try {
		$db = oev_sess_ro();
		// seg_seconds: charging time so far from the logger's segments (open segment counted to now)
		$st = $db->prepare('SELECT id, plug_in_at, kwh, kwh_meter, open, charge_seconds,
			(SELECT coalesce(sum(CASE WHEN g.open = 1 THEN max(0, ? - g.start_at) ELSE g.seconds END), 0) FROM segments g WHERE g.session_id = sessions.id) seg_seconds,
			(SELECT count(*) FROM segments g WHERE g.session_id = sessions.id AND g.open = 1) seg_open
			FROM sessions WHERE unit_id = ? AND open = ? ORDER BY plug_in_at DESC LIMIT 1');
		$st->execute(array(time(), (int)$unit['id'], $open ? 1 : 0));
		$row = $st->fetch(PDO::FETCH_ASSOC);
		$db = null;
		return $row ?: null;
	} catch (Exception $ex) { return null; }
}

/*------------------------------------------------------------------------------
 * PARALLEL MAIN-PAGE SUMMARY. All units are queried at once with curl_multi,
 * one request in flight per unit at a time (never two to the same unit: that
 * makes crossed replies worse). Each unit is a generator that yields its next
 * request; the runner works in rounds: send every ready request together,
 * then hand each reply back to its unit. A unit's retry waits only on its own
 * delay (random 75-125 ms, as in include.php), so retries on one unit don't hold up the
 * others. Validation is the same as the one-at-a-time functions above (shared
 * parsers evse_gs_parse / evse_gu_parse / evse_gt_parse).
 *----------------------------------------------------------------------------*/

// One request as a curl handle. RAPI: same URL/auth/timeouts as rapi() in
// include.php (plus a 5 s overall limit so one dead unit can't stall the page).
function evse_par_handle($unit, $req){
	$auth = ($unit['username'] != '' && $unit['password'] != '');
	// Digest (2.9.x) units: one reused handle per unit (see include.php); all options set every time
	$ch = ($auth && evse_auth_mode($unit['evseurl']) === 'digest') ? evse_curl_shared($unit['evseurl']) : curl_init();
	if ($req['type'] == 'rapi') {
		$cmd = evse_rapi_with_seq($req['cmd'], $req['seq'] ?? 0);   // sequence id: see include.php
		evse_rapi_stat('sent');
		$u = $unit['evseurl'] . 'r?rapi=' . urlencode(evse_rapi_wire($cmd)); $hdrs = array();
		curl_setopt_array($ch, array(CURLOPT_URL => $u, CURLOPT_HTTPGET => true,
			CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_TIMEOUT => 5));
	} else {
		$u = rtrim($unit['evseurl'], '/') . '/' . ltrim($req['path'], '/'); $hdrs = array('Accept: application/json');
		curl_setopt_array($ch, array(CURLOPT_URL => $u, CURLOPT_HTTPGET => true,
			CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => EVSE_CONNECT_TIMEOUT,
			CURLOPT_TIMEOUT => EVSE_TIMEOUT));
	}
	evse_curl_auth($ch, $unit['evseurl'], $unit['username'], $unit['password'], $hdrs, 'GET', $u);   // Basic / preemptive Digest (include.php); headers either way
	return $ch;
}

// Reply in the same shape as rapi() (string, 'Error' or -1) / evse_http() (array).
function evse_par_result($req, $raw, $code){
	if ($req['type'] == 'rapi') {
		if ($code != 200 || !is_string($raw)) return -1;
		// same checks as rapi(): our sequence id, checksum, and the command's reply shape
		$rep = evse_rapi_parse_reply($raw, $req['seq'] ?? 0);
		if ($rep === null) { evse_rapi_stat('rejected'); return -1; }
		if (!$rep['ok']) return 'Error';
		if (!evse_rapi_shape_ok($req['cmd'], $rep['payload'])) { evse_rapi_stat('rejected'); return -1; }
		return $rep['payload'];
	}
	$out = array('ok'=>false, 'code'=>(int)$code, 'json'=>null);
	if (is_string($raw) && $raw !== '') { $j = json_decode($raw, true); if (json_last_error() === JSON_ERROR_NONE) $out['json'] = $j; }
	$out['ok'] = ($code >= 200 && $code < 300 && $out['json'] !== null);
	return $out;
}

// Run generators (key => Generator yielding ['type'=>'rapi','cmd'=>..] or
// ['type'=>'http','path'=>..], optional 'delay' in us). Returns key => getReturn().
function evse_par_run($units, $gens){
	$mh = curl_multi_init();
	$ready = array(); $out = array(); $authre = array();   // auth resends per generator step
	foreach ($gens as $k => $g) {
		if ($g->valid()) $ready[$k] = microtime(true) + (($g->current()['delay'] ?? 0) / 1e6);
		else $out[$k] = $g->getReturn();
	}
	while ($ready) {
		$now = microtime(true);
		$batch = array();
		foreach ($ready as $k => $t) if ($t <= $now + 0.001) $batch[] = $k;
		if (!$batch) { usleep((int)max(1000, (min($ready) - $now) * 1e6)); continue; }
		$hs = array();
		$reqs = array();
		foreach ($batch as $k) {
			$reqs[$k] = $gens[$k]->current();
			if ($reqs[$k]['type'] == 'rapi') $reqs[$k]['seq'] = EVSE_RAPI_SEQ ? evse_rapi_seq_next() : 0;
			$hs[$k] = evse_par_handle($units[$k], $reqs[$k]); curl_multi_add_handle($mh, $hs[$k]);
		}
		do {
			$st = curl_multi_exec($mh, $running);
			if ($running && curl_multi_select($mh, 0.5) == -1) usleep(10000);
		} while ($running && $st == CURLM_OK);
		foreach ($hs as $k => $ch) {
			$raw = curl_multi_getcontent($ch);
			$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			curl_multi_remove_handle($mh, $ch);
			// 401: Basic -> Digest (2.9.x), or a stale cached Digest challenge -> fresh one; send the same request
			// again next round (at most 2 resends per step: Basic -> Digest -> refreshed Digest)
			$again = (($authre[$k] ?? 0) < 2) && evse_auth_retry($ch, $units[$k]['evseurl'], $code, $units[$k]['username'], $units[$k]['password']);
			if (!evse_curl_is_shared($ch)) curl_close($ch);
			if ($again) { $authre[$k] = ($authre[$k] ?? 0) + 1; $ready[$k] = microtime(true); continue; }
			$authre[$k] = 0;
			$g = $gens[$k];
			$g->send(evse_par_result($reqs[$k], $raw, $code));
			if ($g->valid()) $ready[$k] = microtime(true) + (($g->current()['delay'] ?? 0) / 1e6);
			else { unset($ready[$k]); $out[$k] = $g->getReturn(); }
		}
	}
	curl_multi_close($mh);
	return $out;
}

// Generator steps. Same tries / delays / agreement rules as the sequential versions.
function evse_par_get($path){   // evse_http GET: up to 1+EVSE_RETRIES tries, 250 ms apart, no retry on 4xx
	for ($i = 0; $i < 1 + EVSE_RETRIES; $i++) {
		$r = (yield array('type'=>'http', 'path'=>$path, 'delay'=>($i > 0 ? 250000 : 0)));
		if ($r['ok'] || ($r['code'] >= 400 && $r['code'] < 500)) return $r;
	}
	return $r;
}
// One RAPI read, checked like rapi() (sequence id + shape), with the same retry rules:
// at most EVSE_RAPI_CMD_RETRIES retries, each taken from the page's budget. Returns
// $parse(payload), the payload if no parser, or null.
function evse_par_rapi($cmd, $parse = null, $key = ''){
	for ($i = 0; $i <= EVSE_RAPI_CMD_RETRIES; $i++) {
		if ($i > 0) { if (!evse_rapi_budget_take($key)) return null; evse_rapi_stat('retries'); }
		$r = (yield array('type'=>'rapi', 'cmd'=>$cmd, 'delay'=>($i > 0 ? evse_rapi_retry_delay_us() : 0)));
		if (evse_rapi_ok($r)) { $v = $parse ? $parse($r) : $r; if ($v !== null) return $v; }
	}
	return null;
}
function evse_par_gs($key = ''){ return yield from evse_par_rapi('$GS', 'evse_gs_parse', $key); }
function evse_par_gu($key = ''){ return yield from evse_par_rapi('$GU', 'evse_gu_parse', $key); }
function evse_par_gt($key = ''){ return yield from evse_par_rapi('$GT', 'evse_gt_parse', $key); }

// One unit's summary, same result as evse_summary().
function evse_par_summary_gen($unit){
	$r = yield from evse_par_get('config');
	$info = array('api'=>'offline', 'version'=>'', 'config'=>null, 'code'=>$r['code']);
	if ($r['ok'] && is_array($r['json'])) {
		$ver = isset($r['json']['version']) ? (string)$r['json']['version'] : '';
		$info['version'] = $ver; $info['config'] = $r['json'];
		$info['api'] = ((int)ltrim($ver, 'vV') >= 4) ? 'http' : 'rapi';
	} elseif ($r['code'] == 200) {
		$info['api'] = 'rapi';
	}
	evse_detect_prime($unit, $info);

	if ($info['api'] == 'http') {
		$s = yield from evse_par_get('status');
		$st = evse_status($unit, false, ($s['ok'] && is_array($s['json'])) ? $s['json'] : false);
		if (is_array($st)) {   // schedule for the list page: GET /schedule (v5's equivalent of $GD)
			$sc = yield from evse_par_get('schedule');
			$t = ($sc['ok'] && is_array($sc['json'])) ? evse_timer_from_schedule($sc['json']) : null;
			$st['sched'] = ($sc['ok'] && is_array($sc['json'])) ? ($t ? $t : false) : null;
			if (isset($info['config']['max_current_soft'])) $st['saved_current'] = (int)$info['config']['max_current_soft'];
			if ($st['sched'] === false && !empty($st['connected'])) {   // no schedule + car plugged in: session limit for the list page (GET /limit: time min / energy Wh / soc % / range)
				$lm = yield from evse_par_get('limit');
				$st['lim'] = ($lm['ok'] && is_array($lm['json'])) ? evse_lim_from_v5($lm['json']) : null;
			}
		}
		return $st;
	}
	$e = array('api' => $info['api']);
	if ($info['api'] == 'offline') { $e['state'] = 'Connection Error'; $e['statecode'] = -1; $e['lifetimeKWH'] = 0; $e['connected'] = 0; return $e; }

	$gs = yield from evse_par_gs($unit['evseurl']);
	if ($gs === null) evse_logger_state($unit, $e);   // unconfirmed: logger's last state or a dash, never a guess
	else evse_apply_rapi_gs($e, $gs);

	$gu = yield from evse_par_gu($unit['evseurl']);
	if ($gu !== null) { $e['energy'] = round($gu[0] / 1000 / 60 / 60, 1); $e['lifetimeKWH'] = round($gu[1] / 1000, 2); }
	else { $e['energy'] = 0; $e['lifetimeKWH'] = evse_logger_lifetime_kwh($unit); }

	if ($e['statecode'] == 3) {   // like get_charge() + get_settings()
		$c = yield from evse_par_rapi('$GG', null, $unit['evseurl']);
		$charge = explode(' ', (string)$c);
		$e['amps'] = ($c === null) ? null : max(0, intval($charge[0])) / 1000;
		$e['volts_reported'] = ($c !== null && isset($charge[1]) && is_numeric($charge[1])) ? intval($charge[1]) : null;
	}
	// active + saved current ($GC "min hwmax active saved", every unit: the list page shows both); kW only while charging ($GE), as before
	$gc = yield from evse_par_rapi('$GC', null, $unit['evseurl']);
	if ($gc !== null) { $g0 = preg_split('/\s+/', trim((string)$gc)); if (count($g0) == 4) { $e['pilot'] = (int)$g0[2]; $e['saved_current'] = (int)$g0[3]; } }
	if ($e['statecode'] == 3 && $e['amps'] !== null) { $ge = yield from evse_par_rapi('$GE', null, $unit['evseurl']); if ($ge !== null) evse_parse_settings($unit, $e, $ge); }   // else no kW shown

	$ep = yield from evse_par_gt($unit['evseurl']);   // clock check, as evse_clock_check()
	$e['clock_offset'] = ($ep !== null) ? $ep - time() : null; $e['ntp'] = false;
	$e['clock_off'] = ($e['clock_offset'] !== null) && abs($e['clock_offset']) > EVSE_CLOCK_TOLERANCE;

	// schedule ($GD), tagged + shape-checked with the normal retry rules; if it can't be
	// confirmed, the last good $GD (same cache as rapi_cached() on unit.php, up to a day old)
	$gd = yield from evse_par_rapi('$GD', null, $unit['evseurl']);
	$e['sched'] = evse_sched_from_gd(evse_par_cached($unit['evseurl'], '$GD', $gd, 86400));
	// no schedule + car plugged in: session limits for the list page ($GH kWh, $G3 time in 15-min steps); null = not readable
	if ($e['sched'] === false && !empty($e['connected'])) {
		$gh = yield from evse_par_rapi('$GH', null, $unit['evseurl']);
		$g3 = yield from evse_par_rapi('$G3', null, $unit['evseurl']);
		$e['lim'] = evse_lim_from_rapi($gh, $g3);
	}
	return $e;
}

// Session limit as short labels for the list page: ['20 kWh', '2:00', '80%', '250 rng'];
// false = no limit, null = not readable
function evse_lim_fmt_min($m){ return sprintf('%d:%02d', intdiv((int)$m, 60), (int)$m % 60); }
function evse_lim_from_rapi($gh, $g3){
	if ($gh === null && $g3 === null) return null;
	$o = array();
	if ($gh !== null && is_numeric($k = explode(' ', trim((string)$gh))[0]) && (int)$k > 0) $o[] = (int)$k . ' kWh';
	if ($g3 !== null && is_numeric($t = explode(' ', trim((string)$g3))[0]) && (int)$t > 0 && (int)$t < 255) $o[] = evse_lim_fmt_min((int)$t * 15);
	return $o ? $o : (($gh === null || $g3 === null) ? null : false);
}
function evse_lim_from_v5($l){
	if (!isset($l['type'], $l['value']) || $l['type'] === 'none' || (float)$l['value'] <= 0) return false;
	$v = $l['value'];
	switch ($l['type']) {
		case 'time':   return array(evse_lim_fmt_min($v));
		case 'energy': return array(rtrim(rtrim(number_format($v / 1000, 1, '.', ''), '0'), '.') . ' kWh');
		case 'soc':    return array((int)$v . '%');
		case 'range':  return array((int)$v . ' rng');
	}
	return array($l['type'] . ' ' . $v);
}

// rapi_cached() for the parallel path: store a good reply, else fall back to the cached one
function evse_par_cached($url, $cmd, $r, $maxage){
	$f = evse_rapi_cache_file($url);
	$c = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
	if (!is_array($c)) $c = array();
	if (is_string($r)) {
		if (!isset($c[$cmd]) || $c[$cmd][1] !== $r || time() - $c[$cmd][0] > 300) { $c[$cmd] = array(time(), $r); @file_put_contents($f, json_encode($c), LOCK_EX); }
		return $r;
	}
	return (isset($c[$cmd]) && time() - (int)$c[$cmd][0] <= $maxage) ? $c[$cmd][1] : null;
}
// "0 5 14 0" -> ['00:05', '14:00']; "0 0 0 0" -> false (no schedule); unreadable -> null
function evse_sched_from_gd($gd){
	if (!is_string($gd) || !preg_match('/^(\d+) (\d+) (\d+) (\d+)$/', trim($gd), $m)) return null;
	if ($m[1] + $m[2] + $m[3] + $m[4] == 0) return false;
	return array(sprintf('%02d:%02d', $m[1], $m[2]), sprintf('%02d:%02d', $m[3], $m[4]));
}

// key => summary for every unit, all queried in parallel.
function evse_summary_all($units){
	$gens = array();
	foreach ($units as $k => $u) $gens[$k] = evse_par_summary_gen($u);
	return evse_par_run($units, $gens);
}

// Current shown everywhere: '48 A' = the saved setting; '(34 A)' = a temporary active value (below the saved one)
function evse_amps_label($active, $saved = null){
	if ($active === null) return "\u{2014}";
	return ($saved !== null && (int)$active < (int)$saved) ? '(' . (int)$active . ' A)' : (int)$active . ' A';
}
