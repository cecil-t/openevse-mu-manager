# Move openevse.db (config DB) into data/

Prepared Nov 7, 2026. Not done on production yet; needs an approved window.

## Where things are (NAS, file metadata only)
| DB | Now | After | Owner / mode | Sidecars |
|---|---|---|---|---|
| sessions.db (readings) | data/sessions.db | unchanged | http 1023:1023, 640 | none at rest (rollback journal) |
| openevse.db (config) | openevse.db (site root) | data/openevse.db | http 1023:1023, 644 (kept) | none at rest |
| mosquitto.db | loadbalancer/mosquitto/data/ | **leave** (broker persistence, not app SQLite; owned 1883, Docker bind) | 1883:1883, 600 | n/a |

data/ is http:http 750 with a deny-all .htaccess. `backups/` is not touched.
`phpliteadmin.config.php` (directory ".") is left over; there is no phpliteadmin.php, so nothing uses it.

## Code (deploy first; works before AND after the move)
`db_path.php` resolves the path from the site folder (never the cwd):
`private/cfg_db_path` override -> `data/openevse.db` if it exists -> `openevse.db` if it exists ->
`data/openevse.db` (new install). `oev_cfg_db_open()` refuses a missing file, so a reader can't make
an empty DB; only `collector/setup_auth.php` may create it.
Switched: settings_lib, auth_lib, volts_lib, connection, include_v5 (EVSE_DB), index, sys_lib
(System status size), collector/jobs, collector/notifier, collector/setup_auth, setup.sh, and the
coordinator (re-resolves on every 60-s config reload).

## Who has openevse.db open
- Web pages (Apache/PHP as http): open per request; follow the move on the next request.
- Task Scheduler (root -> runs as http): "OpenEVSE session collector" (collector/collector.php) and
  "OpenEVSE jobs and notifications" (private/jobs_root.sh -> jobs.php, notifier.php), every minute;
  each run re-resolves.
- Coordinator container (uid 1023, site mounted read-only at the same path): reloads config every
  60 s. Until it is restarted with the new coordinator.php it keeps the old path, which the move
  turns into a symlink `openevse.db -> data/openevse.db`, so it keeps reading the right file.
  Restart is NOT needed for the move; do it later in any normal window to drop the symlink use.
  Its in-memory config keeps control running between reloads.

## Window (approval needed)
1. Deploy the code above (backup in backups/, owner 1023:1023 kept). No behaviour change yet.
2. `sudo -n -u http php private/cfg_db_move.php --check`
3. `sudo -n -u http php private/cfg_db_move.php --move` - holds SQLite's write lock while it copies,
   checks (quick_check + sha256), renames into data/, keeps the old file as
   data/openevse.db.premove-<ts>, and swaps the root name for the compat symlink. Takes about
   2 seconds; readers keep working; a Settings save/login in that moment waits on the lock.
   Exit 3 = a waiting writer wrote to the old file after the copy: re-apply by hand from the
   premove file or roll back.
4. Check: System status shows data/openevse.db; `/openevse/openevse.db` and `/openevse/data/` deny.
Charging does not depend on the web app: the coordinator keeps its loaded config and the units
keep their own limits. No unit commands, no restarts, no recording pause needed.

## Rollback (keeps writes made after the move)
`sudo -n -u http php private/cfg_db_move.php --rollback` copies the CURRENT data/openevse.db back
under the same lock, replaces the symlink with the file, and renames data/openevse.db aside.

Tests: `php tests/cfg_db_path_test.php` (synthetic fixtures only).
