<?php
include 'connection.php';
require_once __DIR__ . '/volts_lib.php';   // the one voltage rule (assumed L1/L2 volts, overrides)

/******************************************************************************
 * RAPI SEQUENCE IDS - COLLISION WORKAROUND for the 2.x WiFi modules
 * (OpenEVSE ESP8266_WiFi_v2.x 2.8.2 and 2.9.1, the last ESP8266 release).
 * The WiFi module polls the controller itself ($GS every few seconds while
 * sleeping, $GE/$GG/$GP/$GU... while charging) over the same serial line our
 * /r?rapi= requests use, and /r sometimes hands us the module's own answer
 * instead of ours (seen: "$OK fe 500 03 0500" returned for $GV, $GE, $GP...),
 * which is where "fe" as a firmware version and the 7 / 26 / 48 states came from.
 *
 * Fix: every RAPI command we send carries a sequence id (" :SS", 2 hex digits,
 * never 00) before the checksum; the controller (RAPI 3.0.0+, e.g. firmware
 * 7.1.3 / RAPI 5.1.3 and 8.2.2 / 5.2.1) echoes it at the end of its reply:
 *   sent  "$GS :3A^58"    reply "$OK fe 500 03 0500 :3A^xx"
 * A reply is ours only if it carries the id we just sent. The 2.8.2 /r handler
 * passes both through unchanged (it adds its own checksum after ours; the
 * controller checks the first one), tested read-only.
 * 2.9.1 does the same: its RapiSender tokenizer splits " :3A" off as an extra
 * token at the space (the seq-id check only fires on a ':' inside a token), and /r
 * returns the raw reply line; 2.9.1 also queues /r behind its own polling
 * (sendCmdSync), so crossed replies should be rare there.
 * Sources: open_evse firmware/open_evse/rapi_proc.h ("checksum + sequence id
 * (v3.0.0+)") + rapi_proc.cpp (processCmd / response / appendSequenceId), tag D7.1.3:
 *   https://github.com/lincomatic/open_evse/blob/D7.1.3/firmware/open_evse/rapi_proc.h
 *   https://github.com/lincomatic/open_evse/blob/D7.1.3/firmware/open_evse/rapi_proc.cpp
 * WiFi 2.8.2 /r handler + RapiSender:
 *   https://github.com/OpenEVSE/ESP8266_WiFi_v2.x/blob/2.8.2/src/web_server.cpp (handleRapi)
 *   https://github.com/OpenEVSE/ESP8266_WiFi_v2.x/blob/2.8.2/src/RapiSender.cpp
 *
 * On top of that every reply's shape is checked against what the command
 * returns. Bad replies are never used: reads get at most EVSE_RAPI_CMD_RETRIES
 * retries, within a per-request budget (EVSE_RAPI_RETRY_BUDGET) so a page load
 * stays fast; then the caller shows the last good value or a dash.
 * Writes are never resent.
 ******************************************************************************/
define('EVSE_RAPI_SEQ', true);                                   // false = old behaviour (no ids)
define('EVSE_RAPI_RETRY_DELAY_US', 125000);                      // upper bound of the retry wait
// Retry wait: random 75-125 ms (in a stress test 50-700 ms all did equally well), drawn per retry, so our retries don't fall in step
// with the WiFi module's own polling timer (a fixed delay could keep hitting it).
function evse_rapi_retry_delay_us(){ return random_int(75000, 125000); }
define('EVSE_RAPI_CMD_RETRIES', PHP_SAPI === 'cli' ? 2 : 1);     // per read command
define('EVSE_RAPI_RETRY_BUDGET', PHP_SAPI === 'cli' ? 30 : 3);   // per unit, per page load / collector run

/******************************************************************************
 * HTTP AUTH: 2.8.2 takes preemptive Basic; 2.9.1 (ESPAsyncWebServer 1.2.3) answers
 * Basic with 401 + a Digest challenge and only accepts Digest (verified on a
 * 2.9.1 unit; github.com/OpenEVSE/ESP8266_WiFi_v2.x/issues/265). Basic stays the
 * default (one request per call); a unit that 401s a Basic request with a Digest
 * challenge is switched to Digest, remembered per unit URL (process + temp file).
 *
 * Preemptive Digest: for Digest units we build the Authorization
 * header ourselves and send it with the first request, so there's no 401
 * challenge round trip (~30 ms per request). The server's last challenge (realm,
 * nonce, opaque, qop - no credentials) is kept in a shared per-unit cache file in
 * the temp dir (pages, logger, push/clock logic all share it; flock'd), with nc
 * counted up per request and a fresh cnonce each time. ESPAsyncWebServer 1.2.3
 * doesn't track nonces (it only recomputes the hash), but if a unit ever answers
 * 401 we take its new challenge, update the cache and send the request once more.
 ******************************************************************************/
