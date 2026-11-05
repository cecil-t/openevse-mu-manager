<?php
/******************************************************************************
 * util_lib.php - small helpers shared by the pages and the collector scripts:
 *  h()          HTML escape
 *  ago()        "5 min ago" from a timestamp (Settings lists)
 *  oev_sess_ro() read-only connection to sessions.db (the collector's data)
 * Included with require_once; safe to load more than once.
 ******************************************************************************/
// controller serial ($GI / WiFi /config evse_serial): 6 ASCII chars + 4+ hex digits, e.g. Y73595FF0E2D12.
// WiFi 5.x reads it once at boot without RAPI sequence ids, so a late reply can land there
// (seen: "12" = the $GE pilot, "220" = the $GA ammeter scale, "0").
function oev_serial_ok($s){ return (bool)preg_match('/^[A-Za-z0-9]{6}[0-9A-Fa-f]{4,}$/', trim((string)$s)); }
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

// timestamp -> 'just now' / '5 min ago' / '3 h ago' / date; 0 or null -> 'never'
function ago($t){ if (!$t) return 'never'; $d = time() - (int)$t; if ($d < 120) return 'just now'; if ($d < 7200) return round($d / 60) . ' min ago'; if ($d < 172800) return round($d / 3600) . ' h ago'; return date('n/j/Y', (int)$t); }

// sessions.db opened read-only (pages never write it; the collector and the receiver do, via sess_db()).
// Throws on failure: callers wrap it in try/catch.
function oev_sess_ro($timeout = 2, $dir = null){
	$db = new PDO('sqlite:' . ($dir ?? __DIR__) . '/sessions.db', null, null, array(PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY, PDO::ATTR_TIMEOUT => $timeout, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
	$db->exec('PRAGMA temp_store = MEMORY');   // big sorts never spill into the NAS temp folder
	return $db;
}

// the browser is on the units' own network: REMOTE_ADDR (the app trusts no proxy headers) is a private IPv4 address in the same /24 as the unit's IP
function nm_same_net($client, $ip){
	$f = FILTER_FLAG_IPV4;
	if (!is_string($client) || !is_string($ip) || filter_var($client, FILTER_VALIDATE_IP, $f) !== $client || filter_var($ip, FILTER_VALIDATE_IP, $f) !== $ip) return false;
	if (filter_var($client, FILTER_VALIDATE_IP, $f | FILTER_FLAG_NO_PRIV_RANGE) !== false) return false;   // public or Tailscale (100.64/10): not private
	return (ip2long($client) & 0xFFFFFF00) === (ip2long($ip) & 0xFFFFFF00);
}
// or the browser comes in from this site's own public address (it shares the units' home internet connection): the site name's DNS A records, as set by the web server (not the browser's Host header)
function nm_home_public($client){
	static $pub = null; $f = FILTER_FLAG_IPV4;
	if (!is_string($client) || filter_var($client, FILTER_VALIDATE_IP, $f | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== $client) return false;
	if ($pub === null) {
		$pub = array(); $n = (string)($_SERVER['SERVER_NAME'] ?? '');
		if ($n !== '' && filter_var($n, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === $n && strpos($n, '.') !== false)
			foreach ((@dns_get_record($n, DNS_A) ?: array()) as $r) if (isset($r['ip']) && filter_var($r['ip'], FILTER_VALIDATE_IP, $f) === $r['ip']) $pub[] = $r['ip'];
	}
	return in_array($client, $pub, true);
}
function nm_local($client, $ip){
	if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== $ip || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE) !== false) return false;   // the unit must be on a private network
	return nm_same_net($client, $ip) || nm_home_public($client);
}
// the unit's own web page, from its Address (Settings > Units): scheme, host and port only, never a login
function nm_web($evseurl){
	$p = parse_url((string)$evseurl); if (!is_array($p) || empty($p['host'])) return '';
	$sc = strtolower($p['scheme'] ?? 'http'); if ($sc !== 'http' && $sc !== 'https') return '';
	$h = $p['host']; if (filter_var(trim($h, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) $h = '[' . trim($h, '[]') . ']';
	return $sc . '://' . $h . (isset($p['port']) ? ':' . (int)$p['port'] : '') . '/';
}
