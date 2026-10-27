# MQTT on the chargers: plain-language notes

**What it is:** each charger keeps a connection to the broker on the server (Docker, LAN only,
with a login per charger). Chargers report their state every 30 s and right away on events
(plug in, start, stop). The coordinator listens, so it reacts within seconds instead of
waiting for its next 20 s web poll.

**Is it permanent?** The MQTT settings are saved in each WiFi module (they survive reboots
and power cuts) until changed on the unit's page or via the API. Turning MQTT off is the
same switch. Nothing in the charging controller changes because of MQTT.

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
- *Broker down.* Units keep charging at their last saved current; they retry the broker
  every 5 s on their own. The coordinator keeps working over the web, a bit slower.
- *Coordinator down.* Nothing changes on the units. Saved currents are always within the
  group limit, so the circuit stays safe; new cars just don't get re-balanced until it's
  back. Docker restarts it automatically; the status page turns red after 90 s.
- *2.9.1 boot:* the controller starts charging from its saved current straight away; it
  doesn't wait for WiFi or MQTT. That's why the saved values must add up to the limit.
- *Saved writes:* the coordinator saves currents to the controller (EEPROM). That's around
  10-20 writes a day, which lasts decades.
- *Security:* LAN only (bound to the server's LAN address, MQTT_BIND in .env), passwords required, no internet exposure.
  It isn't encrypted on the LAN (the units' 2.9.1 firmware has no TLS for MQTT).
