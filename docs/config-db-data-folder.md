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

## Who writes openevse.db (all go through oev_cfg_db_open)
- Web (PHP as http): sign-in (login_fails, auth_tokens, users.last_login_at, rehash), token / public-link
  `last_used_at` touches on page views, logout, Settings saves (settings, units, groups, users,
  public links, jobs queue, reminders), account.php.
- Task Scheduler as http, every minute: collector/jobs.php and collector/notifier.php
  ("OpenEVSE jobs and notifications" -> private/jobs_root.sh). collector/collector.php only reads it.
- collector/setup_auth.php (setup / upgrades; the only code allowed to create the file).
- Coordinator container (uid 1023): READ-ONLY, reopens on every 60-s reload and re-resolves the path
  (needs the new coordinator.php, i.e. one restart; its heartbeat then carries cfg_resolver=1).

## Writer exclusion (what the code enforces)
- Every write-capable config connection made through oev_cfg_db_open() takes a SHARED flock on
  data/cfg_db.lock BEFORE resolving the path and holds it until its process ends (a web request,
  one jobs/notifier run). Read-only opens (coordinator, report pages, connection.php) take none.
- data/cfg_db_maint (made only by cfg_db_move.php): new connections open read-only; sign-in
  pauses, token/public-link touches and logout deletes are skipped; jobs.php, notifier.php and
  setup_auth.php exit at start. Saves during the window fail with an error (not lost).
- cfg_db_move.php takes the lock EXCLUSIVE before copying and keeps it until publication, so it
  WAITS for any open write-capable connection, including one holding an uncommitted transaction
  (test R1, move and rollback), or aborts after --lock-wait with nothing published and the write
  kept (R2). New writers arriving meanwhile wait (bounded; web 503) and then open the new path (R4, R5).
- With the lock held it also asks SQLite for BEGIN IMMEDIATE and checks for -journal/-wal/-shm:
  a writer outside the contract caught in a transaction aborts the move with its write kept (R3).
- The first drafts used timers (drain/settle) and were unsafe: a transaction opened before the flag
  and committed after the settle wrote into the archived old file. Removed; no timers remain.

## Honest limits (not enforced by code)
- Anything that writes openevse.db without oev_cfg_db_open() (an old PHP request still running code
  from before the deploy, a hand-run sqlite3, another tool) is not excluded. The script refuses until
  the lock-contract code is --min-code-age (900 s) old and no jobs/notifier/setup process predates it;
  web requests are bounded by PHP's max_execution_time, which must be below that.
- A non-contract writer that starts writing after the BEGIN IMMEDIATE check is not caught by it.
  SQLite refuses writes from a handle whose file was renamed (SQLITE_READONLY_DBMOVED) only for
  transactions that start after the rename.
- So the plan relies on: no human edits during the window (Greg agreed: no Settings changes), and the
  known background writers being drained/paused by the flag and lock.

## Minimal plan (each step needs Greg's approval)
1. Deploy the code (backups, owner 1023:1023). Behaviour unchanged: still ./openevse.db.
   sessions.db is not touched by any of this.
2. Restart the coordinator container once (Mosquitto untouched). Needed: the running coordinator reads
   ./openevse.db by a fixed path and would fail its reloads after the move; the new one re-resolves.
   Load balancing pauses only while the container restarts. Do it when no test charge needs it.
3. Greg makes no Settings changes. (No fixed wait; the script checks open handles instead.)
4. `sudo -n -u http php private/cfg_db_move.php --check`, then `--move`. Usually a few seconds; longer
   only if a page/job is mid-write (bounded by --lock-wait=120, then aborts safely).
5. Check System status shows data/openevse.db; /openevse/openevse.db -> 404; /openevse/data/ -> 403.
   Later `--verify`, then delete data/openevse.db.premove-* when happy. No symlink exists.
Rollback: `--rollback` (same procedure; copies the CURRENT data/openevse.db back).
Tests: `php tests/cfg_db_path_test.php` (synthetic fixtures, real concurrent processes).
