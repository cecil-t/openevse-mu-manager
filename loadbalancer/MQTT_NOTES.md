# MQTT on the chargers: plain-language notes

**What it is:** each charger keeps a connection to the broker on the server (Docker, LAN only,
with a login per charger). Chargers report their state every 30 s and right away on events
(plug in, start, stop). The coordinator listens, so it reacts within seconds instead of
waiting for its next 20 s web poll.

**Is it permanent?** The MQTT settings are saved in each WiFi module (they survive reboots
and power cuts) until changed on the unit's page or via the API. Turning MQTT off is the
same switch. Nothing in the charging controller changes because of MQTT.

**Four current limits.** These are different. The notes below say which one they mean.

- **(a) Pilot menu on the unit.** The drop-down in the charger's own web page. Up to 80 A
  on Level 2, 24 A on Level 1.
- **(b) Max current.** What MU Manager will let that unit's pilot be set to. Stored as
  `evses.breaker` (Settings > Units, "Max current (Amps)"). It can't be above the unit's
  hardware maximum. Next to it, Settings shows the required minimum circuit breaker:
  the setting × 125% (chargers are a continuous load), rounded up to the next standard
  breaker size.
- **(c) Pilot setting.** The current saved on the unit. It survives a reboot. It can't be
  above (a) or (b).
- **(d) Temporary limit.** What the coordinator sets (`$SC N V` on WiFi 2.x, a claim on v5).
  It overrides (c) until the unit restarts. Only (d) is set by the coordinator. Across a
  load group, the temporary limits add up to the group limit or less.

**Good**
- Fast reaction to plug-ins and reboots (a unit says "connected" when it comes back, and
  the coordinator re-checks and re-sends its current).
- Less polling of the units over the web.
- Per-unit logins: a unit can only use its own topic; only the coordinator can send
  commands to units.

**Caveats**
- *Replies aren't tagged.* On 2.9.1, commands sent over MQTT get untagged replies, so two
  replies can cross. The coordinator therefore only *listens* on MQTT and does all
  changes and checks over the web with tagged RAPI (the same reliable path the site uses).
- *Retained messages.* A retained command is re-delivered every time a unit reconnects,
  so an old value could be re-applied hours later. The coordinator never sends retained
  commands; only its own status (openevse/lb/status) is retained.
- *Broker down.* Units keep charging at their last temporary limit (d). They retry the broker
  every 5 s on their own. The coordinator keeps working over the web, a bit slower.
- *Coordinator down.* Nothing changes on the units. The temporary limits (d) are always
  within the group limit, so the circuit stays safe while the units stay powered. New cars
  just don't get re-balanced until it's back. Docker restarts it automatically; the status
  page turns red after 90 s. Accepted risk: a unit that loses power comes back on its pilot
  setting (c), so the group can go over until the coordinator sets (d) again.
- *2.9.1 boot:* on boot the controller uses its pilot setting (c), straight away, before
  WiFi or MQTT. It does not use the temporary limit (d). The pilot settings do not have to
  add up to the group limit. That's the accepted risk above.
- *Saved writes:* the coordinator only sets temporary limits (d). It never writes the pilot
  setting (c) to EEPROM. `coordinator/tests.php` checks this.
- *Security:* LAN only (bound to the server's LAN address, MQTT_BIND in .env), passwords required, no internet exposure.
  It isn't encrypted on the LAN (the units' 2.9.1 firmware has no TLS for MQTT).
