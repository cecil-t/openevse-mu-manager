# OpenEVSE load balancer (Docker)

Shares one circuit's amps between the chargers in a load group (openevse.db `load_groups`,
`evses.loadgroupid`). Two containers: **mosquitto** (MQTT broker, LAN only) and
**coordinator** (PHP, reuses the site's tagged/retrying RAPI + HTTP helpers).

## Rules it follows
- Equal share among plugged-in units, capped at each unit's rating (`evses.breaker`), min 6 A.
- Units with nothing plugged in get the leftover amps up to the fair share (limit / units in
  the group, e.g. 80 / 4 = 20 A), never below 6 A, so the *saved* currents always add up to
  <= the group limit (if the server or coordinator dies, nothing can overload the circuit).
- Headroom: amps of charging units are read every 5 s (charging units only). A car drawing
  4+ A under its share for 2 min (not in its first 3 min, or 3 min after a raise) is capped
  at draw + max(4 A, 15 %) (min 6 A); the spare goes to the other cars, then idle units.
  The cap is lifted when the car draws within 2 A of it for 15 s (others lowered first).
  Max one cut per 5 min, one raise per 30 s per unit, changes under 2 A ignored; raises wait
  5 s after a lower. Cuts are saved; headroom raises are temporary (`$SC N V`), so after a
  reboot every unit is back at or below its base share. Tuning: `LB_HR` in lb_core.php.
- Schedules: a unit asleep on its schedule counts as idle even with a car plugged in; when it
  wakes, MQTT reports it and it gets its share (others lowered first). The coordinator never
  sends schedule or sleep commands; every RAPI command passes a whitelist ($SC >= 6, $FD,
  $FE, reads) in lb_core.php.
- Pause (only if cars don't fit at 6 A each): WiFi 2.x: saved $SC 6, then $FD 5 s later
  ($FS isn't used: the schedule timer would wake it); resume $FE. v5: claim
  {"state":"disabled"}, resume = release the claim. v5 gets no temporary raises (claims
  can't exceed its saved soft max).
- Tests: `php coordinator/tests.php -v` (split, headroom, second-by-second simulation).
- If cars don't fit at 6 A each, the lowest priority pauses (first plugged in = highest
  priority, unless an optional integer column `evses.lb_priority` is set; lower = first).
- Writes only on change, lowers before raises, confirms each write with a tagged read,
  re-sends when a unit announces "connected" on MQTT (reboot). Every decision is logged.
- Dry run: `LB_DRY_RUN=1` in `.env` (or `dry_run = 1` in `coordinator/lb.ini` when LB_DRY_RUN is blank)
  logs "would set" and never writes. Set 0 to go live, then `docker compose up -d`.

## Layout
- `docker-compose.yml`, `.env` (paths, IDs, port, container name prefix, dry run; no secrets; copy from `env.example`)
- `coordinator/` code + `lb.ini` + `tests.php` (`php coordinator/tests.php`)
- `mosquitto/config/` broker config + ACL (units: only their own `openevse/u<id>/#`;
  coordinator login `lb`: all of `openevse/#`)
- `secrets/` (mode 700, never in backups you share): `mqtt_users.json` (plain logins),
  `coordinator.env`, `mosquitto_passwd` (hashed, owner 1883)
- `run/` coordinator.log, heartbeat.json (every 5 s), state.json (plug-in times)
- `tools/make_logins.php`, `tools/setup_units.php` (one-off unit MQTT setup)
- `.htaccess` deny-all (this folder sits under the web root)

## New install
Done by the main README's setup steps (`setup.sh`, then `docker compose up -d --build` here).
By hand: `cp env.example .env` and edit it, then `sudo -u <web user> php tools/make_logins.php`,
make `secrets/mosquitto_passwd` (owner 1883, mode 600), touch `secrets/reload.flag` (the jobs task
hashes the logins into the broker), `chown -R 1883:1883 mosquitto/data mosquitto/log`, and
`docker compose up -d --build`. Units get MQTT turned on automatically when you put them in a load
group (Settings > Units); `tools/setup_units.php <broker LAN IP> <unit id> ...` is the old one-off way.
Check `run/coordinator.log` and the site's `lbstatus.php`.

Never forward the MQTT port (1883) on the router.

## Day to day
- Status: `<site web address>lbstatus.php` (and the group header on the home page)
- Restart: `docker compose restart coordinator`. Logs: `run/coordinator.log`, `docker logs openevse-mosquitto`
- Dry-run checks without touching units: `php coordinator/coordinator.php --once`
  and `--once --pretend=3,5` (pretend those units have a car plugged in).