function evse_auth_file($url){ return sys_get_temp_dir() . '/oev_auth_' . md5($url) . '.txt'; }
function evse_auth_mode($url){
	$c = &$GLOBALS['__evse_auth_mode'];
	if (!is_array($c)) $c = array();
	if (!isset($c[$url])) { $m = @file_get_contents(evse_auth_file($url)); $c[$url] = ($m === 'digest') ? 'digest' : 'basic'; }
	return $c[$url];
}
function evse_auth_set($url, $mode){
	if (evse_auth_mode($url) === $mode) return;
	$GLOBALS['__evse_auth_mode'][$url] = $mode;
	$f = evse_auth_file($url); @file_put_contents($f, $mode, LOCK_EX); @chmod($f, 0666);
}

// Digest challenge cache (per unit URL): {"realm","nonce","opaque","qop","nc","at"}
function evse_digest_file($url){ return sys_get_temp_dir() . '/oev_digest_' . md5($url) . '.json'; }
// open + lock the cache file; null if it can't be opened (then the process copy is used)
function evse_digest_open($url){
	$f = evse_digest_file($url);
	$new = !is_file($f);
	$fh = @fopen($f, 'c+');
	if (!$fh) return null;
	if ($new) @chmod($f, 0666);   // http (pages) and the logger both write it
	flock($fh, LOCK_EX);
	return $fh;
}
function evse_digest_put($fh, $c){ ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($c)); fflush($fh); }
// WWW-Authenticate: Digest ... -> cache (nc 0); false if it isn't a usable Digest challenge
function evse_digest_learn($url, $wa){
	if (!preg_match('/^\s*Digest\s+(.*)$/is', (string)$wa, $m)) return false;
	preg_match_all('/(\w+)\s*=\s*(?:"([^"]*)"|([^\s,]+))/', $m[1], $mm, PREG_SET_ORDER);
	$p = array();
	foreach ($mm as $x) $p[strtolower($x[1])] = (isset($x[3]) && $x[3] !== '') ? $x[3] : $x[2];
	if (empty($p['nonce'])) return false;
	$qop = '';
	if (isset($p['qop'])) foreach (explode(',', $p['qop']) as $q) if (trim($q) === 'auth') $qop = 'auth';
	$c = array('realm'=>(string)($p['realm'] ?? ''), 'nonce'=>$p['nonce'], 'opaque'=>$p['opaque'] ?? null, 'qop'=>$qop, 'nc'=>0, 'at'=>time());
	$GLOBALS['__evse_digest'][$url] = $c;
	if ($fh = evse_digest_open($url)) { evse_digest_put($fh, $c); flock($fh, LOCK_UN); fclose($fh); }
	return true;
}
// take the next nc from the cache (a placeholder challenge if nothing is cached yet)
function evse_digest_next($url){
	$c = null;
	if ($fh = evse_digest_open($url)) {
		$c = json_decode((string)stream_get_contents($fh), true);
		if (is_array($c) && !empty($c['nonce'])) $c['nc'] = (int)($c['nc'] ?? 0) + 1;
		else $c = evse_digest_placeholder();   // nothing (usable) cached yet
		evse_digest_put($fh, $c);
		flock($fh, LOCK_UN); fclose($fh);
	} else {   // temp dir not writable: per-process copy
		if (empty($GLOBALS['__evse_digest'][$url])) $GLOBALS['__evse_digest'][$url] = evse_digest_placeholder();
		else $GLOBALS['__evse_digest'][$url]['nc']++;
		$c = $GLOBALS['__evse_digest'][$url];
	}
	return $c;
}
// Before any real challenge: a made-up one with the 2.9.1 realm ("openevse"). 2.9.1 only
// checks the hash (tested: made-up nonce/opaque/realm all accepted); anything
// stricter answers 401 with a real challenge, which evse_auth_retry() learns.
function evse_digest_placeholder(){
	return array('realm'=>'openevse', 'nonce'=>bin2hex(random_bytes(16)), 'opaque'=>null, 'qop'=>'auth', 'nc'=>1, 'at'=>0);
}
// "Authorization: Digest ..." for this request ($requrl: the full URL being fetched)
function evse_digest_header($url, $user, $pass, $method, $requrl){
	$c = evse_digest_next($url);
	$pu = parse_url($requrl);
	$uri = ($pu['path'] ?? '/') . (isset($pu['query']) ? '?' . $pu['query'] : '');
	$ha1 = md5($user . ':' . $c['realm'] . ':' . $pass);
	$ha2 = md5(strtoupper($method) . ':' . $uri);
	$nc = sprintf('%08x', max(1, (int)$c['nc']) % 4294967296);
	$cnonce = bin2hex(random_bytes(8));
	$h = 'Digest username="' . $user . '", realm="' . $c['realm'] . '", nonce="' . $c['nonce'] . '", uri="' . $uri . '"';
	if ($c['qop'] === 'auth') $h .= ', qop=auth, nc=' . $nc . ', cnonce="' . $cnonce . '", response="' . md5("$ha1:{$c['nonce']}:$nc:$cnonce:auth:$ha2") . '"';
	else $h .= ', response="' . md5("$ha1:{$c['nonce']}:$ha2") . '"';
	if ($c['opaque'] !== null && $c['opaque'] !== '') $h .= ', opaque="' . $c['opaque'] . '"';
	return 'Authorization: ' . $h;
}

