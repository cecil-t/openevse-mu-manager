<?php
// Help: how the OpenEVSE pages get their data, unit clocks, firmware notes. Plain words.
require_once __DIR__ . '/auth_lib.php';
oev_require('view');
require_once __DIR__ . '/util_lib.php';   // h(), ago(), oev_sess_ro()
$title = 'Help'; $wq = '';
include __DIR__ . '/_head.php';
$S = oev_settings();
// numbers shown here are read from where the system really keeps them (shown in black: oev_v())
function hp_const($file, $name, $def){ $t = (string)@file_get_contents(__DIR__ . '/' . $file); return preg_match("/define\\('" . preg_quote($name, '/') . "',\\s*([0-9.]+)\\)/", $t, $m) ? $m[1] + 0 : $def; }
function hp_dur($s){ $s = (float)$s; if ($s < 60) return ($s == 1 ? '1 second' : $s . ' seconds'); $m = $s / 60; return ($m == 1 ? '1 minute' : round($m) . ' minutes'); }
$K = array(
	'poll_plug' => hp_const('collector/session_logic.php', 'SESS_POLL_SESSION_SEC', 120),
	'poll_idle' => hp_const('collector/session_logic.php', 'SESS_POLL_IDLE_SEC', 900),
	'clk_every' => hp_const('collector/clock_logic.php', 'SESS_CLOCK_CHECK_SEC', 600),
	'clk_off'   => hp_const('collector/clock_logic.php', 'SESS_CLOCK_OFF_SEC', 1),
	'clk_min'   => hp_const('collector/clock_logic.php', 'SESS_CLOCK_SYNC_MIN_SEC', 600),
	'lb_stale'  => hp_const('include_lb.php', 'EVSE_LB_STALE', 90),
);
$ini = @parse_ini_file(__DIR__ . '/loadbalancer/coordinator/lb.ini'); $K['lb_poll'] = (int)($ini['poll_sec'] ?? 20);
$K['lb_fast'] = preg_match("/'fast_sec'\s*=>\s*(\d+)/", (string)@file_get_contents(__DIR__ . '/loadbalancer/coordinator/lb_core.php'), $m) ? (int)$m[1] : 5;
$K['hist'] = null;
try { $sdb = oev_sess_ro(); $K['hist'] = $sdb->query('SELECT min(plug_in_at) FROM sessions')->fetchColumn(); $sdb = null; } catch (Exception $e) {}
$hbm = (int)$S['heartbeat_min'];
?>
<style>
.hp{font-size:16px;line-height:1.5;color:#444;text-align:left}
.hp h2{font-size:18px;color:#035a5a;margin:16px 0 4px 0}
.hp h2:first-child{margin-top:2px}
.hp ul{margin:4px 0 4px 0;padding-left:20px}
.hp li{margin:3px 0}
.hp .toc a{display:inline-block;margin:0 10px 4px 0;color:#035a5a}
.hp b{color:#333}
</style>
<div class="slidebox hp">
<div class="toc"><a href="#data">Getting the data</a><a href="#lb">Load groups</a><a href="#clock">Unit clocks</a><a href="#fw">Firmware</a><a href="#volts">Voltage</a><a href="#mail">Emails</a><a href="#names">Names</a><a href="#logins">Logins</a><?=oev_is_admin() ? '<a href="#sys">System status</a><a href="#setup">Setup</a>' : ''?></div>

<h2 id="data">How the server gets its data</h2>
<ul>
<li><b>Units send it.</b> Each unit sends its readings (amps, energy, temperature, state) to this server every 30 seconds. This is the unit's Emoncms setting, pointed at this server ("Internal logging" in Settings).</li>
<li><b>The server also asks.</b> Every minute the collector checks which units need a closer look: right after a pushed reading shows a change, every <?=oev_v(hp_dur($K['poll_plug']))?> while a car is plugged in, every <?=oev_v(hp_dur($K['poll_idle']))?> otherwise, and every minute for a unit whose readings stop arriving. It also asks every minute for any unit that doesn't send readings (Emoncms off or pointed at another server).</li>
<li><b>The load balancer listens.</b> Units in a load group also report over MQTT, so the load balancer hears about a plug-in or a restart within seconds. It reads every unit over the web every <?=oev_v(hp_dur($K['lb_poll']))?>, and reads the amps of charging units every <?=oev_v(hp_dur($K['lb_fast']))?>.</li>
<li><b>The pages.</b> The home page and unit pages update every 5 seconds from the load balancer. The light next to the time is green when that is working, amber when the data is late, and red when the load balancer is down, meaning no word from it for <?=oev_v(hp_dur($K['lb_stale']))?> (the pages then ask the units directly, which is slower).</li>
<li>Session reports come from these readings. History starts <?=$K['hist'] ? oev_v(date('M j, Y', (int)$K['hist'])) : 'with the first session'?>.</li>
</ul>

<h2 id="lb">Load groups</h2>
<ul>
<li>Units in a load group are fed from the same breaker panel or main breaker. Each unit can still have its own branch breaker. The group's capacity is the limit for that panel or feeder: the load balancer splits it between the cars that are plugged in, and never goes over it.</li>
<li><b>Max current</b> (Settings &gt; Units) is the most a unit will ever give a car. It can't be more than the unit's own hardware maximum. The unit's branch breaker must be at least 125% of it, rounded up to a standard size: <?php $ex = array(); foreach (array(16, 32, 40, 48) as $a) $ex[] = $a . ' A needs ' . oev_v(oev_min_breaker($a) . ' A'); echo implode(', ', $ex); ?>.</li>
<li>To delete a load group, first set each of its units to Load group "None" under Settings &gt; Units.</li>
<li>Each unit's <b>saved</b> current is kept at its full rating. The load balancer sets a <b>temporary</b> current below it, shown in parentheses, like (16 A).</li>
<li>Joining a group turns on MQTT on the unit with its own login. Leaving the group turns it off. Units that are not in a group can use MQTT any way you like.</li>
<li>If the server stops, units keep charging at their last setting. The load balancer restarts on its own.</li>
</ul>

<h2 id="clock">How unit clocks are set and kept right</h2>
<ul>
<li>Every <?=oev_v(hp_dur($K['clk_every']))?> the collector reads each unit's clock and compares it with the server's clock.</li>
<li>Why several reads: each read has to travel over the network and back, and that delay changes from one read to the next. A single read only tells you the unit's time was somewhere between when the question was sent and when the answer came back. So it takes up to 3 good reads (up to 6 tries, ignoring any answer slower than 1.5 seconds) and uses only the time window that all of them agree on. That is tighter than any one read, so the difference between the two clocks is as accurate as the network allows. If the reads don't agree, nothing is changed.</li>
<li>If a unit is <?=oev_v(hp_dur($K['clk_off']))?> or more off, the server sets it, then reads it back to make sure. It never does this more than once every <?=oev_v(hp_dur($K['clk_min']))?> for a unit.</li>
<li>WiFi v5 units that get the time from the internet (NTP) are only checked, not set.</li>
<li>Times use the site's time zone: <?=oev_v(oev_timezone())?><?=$S['timezone'] === '' ? ' (the server default)' : ''?>. Now it is <?=oev_v(date('g:i a T'))?>. The 2.x controllers keep local time, so they follow it too.</li>
<li>Schedules run on the unit's own clock, which is why keeping it right matters.</li>
</ul>

<h2 id="fw">Firmware: what works and known issues</h2>
<ul>
<li><b>Recommended: ESP32-based units</b> running OpenEVSE WiFi firmware v4 or later (v5 is current). They have the most features and work best with this site: they report changes right away, keep accurate time from the internet, and accept settings reliably.</li>
<li><b>WiFi v5 (ESP32) note:</b> the load balancer's limit is held in the WiFi module's memory and is lost when it restarts. The load balancer notices and sets it again.</li>
</ul>
<p style="margin:8px 0 2px 0"><b>Legacy units (ESP8266 WiFi modules)</b> still work, with these notes:</p>
<ul>
<li><b>Use 2.9.1</b> (the last ESP8266 release). Units on 2.8.2 work too.</li>
<li><b>Logins on 2.9.1:</b> 2.9.1 uses a stronger login method (Digest). The site handles both; just enter the unit's username and password in Settings if it has one.</li>
<li><b>Mixed-up replies:</b> the WiFi module talks to the charger on the same line the server uses, so a reply can come back to the wrong question. The server tags each question and only uses replies that carry its tag, and retries when one is wrong.</li>
<li><b>MQTT on 2.9.1:</b> replies to commands sent over MQTT are not tagged, so the load balancer only listens on MQTT and makes all changes over the web.</li>
<li><b>Starting up on 2.9.1:</b> after a restart the charger starts at its saved current straight away, before WiFi is up. That is why the saved currents in a load group must fit the group's capacity.</li>
<li><b>Sleeping units:</b> while a unit is asleep, its pilot setting can show an old value until it wakes.</li>
</ul>
<h2 id="volts">Voltage and power (kW)</h2>
<ul>
<li>Power is amps &times; volts. Most units have no voltage sensor: the voltage they report is just a default for their service level. So this site uses the Level 1 and Level 2 voltages in Settings &gt; Preferences (now <?=oev_v($S['volts_l1'] . ' V')?> and <?=oev_v($S['volts_l2'] . ' V')?>).</li>
<li><b>If a unit has a real voltage sensor</b> (a voltmeter add-on fitted and turned on in the unit), tick "This unit has its own voltage sensor" for it in Settings &gt; Units. Then the voltage the unit reports while charging is used instead of the Level 1 / Level 2 setting, on every page and in the session reports. Test connection shows the voltage a unit reports, as a hint.</li>
<li>The load balancer works in amps only, so voltage never changes how current is shared.</li>
</ul>

<h2 id="mail">Email notifications</h2>
<ul>
<li>Set the mail server under Settings &gt; Preferences. Any SMTP service works; Mailjet is a good choice and has a free plan.</li>
<li><b>Errors:</b> a unit shows an error (the red states, like a ground fault, stuck relay or over temperature), a unit isn't heard from for longer than the set time (now <?=oev_v($hbm >= 60 && $hbm % 60 === 0 ? ($hbm / 60) . ' hours' : $hbm . ' minutes')?>), or a part of the system stops (see System status). You get another email when it is OK again.</li>
<li><b>Reboots:</b> a unit restarted. <b>Finished sessions:</b> a car was unplugged, with the energy and charging time.</li>
<li>At most one email per unit and kind each hour. Each user picks which of these they get under Settings &gt; Users.</li>
<li><b>Plug-in reminders:</b> an email when no car is plugged into a unit by the time you set. Each reminder has its own "Send to" list (Settings &gt; Preferences), and goes only to those addresses.</li>
</ul>

<?php
$NU = array(); try { $NU = oev_db()->query('SELECT id, name, loadgroupid FROM evses ORDER BY id')->fetchAll(); } catch (Exception $e) {}
$NS = oev_host_suggest($NU); $NV = array_values(array_filter($NU, 'oev_unit_visible'));
?>
<div id="namesec">
<h2 id="names">Names: unit number, hostname, Emoncms node, MQTT topic</h2>
<ul>
<li><b>Unit number</b>: the number this site gives each unit (# on Settings &gt; Units). Everything else is tied to it.</li>
<li><b>Name</b>: what you call the unit on this site, for example <?=$NV ? oev_v($NV[0]['name']) : 'Garage 1'?>. Change it any time.</li>
<li><b>Emoncms node</b> = the unit number (unit 4 sends as node <?=oev_v('4')?>). Set for you by Internal logging.</li>
<li><b>MQTT topic</b> = <?=oev_v('openevse/u')?> + the unit number, for units in a load group. The load balancer finds each unit by this topic, so it can't be renamed.</li>
<li><b>Hostname</b>: the name the unit gives itself on the network. Routers like the Deco usually show it, unless you renamed the device in the router's app. Settings &gt; Units suggests one made from the name:
<ul>
<li>lowercase; anything that is not a letter <?=oev_v('a-z')?> or digit <?=oev_v('0-9')?> becomes a dash; repeated dashes become one; dashes at the ends are dropped;</li>
<li>at most <?=oev_v(OEV_HOST_MAX . ' characters')?>;</li>
<li>if two units come out the same, the second one gets <?=oev_v('-')?> and its unit number added; if nothing is left, it is <?=oev_v('evse-')?> and the unit number.</li>
</ul></li>
<?php if ($NV) { echo '<li>Here: '; $o = array(); foreach ($NV as $u) $o[] = h($u['name']) . ' &rarr; ' . oev_v($NS[(int)$u['id']]); echo implode('; ', $o) . '.</li>'; } ?>
<li><b>Set hostname on unit</b> (Settings &gt; Units, admins) saves it on the unit and restarts only the unit's WiFi module, so it is offline for about 20 seconds; charging carries on. Then it reads the unit again to check. You can type your own hostname instead; it is cleaned up by the same rule.</li>
<li>On ESP32 (v5) units a manual override lives only in the WiFi module, so while one is active the hostname is saved but the WiFi is not restarted; the new name then takes effect at the next restart.</li>
</ul>
</div>

<h2 id="logins">Logins</h2>
<ul>
<li>Admins can view and change everything, in every load group. View-only logins can look but can't press buttons or change settings, and can be limited to certain load groups.</li>
<li>A login can be limited to some load groups. Units not in a group are only shown to logins that see all groups.</li>
<li>You stay logged in on each device until you log out (Account). Change your password there too.</li>
<li>A view-only link (Settings) lets anyone with the link see what is happening right now (the home page and each unit's live status) without a login. It shows no session reports, graphs, history or logs. Turn it off at any time.</li>
<li>Your initials in the circle at the top right open a menu: Account (your name and password), Help, Settings for admins, and Log out.</li>
</ul>
<?php if (oev_is_admin()) { ?>
<h2 id="sys">System status (admins)</h2>
<ul>
<li>Settings &gt; System status checks the parts that run in the background: the MQTT broker (Mosquitto), the load balancer and its MQTT listener, the data collector, the units' 30-second readings, and the jobs and notifications task.</li>
<li>Each shows OK or a plain-words problem, with when it was last seen. A red dot on your initials means something needs a look.</li>
<li>Problems lasting 2 minutes are emailed to users who get Errors (when notifications are on).</li>
</ul>
<div id="setupsec">
<h2 id="setup">Setup (admins)</h2>
<p style="margin:4px 0">The full step-by-step guide is <b>README.md</b> in the site folder (<?=oev_v(__DIR__)?>). In short, the system has these parts:</p>
<ul>
<li><b>The web site</b>: PHP <?=oev_v(PHP_VERSION)?> on <?=oev_v(strtok((string)($_SERVER['SERVER_SOFTWARE'] ?? 'the web server'), ' '))?>, with its data in two SQLite files, openevse.db (settings) and sessions.db (readings).</li>
<li><b>Two Docker containers</b>: the MQTT broker (Mosquitto) and the load balancer (coordinator). Both start on their own after a restart.</li>
<li><b>Two scheduled tasks</b>, every minute, as root: the session collector, and the jobs and notifications task (unit setup, emails, System status).</li>
<li><b>The encryption key</b> for saved passwords, in <?=oev_v('private/key/secret.key')?>. Back it up: without it, saved unit and mail passwords have to be typed in again.</li>
<li><b>Lockdown</b>: .htaccess files keep the private, collector, load balancer and backup folders off the web. The site is built for Apache, which reads them.</li>
<li><b>Units</b>: add them under Settings &gt; Units and press Test connection. "Internal logging" sets up Emoncms for you, and joining a load group sets up MQTT for you.</li>
</ul>
</div>
<?php } ?>
<p><a href="./">&larr; Home</a><?=oev_is_admin() ? ' &middot; <a href="settings.php">Settings</a>' : ''?></p>
</div></div></body></html>
