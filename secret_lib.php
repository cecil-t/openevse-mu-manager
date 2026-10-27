<?php
/******************************************************************************
 * secret_lib.php - passwords the system itself has to use (unit logins, SMTP,
 * external Emoncms keys) are stored encrypted: AES-256-GCM with a random
 * 32-byte key in private/key/secret.key (web access denied by private/.htaccess;
 * folder 0700 and file 0400, owned by the web user http).
 * A copy of openevse.db on its own does not reveal them.
 * Stored form: "enc1:" + base64(iv 12 | tag 16 | ciphertext). Anything without
 * the prefix is treated as plain text (old rows), so readers never break.
 * The load balancer container sees it through its read-only mount of the site
 * (same path), running as the same user. Back this file up: without it the
 * saved unit / SMTP passwords can't be read and must be typed in again.
 * (OpenSSL is used because the site's PHP 8.4 profile has no sodium extension;
 * AES-256-GCM is the same strength of authenticated encryption.)
 ******************************************************************************/
if (!defined('OEV_KEY_FILE')) define('OEV_KEY_FILE', getenv('OEV_KEY_FILE') ?: __DIR__ . '/private/key/secret.key');

function oev_key(){
	static $k = null;
	if ($k === null) {
		$r = @file_get_contents(OEV_KEY_FILE);
		$r = is_string($r) ? trim($r) : '';
		$k = (strlen($r) === 64 && ctype_xdigit($r)) ? hex2bin($r) : false;
	}
	return $k;
}
function oev_is_enc($v){ return is_string($v) && strncmp($v, 'enc1:', 5) === 0; }
function oev_enc($plain){
	if ($plain === null || $plain === '') return $plain;
	$k = oev_key();
	if (!$k) throw new RuntimeException('Encryption key file missing or unreadable');
	$iv = random_bytes(12); $tag = '';
	$c = openssl_encrypt((string)$plain, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag, 'oev1', 16);
	if ($c === false) throw new RuntimeException('Encryption failed');
	return 'enc1:' . base64_encode($iv . $tag . $c);
}
function oev_dec($v){
	if (!oev_is_enc($v)) return $v;
	$k = oev_key(); if (!$k) return '';
	$b = base64_decode(substr($v, 5), true);
	if ($b === false || strlen($b) < 28) return '';
	$p = openssl_decrypt(substr($b, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16), 'oev1');
	return $p === false ? '' : $p;
}
// a unit row from openevse.db `evses` with its login usable (password decrypted)
function oev_unit_row($row){
	if (is_array($row) && isset($row['password'])) $row['password'] = oev_dec($row['password']);
	if (is_array($row) && isset($row['emon_ext_key'])) $row['emon_ext_key'] = oev_dec($row['emon_ext_key']);
	return $row;
}