// Set auth + headers on a curl handle for this unit (headers given in $headers, since a
// Digest unit's Authorization goes in there too). $method / $requrl: the request being sent
// (default GET / the handle's URL). Also records the reply's WWW-Authenticate for
// evse_auth_retry(). Without credentials only the headers are set.
function evse_curl_auth($ch, $url, $user, $pass, $headers = array(), $method = 'GET', $requrl = null){
	$id = spl_object_id($ch);
	$GLOBALS['__evse_wa'][$id] = '';
	curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($h, $line) use ($id){
		if (stripos($line, 'WWW-Authenticate:') === 0) $GLOBALS['__evse_wa'][$id] = trim(substr($line, 17));
		return strlen($line);
	});
	curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
	if ($user == '' || $pass == '') { curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); return false; }
	if (evse_auth_mode($url) === 'digest') {
		if ($requrl === null) $requrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
		$headers[] = evse_digest_header($url, $user, $pass, $method, $requrl);
		curl_setopt($ch, CURLOPT_USERPWD, null);   // shared handles: no leftover curl auth
	} else {
		curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass);
	}
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	return true;
}
// One handle per unit for the whole request (GET-only callers: rapi(), the parallel
// summary; they set every option they use). Kept from the curl-Digest days; harmless.
function evse_curl_shared($url){
	$h = &$GLOBALS['__evse_curl_shared'];
	if (!is_array($h)) $h = array();
	if (!isset($h[$url])) $h[$url] = curl_init();
	return $h[$url];
}
function evse_curl_is_shared($ch){
	if (empty($GLOBALS['__evse_curl_shared'])) return false;
	foreach ($GLOBALS['__evse_curl_shared'] as $h) if ($h === $ch) return true;
	return false;
}
// After a request on $ch: was it a 401 we can fix by sending it once more? Basic unit +
// Digest challenge: switch it to Digest. Digest unit: take the new challenge into the
// cache. Callers allow this at most once per request (twice if it starts as Basic).
function evse_auth_retry($ch, $url, $code, $user, $pass){
	if ((int)$code !== 401 || $user == '' || $pass == '') return false;
	if (!evse_digest_learn($url, $GLOBALS['__evse_wa'][spl_object_id($ch)] ?? '')) return false;   // not a Digest challenge (e.g. wrong password on Basic)
	evse_auth_set($url, 'digest');
	return true;
}

// next sequence id 1..255 (00 is reserved as "no id")
function evse_rapi_seq_next(){
	static $s = null;
	if ($s === null) $s = random_int(1, 255);
	$s = $s % 255 + 1;
	return $s;
}
// "$GS" -> "$GS :3A"
function evse_rapi_with_seq($cmd, $seq){ return $seq ? $cmd . ' :' . sprintf('%02X', $seq) : $cmd; }
// command text for /r?rapi=: ours ^checksum, unless the line would come near the
// controller's 32-byte buffer (31 chars max; the WiFi module always appends its own
// "^xx", which the controller checks then - it covers the sequence id too). Only the
// $S1 clock set is that long: it goes out as "$S1 26 12 31 23 59 59 :AF" + WiFi's ^xx = 28.
function evse_rapi_wire($cmd){ return (strlen($cmd) + 6 > 28) ? $cmd : $cmd . '^' . checksum($cmd); }
// take one retry from this unit's budget for the page load (per unit, so the list page's
// units, queried in parallel, don't starve each other; one unit's retries add at most
// ~3 x 0.2 s to a page)
function evse_rapi_budget_take($key = ''){
	$b = &$GLOBALS['__evse_rapi_budget'];
	if (!is_array($b)) $b = array();
	if (!isset($b[$key])) $b[$key] = EVSE_RAPI_RETRY_BUDGET;
	if ($b[$key] <= 0) return false;
	$b[$key]--;
	return true;
}
// counters for this request: sent / rejected / retries (shown as an HTML comment on the pages)
function evse_rapi_stat($k){ if (!isset($GLOBALS['__evse_rapi_stats'])) $GLOBALS['__evse_rapi_stats'] = array('sent'=>0, 'rejected'=>0, 'retries'=>0); $GLOBALS['__evse_rapi_stats'][$k]++; }
function evse_rapi_stats_comment(){ $s = isset($GLOBALS['__evse_rapi_stats']) ? $GLOBALS['__evse_rapi_stats'] : array('sent'=>0, 'rejected'=>0, 'retries'=>0); return "<!-- rapi: sent {$s['sent']}, rejected {$s['rejected']}, retries {$s['retries']} -->"; }

// Reply text (the /r page or its JSON 'ret') -> ['ok'=>true|false ($OK/$NK), 'payload'=>string],
// or null if there is no valid reply, the checksum is wrong, or it isn't the reply to $seq.
function evse_rapi_parse_reply($text, $seq){
	if (!is_string($text) || !preg_match('/\$(OK|NK)([^\^\$<"]*)\^([0-9A-Fa-f]{2})/', $text, $m)) return null;
	if (strtoupper($m[3]) !== checksum('$' . $m[1] . $m[2])) return null;
	$body = $m[2];
	if ($seq) {
		if (!preg_match('/^(.*?) ?:([0-9A-Fa-f]{2})$/', $body, $mm) || hexdec($mm[2]) !== (int)$seq) return null;   // not ours
		$body = $mm[1];
	}
	return array('ok'=>($m[1] === 'OK'), 'payload'=>trim($body));
}

