<?php
/******************************************************************************
 * mail_lib.php - sends the OpenEVSE emails through the SMTP server set in
 * Settings > Preferences (any provider: Mailjet, Gmail, Microsoft 365, ...).
 * Uses the PHPMailer copy in ./PHPMailer. The SMTP password is stored
 * encrypted and never printed.
 ******************************************************************************/
// PHPMailer 6.0.5 uses a filter flag removed in PHP 8. Define it (as 0, which is what PHP 8 does anyway) so it runs on PHP 8.4.
if (!defined('PHPMailer\PHPMailer\FILTER_FLAG_HOST_REQUIRED')) define('PHPMailer\PHPMailer\FILTER_FLAG_HOST_REQUIRED', 0);
require_once __DIR__ . '/settings_lib.php';
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

function oev_mail_ready(){ $s = oev_settings(); return $s['smtp_host'] !== '' && $s['smtp_from'] !== ''; }
function oev_mailer(){
	$s = oev_settings();
	$m = new PHPMailer\PHPMailer\PHPMailer(true);
	$m->isSMTP();
	$m->Host = $s['smtp_host'];
	$m->Port = (int)$s['smtp_port'] ?: 587;
	$m->SMTPSecure = in_array($s['smtp_secure'], array('tls', 'ssl'), true) ? $s['smtp_secure'] : '';
	$m->SMTPAutoTLS = ($s['smtp_secure'] !== 'none');
	$m->SMTPAuth = ($s['smtp_user'] !== '');
	$m->Username = $s['smtp_user'];
	$m->Password = oev_dec($s['smtp_pass']);
	$m->Timeout = 15;
	$m->SMTPDebug = 0;
	$m->CharSet = 'UTF-8';
	$m->setFrom($s['smtp_from'], trim((string)$s['smtp_from_name']) !== '' ? $s['smtp_from_name'] : oev_site_name());
	return $m;
}
// -> true, or an error message (never contains the password)
function oev_mail($to, $subject, $body){
	if (!oev_mail_ready()) return 'Email is not set up (Settings > Preferences).';
	$to = array_values(array_unique(array_filter(array_map('trim', (array)$to), function($a){ return filter_var($a, FILTER_VALIDATE_EMAIL); })));
	if (!$to) return 'No valid recipient.';
	try {
		$m = oev_mailer();
		$m->addAddress($to[0]);
		foreach (array_slice($to, 1) as $a) $m->addBCC($a);
		$m->isHTML(false);
		$m->Subject = '[' . oev_site_name() . '] ' . $subject;   // every email names the site
		$m->Body = $body;
		$m->send();
		return true;
	} catch (Throwable $e) {
		return 'Could not send: ' . (isset($m) && $m->ErrorInfo ? $m->ErrorInfo : $e->getMessage());
	}
}
// log in to the SMTP server without sending anything -> true or an error message
function oev_mail_check(){
	if (!oev_mail_ready()) return 'Fill in the server and the from address first.';
	try { $m = oev_mailer(); if ($m->smtpConnect()) { $m->smtpClose(); return true; } return 'Could not connect or log in.'; }
	catch (Throwable $e) { return 'Could not connect or log in: ' . (isset($m) && $m->ErrorInfo ? $m->ErrorInfo : $e->getMessage()); }
}
