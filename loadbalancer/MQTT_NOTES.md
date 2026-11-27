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
- *Broker down.* Units keep charging at their last temporary current. They retry the broker
  every 5 s on their own. The coordinator keeps balancing over the web, a bit slower, and
  tries its own MQTT connection again every 30 s.
- *Coordinator down.* Units that stay up are left as they are. A car keeps its last
  temporary current. An idle unit keeps its temporary share (at least 6 A, up to a fair
  share of the limit) or stays paused and draws nothing, if idle pause was already on.
  None of that is written to the saved current. Saved currents are each unit's own full
  rating, so they do not have to add up to the group limit. New cars are not re-balanced
  until the coordinator is back. Docker restarts it automatically; the status page turns
  red after 90 s. Accepted risk: a unit that restarts (a power cut) comes back at its
  saved current, and the group can go over its limit until the coordinator re-applies the
  shares. A unit that was asleep and not counted (sleep-free) can wake and draw up to 6 A
  over.
- *2.9.1 boot:* the controller starts charging from its saved current straight away; it
  doesn't wait for WiFi or MQTT. That saved current is the user's full rating. The
  coordinator sets the temporary share again when it sees the unit (MQTT "connected", the
  pilot above its share, or the next web read). Until then the group can be over its
  limit. The saved values do not have to add up to the limit.
- *Saved writes:* the coordinator does not save currents. Every share is temporary
  (WiFi 2.x `$SC N V`, v5 a claim in the WiFi module's memory, with the same `$SC N V`
  only if the claim did not take). The saved (EEPROM) current is the user's, set on the
  unit page, and stays there. Balancing does not wear the EEPROM.
- *Security:* LAN only (bound to the server's LAN address, MQTT_BIND in .env), passwords required, no internet exposure.
  It isn't encrypted on the LAN (the units' 2.9.1 firmware has no TLS for MQTT).