// Last good reply of a slow-changing read ($GV, $GE, $GD, $G3, $GH), for when the
// current read can't be confirmed. Cleared when we send that unit a write.
function evse_rapi_cache_file($url){ return sys_get_temp_dir() . '/oev_rapi_cache_' . md5($url) . '.json'; }
function rapi_cached(&$unit, $cmd, $maxage){
	$r = rapi($unit, $cmd);
	$f = evse_rapi_cache_file($unit['evseurl']);
	$c = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
	if (!is_array($c)) $c = array();
	if (is_string($r) && $r !== 'Error') {
		if (!isset($c[$cmd]) || $c[$cmd][1] !== $r || time() - $c[$cmd][0] > 300) { $c[$cmd] = array(time(), $r); @file_put_contents($f, json_encode($c), LOCK_EX); }
		return $r;
	}
	if (isset($c[$cmd]) && time() - (int)$c[$cmd][0] <= $maxage) return $c[$cmd][1];
	return $r;
}
function evse_rapi_ok($r){ return is_string($r) && $r !== 'Error'; }

function evse_rapi_is_read($cmd){ return strtoupper(substr($cmd, 0, 2)) === '$G'; }

// Does a read's payload look like what this command returns? (writes: anything)
function evse_rapi_shape_ok($cmd, $p){
	$c = strtoupper(substr(trim($cmd), 0, 3));
	$t = ($p === '') ? array() : preg_split('/\s+/', trim($p));
	$n = count($t);
	$dec = function($v, $lo, $hi){ return preg_match('/^-?\d{1,10}$/', $v) && (int)$v >= $lo && (int)$v <= $hi; };
	$hex = function($v, $len){ return (bool)preg_match('/^[0-9a-f]{1,' . $len . '}$/i', $v); };
	switch ($c) {
		case '$GS':   // state(hex2) elapsed [pilotstate(hex2) vflags(hex4)]
			if (!(($n == 2 || $n == 4) && preg_match('/^[0-9a-f]{2}$/i', $t[0]) && ctype_digit($t[1]))) return false;
			if ($n == 4 && !(preg_match('/^[0-9a-f]{2}$/i', $t[2]) && preg_match('/^[0-9a-f]{4}$/i', $t[3]))) return false;
			$s = hexdec($t[0]);
			return ($s <= 11 || $s == 254 || $s == 255) && ($n == 2 || hexdec($t[2]) <= 7);
		case '$GV':   // "7.1.3 5.1.3": firmware x.y.z (maybe a letter) + RAPI version
			return $n >= 1 && $n <= 2 && preg_match('/^[A-Za-z]?\d+\.\d+\.\d+[A-Za-z0-9.]*$/', $t[0]) && ($n == 1 || preg_match('/^\d+\.\d+\.\d+$/', $t[1]));
		case '$GT':   // yy m d H i s
			return $n == 6 && $dec($t[0], 0, 99) && $dec($t[1], 1, 12) && $dec($t[2], 1, 31) && $dec($t[3], 0, 23) && $dec($t[4], 0, 59) && $dec($t[5], 0, 59);
		case '$GE':   return $n == 2 && $dec($t[0], 6, 80) && $hex($t[1], 4);
		case '$GG':   return $n == 2 && $dec($t[0], -1, 100000) && $dec($t[1], -1, 300000);
		case '$GU':   return $n == 2 && ctype_digit($t[0]) && ctype_digit($t[1]);
		case '$GP':   return $n == 3 && $dec($t[0], -2560, 2560) && $dec($t[1], -2560, 2560) && $dec($t[2], -2560, 2560);
		case '$G3':
		case '$GH':   return $n == 1 && $dec($t[0], 0, 255);
		case '$GD':   return $n == 4 && $dec($t[0], 0, 23) && $dec($t[1], 0, 59) && $dec($t[2], 0, 23) && $dec($t[3], 0, 59);
		case '$GF':   return $n == 3 && $hex($t[0], 4) && $hex($t[1], 4) && $hex($t[2], 4);
		case '$G0':   return $n == 1 && preg_match('/^[0-2]$/', $t[0]);
		case '$GA':   return $n == 2 && $dec($t[0], 0, 99999) && $dec($t[1], -99999, 99999);
		case '$GC':   return $n == 4 && $dec($t[0], 0, 80) && $dec($t[1], 0, 80) && $dec($t[2], 0, 80) && $dec($t[3], 0, 80);
	}
	return true;
}

// Sends RAPI command to OpenEVSE box.
// Returns the reply payload (string), 'Error' ($NK to our command), -1 (no valid
// reply), or for writes -2 (sent, but the reply wasn't verifiably ours - the
// command was probably carried out; callers re-read state where it matters).
function rapi(&$evseconnect, $command){
	$url      = $evseconnect['evseurl'];
	$username = $evseconnect['username'];
	$password = $evseconnect['password'];

	$fetch = function($rapiurl) use ($url, $username, $password){
		// up to 3 sends: Basic -> 401 -> Digest; a stale cached challenge -> 401 -> fresh one
		$dig_retry = 0;
		for ($a = 0; $a < 3; $a++) {
			$was_digest = (evse_auth_mode($url) === 'digest');
			$shared = ($username <> '' && $password <> '' && evse_auth_mode($url) === 'digest');
			$ch = $shared ? evse_curl_shared($url) : curl_init();
			curl_setopt($ch, CURLOPT_URL, $rapiurl);
			curl_setopt($ch, CURLOPT_HTTPGET, true);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch,CURLOPT_CONNECTTIMEOUT, 1);  //Connection timeout to X seconds per call.
			evse_curl_auth($ch, $url, $username, $password, array(), 'GET', $rapiurl);   // Basic, or preemptive Digest for 2.9.x (see above)
			$result = curl_exec($ch);
			$response = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			$retry = evse_auth_retry($ch, $url, $response, $username, $password) && (!$was_digest || $dig_retry++ < 1);
			if (!$shared) curl_close($ch);
			if (!$retry) break;
		}
		return array($result, $response);
	};

	if ( substr($command, 0, 1) != '$' ) {
		// connection test ('r'), or the WiFi module's own JSON (config / status)
		list($result, $response) = $fetch(substr($command, 0, 1) == 'r' ? $url . 'r' : $url . urlencode($command));
		if ($command == 'r') return $response;
		if ($response <> 200) return -1;
		return $result;
	}

	// RAPI, with sequence id + shape check (see the block comment above)
	$is_read = evse_rapi_is_read($command);
	$memo = &$GLOBALS['__evse_rapi_memo'];
	if (!is_array($memo)) $memo = array();
	$cmd3 = strtoupper(substr($command, 0, 3));
	if (!$is_read) {
		unset($memo[$url]);                    // a write: re-read everything after it
		@unlink(evse_rapi_cache_file($url));
	} elseif ($cmd3 != '$GT' && $cmd3 != '$GG' && isset($memo[$url][$command]) && microtime(true) - $memo[$url][$command][0] < 2) {
		return $memo[$url][$command][1];      // same read a moment ago in this page load
	}
	$tries = $is_read ? 1 + EVSE_RAPI_CMD_RETRIES : 1;   // writes: never sent twice
	$last = -1;
	for ($i = 0; $i < $tries; $i++) {
		if ($i > 0) {
			if (!evse_rapi_budget_take($url)) break;
			evse_rapi_stat('retries');
			usleep(evse_rapi_retry_delay_us());
		}
		$seq = EVSE_RAPI_SEQ ? evse_rapi_seq_next() : 0;
		$sent = evse_rapi_with_seq($command, $seq);
		evse_rapi_stat('sent');
		list($result, $response) = $fetch($url . 'r?rapi=' . urlencode(evse_rapi_wire($sent)));
		if ($response <> 200) { $last = -1; continue; }
		$rep = evse_rapi_parse_reply($result, $seq);
		if ($rep === null) {
			evse_rapi_stat('rejected');
			// a write whose reply isn't ours: don't resend
			if (!$is_read) return (evse_rapi_parse_reply($result, 0) !== null) ? -2 : -1;
			$last = -1; continue;
		}
		if (!$rep['ok']) return 'Error';       // $NK to our own command
		if ($is_read && !evse_rapi_shape_ok($command, $rep['payload'])) { evse_rapi_stat('rejected'); $last = -1; continue; }
		if ($is_read) $memo[$url][$command] = array(microtime(true), $rep['payload']);
		return $rep['payload'];
	}
	return $last;
} //end function rapi()


function checksum($string){
	$val = 0;
	for($i = 0; $i < strlen($string); $i++) {
            $val ^= ord($string[$i]);
	}
	$val = strtoupper(dechex($val));
	if ( strlen($val) == 1 ){
		$val = '0' . $val;
	}
	return $val;
}



function get_state(&$evseconnect, &$evse){

	// Get State, state elapsed_seconds - 1 Not Connected - 2 Connected - 3 Charging - 4 Error - 5 Error
	$state = explode(' ', rapi($evseconnect, '$GS'));
	
	/*************************************************
	 * Retry ONCE on error.
	 * Sometimes getting the state is flaky, 
	 * and everything else depends on this parameter.
	 *************************************************/
/*  COULD BE CAUSE OF ANOMOLOUS LOADS?
	if ( count($state) <> 2 || $state[0] < 0 || $state[0] > 255 ) {
		usleep(100000);  // wait 1/10 sec
		$state = explode(' ', rapi($evseconnect, '$GS'));
	}
*/
	
	// if after two tries, still -1 then no connection
	if ( $state[0] == -1 ){
		$evse['state'] = "Connection Error";
		$evse['statecode'] = $state[0];
		return;
	}
	
	$evse['elapsed_sec'] = $state[1];
	if ($state[1] <= 120) {
		$evse['elapsed'] = $state[1] . ' seconds';
	}elseif ($state[1] < 3600) {
		$evse['elapsed'] = round($state[1] / 60) . ' minutes';
	}else{
		$evse['elapsed'] = round($state[1] / 60 / 60, 1) . ' hours';
	}
	
	
	// fix for sleeping units?  Force sleep if statecode = fe
	if ( $state[0] == 'fe' ) { $state[0] = 254; }
	
	
	switch ($state[0]) {
//		case 'fe': $evse['state'] = 'Sleeping'; break;  // one unit does this when scheduled?
		case 0:    $evse['state'] = 'Starting'; break;  // almost never see this
		case 1:    $evse['state'] = 'Ready'; break;     // rename to "Idle"?  Let the connected property from /status determine if it's plugged in
		case 2:    $evse['state'] = 'Not Charging'; break;  // Plugged In?  Same as connected, already handled by "connected" status.  "Not charging" works best with connected.
		case 3:    $evse['state'] = 'Charging'; break;
		case 4:    $evse['state'] = 'Error: Vent Required'; break;
		case 5:    $evse['state'] = 'Error: Diode Check'; break;
		case 6:    $evse['state'] = 'Error: GFCI Fault'; break;
		case 7:    $evse['state'] = 'Error: Earth Ground'; break;
		case 8:    $evse['state'] = 'Error: Stuck Relay'; break;
		case 9:    $evse['state'] = 'Error: GFCI Test Failed'; break;
		case 10:   $evse['state'] = 'Error: Over Temperature'; break;
		case 11:   $evse['state'] = 'Error: Over Current'; break;
		case 254:  $evse['state'] = 'Sleeping'; break;
		case 255:  $evse['state'] = 'Disabled'; break;
		default:   $evse['state'] = 'Error'; break;
	}
	$evse['statecode'] = $state[0];
}


function get_charge(&$evseconnect, &$evse){
	// Get charge current and voltage, milliamps millivolts
	// rapi() checks the reply (sequence id + shape) and retries; unconfirmed => null (shown as a dash)
	$r = rapi($evseconnect, '$GG');
	if (!evse_rapi_ok($r)) { $evse['amps'] = null; $evse['volts_reported'] = null; return; }
	$charge = explode(' ', $r);
	$evse['amps'] = max(0, intval($charge[0])) / 1000;
	// millivolts: only a measurement if a voltmeter is fitted (else the level's default)
	$evse['volts_reported'] = isset($charge[1]) && is_numeric($charge[1]) ? intval($charge[1]) : null;
}


function get_settings(&$evseconnect, &$evse){
	// Get Settings, amps flags
	$r = rapi_cached($evseconnect, '$GE', 600);   // last good reply up to 10 min old if this one isn't confirmed
	if (!evse_rapi_ok($r)) {   // unknown: dashes, not made-up flags
		$evse['pilot'] = null; $evse['level'] = null; $evse['autolevel'] = null;
		foreach (array('diode', 'vent', 'ground', 'relay', 'gfci', 'temp') as $k) $evse['check_' . $k] = null;
		$evse['auto_start'] = null;
		list($evse['volts'], $evse['volts_src']) = evse_volts_info($evseconnect, 2, $evse['volts_reported'] ?? null);
		$evse['power'] = ($evse['amps'] === null) ? null : round($evse['amps'] * $evse['volts'] / 1000, 1);
		return;
	}
	$settings = explode(' ', $r);
	$evse['pilot'] = $settings[0];
	if ( !is_numeric($evse['pilot']) ) { $evse['pilot'] = 0; }
	// $GC "min hwmax active saved": active (maybe temporary) and saved current, fresh
	$gc = rapi($evseconnect, '$GC');
	if (evse_rapi_ok($gc)) { $t = preg_split('/\s+/', trim($gc)); if (count($t) == 4) { $evse['pilot'] = (int)$t[2]; $evse['saved_current'] = (int)$t[3]; } }
	// Reading Hex value, converting to binary then a string (inverted) to read the bits
	$flags = '';
	$f = base_convert(substr($settings[1], 0, 1), 16, 2);
	$flags .= str_repeat("0", 4 - strlen($f)) . $f;
	$f = base_convert(substr($settings[1], 1, 1), 16, 2);
	$flags .= str_repeat("0", 4 - strlen($f)) . $f;
	$f = base_convert(substr($settings[1], 2, 1), 16, 2);
	$flags .= str_repeat("0", 4 - strlen($f)) . $f;
	$f = base_convert(substr($settings[1], 3, 1), 16, 2);
	$flags .= str_repeat("0", 4 - strlen($f)) . $f;
	$flags = strrev($flags);
//	echo $settings[1] . '<br />';
//	echo $flags . '<br />';
	$evse['check_diode']  = !boolval(substr($flags, 1,  1));
	$evse['check_vent']   = !boolval(substr($flags, 2,  1));
	$evse['check_ground'] = !boolval(substr($flags, 3,  1));
	$evse['check_relay']  = !boolval(substr($flags, 4,  1));
	$evse['auto_start']   = !boolval(substr($flags, 5,  1));
	$evse['check_gfci']   = !boolval(substr($flags, 9,  1));
	$evse['check_temp']   = !boolval(substr($flags, 10, 1));
	
	//detect auto service level
	if ( !substr($flags, 5, 1) ) { $evse['autolevel'] = 'Auto'; }else{ $evse['autolevel'] = 'Manual'; }
	//get current Level setting
	$evse['level'] = substr($flags, 0, 1) + 1;
	// voltage: override / voltmeter / assumed for the level (volts_lib.php)
	list($evse['volts'], $evse['volts_src']) = evse_volts_info($evseconnect, $evse['level'], $evse['volts_reported'] ?? null);
	// multiply estimated power in kW
	$evse['power'] = ($evse['amps'] === null) ? null : round($evse['amps'] * $evse['volts'] / 1000, 1);
}

function get_energy(&$evseconnect, &$evse){
	// Get Energy Usage, wattseconds, watt_hour_accumulated $energy[1]
	$r = rapi($evseconnect, '$GU');
	if (!evse_rapi_ok($r)) { $evse['energy'] = null; $evse['lifetimeKWH'] = null; return; }   // unconfirmed: dashes
	$energy = explode(' ', $r);
	$evse['energy'] = round(intval($energy[0]) / 1000 / 60 / 60, 1);  //divide by 1000 for kilo, 60 for minutes, 60 for hours
	$evse['lifetimeKWH'] = round(intval($energy[1]) / 1000, 2); // divide by 1000 for kilo
}

function get_temp(&$evseconnect, &$evse){
	// Get Temperature (provided in decimal C * 10 for integer)
	$r = rapi($evseconnect, '$GP');
	if (!evse_rapi_ok($r)) { $evse['temp'] = "\u{2014}"; return; }   // unconfirmed: dash (a -1 used to read as 32 F)
	$temp = explode(' ', $r) + array('', '', '');
	//look in first temp slot [0]
	if ($temp[0] > -600 && $temp[0] < 2500 && is_numeric($temp[0]) ) {			// sanity check
		$evse['temp'] = round($temp[0] / 10 * 9 / 5 + 32);	//convert C to F
	}else{
		//try 2nd temp slot [1]
		if ($temp[1] > -600 && $temp[1] < 2500 && is_numeric($temp[1]) ) {			// sanity check
			$evse['temp'] = round($temp[1] / 10 * 9 / 5 + 32);	//convert C to F
		}else{
			$evse['temp'] = 'Error';
		}
		//$evse['temp'] = $temp[0] . ' ' . $temp[1] . ' ' . $temp[2];
	}
}

function get_time(&$evseconnect, &$evse){
	// Get unit Time - Y M D H M S => MM/DD HH:MM
	$r = rapi($evseconnect, '$GT');
	if (!evse_rapi_ok($r)) { $evse['time'] = "\u{2014}"; return; }   // unconfirmed: dash
	$time = explode(' ', $r);
	foreach ($time as &$value) {
		if (strlen($value)==1){
			$value = "0$value";	//convert to 2-digits with leading zero as needed
		}
	if ( $value > 99 ) { $value = 'Err'; }		// sanity check
	}
	//simple formatted date and time
	$evse['time'] = "{$time[1]}/{$time[2]} {$time[3]}:{$time[4]}";
	//individual values
	$evse['tyear']  = substr(date('Y'),0,2) . $time[0];  //make 4 digits
	$evse['tmonth'] = $time[1];
	$evse['tday']   = $time[2];
	$evse['thour']  = $time[3];
	$evse['tmin']   = $time[4];
	$evse['tsec']   = $time[5];
}

function get_timelimit(&$evseconnect, &$evse){
	// Get Charge Time Limit - 15-minutes (1), 30-minutes (2), 45-minutes (3), etc...
	$r = rapi_cached($evseconnect, '$G3', 600);
	if (!evse_rapi_ok($r)) { $evse['timelimit'] = "\u{2014}"; $evse['timelimitcode'] = null; return; }
	$timelimit = explode(' ', $r);
	if ( $timelimit[0] <= 0 || $timelimit[0] >= 100 ) {
		$evse['timelimit'] = 'None';
	}else{
		if ($timelimit[0] * 15 <= 60 ) {
			$evse['timelimit'] = strval($timelimit[0] * 15) . ' minutes';
		}else{
			$evse['timelimit'] = strval($timelimit[0] * 15 / 60) . ' hours';
		}
	}
	$evse['timelimitcode'] = $timelimit[0];
}

function get_timer(&$evseconnect, &$evse){
	// Get start/stop timer settings, start_hour start_min end_hour end_min
	$r = rapi_cached($evseconnect, '$GD', 3600);
	if (!evse_rapi_ok($r)) { $evse['timerstart'] = $evse['timerend'] = "\u{2014}"; return; }
	$timer = explode(' ', $r);
	foreach ($timer as &$value) {
		if (strlen($value)==1){
			$value = "0$value";	//convert to 2-digits with leading zero as needed
		}
	}
	// Check if all timers are Zero or not all numeric
	$timertest = $timer[0] . $timer[1] . $timer[2] . $timer[3];
	if ( strlen($timertest) < 8 || $timertest == 0  ) {
		$evse['timerstart'] = 'None';
		$evse['timerend']   = 'None';
	}else{
		$evse['timerstart'] = $timer[0] . ':' . $timer[1];
		$evse['timerend']   = $timer[2] . ':' . $timer[3];
	}
}

function get_chargelimit(&$evseconnect, &$evse){
	// Get Charge Limit in kWh, integer
	$r = rapi_cached($evseconnect, '$GH', 600);
	if (!evse_rapi_ok($r)) { $evse['chargelimit'] = "\u{2014}"; return; }
	$chargelimit = explode(' ', $r);
	if ($chargelimit[0] > 0) {
		$evse['chargelimit'] = $chargelimit[0] . ' kWh';
	}else{
		$evse['chargelimit'] = 'None';
	}
}


function get_config(&$evseconnect, &$evse){
	// get firmware version of OpenEVSE controller
	// $GV checked (sequence id + "x.y.z" shape): a crossed reply showed "fe" here before.
	// Firmware only changes on a flash, so the last good reply (30 days) stands in.
	$version = rapi_cached($evseconnect, '$GV', 30 * 86400);
	$version = evse_rapi_ok($version) ? explode(' ', $version) : array("\u{2014}");
	// get status information from unit
	// (json_decode: 2.8.2 and 2.9.1 both send JSON, in different key orders / types)
	$status = json_decode((string)rapi($evseconnect, 'status'), true);
	if (!is_array($status)) $status = array();
	// get config information from unit (evse_detect() already fetched it this request)
	$d = function_exists('evse_detect') ? evse_detect($evseconnect) : null;
	$config = ($d && is_array($d['config'])) ? $d['config'] : json_decode((string)rapi($evseconnect, 'config'), true);
	if (!is_array($config)) $config = array();
	$evse['wifi_status'] = $status;   // 2.9.1 adds voltage, rapi_connected, net_connected, ...
	//save relevant info - there is more in the dictionaries, but it's redundant data.
	// TODO: more reliable?  Fewer calls?  Adding more items here could possibly simplify & reduce total RAPI calls
	//$evse['firmware']  = $config['firmware'];  // this one is inconsistent from unit to unit
	$evse['firmware']  = $version[0];
	$evse['protocol']  = isset($config['protocol']) ? $config['protocol'] : '';
	$evse['version']   = isset($config['version']) ? $config['version'] : '';
	$evse['ssid']      = isset($config['ssid']) ? $config['ssid'] : '';
	$evse['hostname']  = isset($config['hostname']) ? (string)$config['hostname'] : '';
	$evse['ipaddress'] = isset($status['ipaddress']) ? $status['ipaddress'] : '';
	$evse['srssi']     = (isset($status['srssi']) && is_numeric($status['srssi'])) ? (int)$status['srssi'] : null;
	$evse['rapi_connected'] = isset($status['rapi_connected']) ? (int)$status['rapi_connected'] : null;   // 2.9.1+
}


function get_voice(&$evseconnect, &$evse){
	// CONFIGURE TEXT STATUS - IDEAL FOR VOICE RESPONSE
	// MUST RUN LAST, after get_state(), get_settings(), etc. (needs $evse['connected'])
	if ( $evse['connected'] ) { $connected = 'connected'; } else {$connected = 'not connected,';}
	
	$evse['status'] = '';	
 $evse['status'] .= $evseconnect['name'];
	$evse['status'] .= ",, E.V.S.E. is $connected and {$evse['state']}";
	
	switch ($evse['statecode']) {
	default:
		$evse['status'] .= '. ';
		break;
    case 3:    // charging
		$evse['status'] .= " at {$evse['amps']} amps, {$evse['power']} kilowatts";
	case 2:
		$evse['status'] .= '. ';
		if ($evse['elapsed_sec'] <= 21600) {
			// times less than 6 hours are valid, otherwise ignore time
			$evse['status'] .= "It has charged for {$evse['elapsed']}, ";
		}
		$evse['status'] .= "{$evse['energy']} kilowatt-hours have been added. ";
	}
}


function get_status(&$evseconnect){
	// check connection to base URL, fail out if can't connect
	// this prevents undue loss of time for each fuction on a unit that isn't available
	$response = rapi($evseconnect, 'r');  // "r" = special ID above
	if ($response <> 200){
		return $response;
	}else{
		// only continue if unit responds on the network
		get_state($evseconnect, $evse);
		get_config($evseconnect, $evse);
		// The unit answered 'r' above, so read the rest even if the $GS reply couldn't be
		// confirmed (evse_status() fills the state in then). $G0 is no longer sent: the
		// plug-in comes from the validated $GS, and every request we skip is one less
		// chance to collide with the WiFi module's own polling.
		$evse['connected'] = 0;   // set from the validated $GS by evse_status()
		if ( true ) {
			get_charge($evseconnect, $evse);
			get_settings($evseconnect, $evse);
			get_energy($evseconnect, $evse);
			get_temp($evseconnect, $evse);
			get_time($evseconnect, $evse);
			get_timelimit($evseconnect, $evse);
			get_timer($evseconnect, $evse);
			get_chargelimit($evseconnect, $evse);

			get_voice($evseconnect, $evse);
		}//statecode
	}//response
	
	return $evse;
	
} //end get_status()


?>