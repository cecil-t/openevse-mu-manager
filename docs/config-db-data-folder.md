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

## Maintenance contract (private/cfg_db_maint, created/removed only by cfg_db_move.php)
- Every new config connection opens read-only: a write FAILS with an error instead of being lost.
- Sign-in shows "paused for a minute"; token touches and logout deletes are skipped (pages still work).
- jobs.php, notifier.php, setup_auth.php exit at start (logged).
- Opens never create the file (SQLITE_OPEN_READWRITE without CREATE; PHP 8.1+; NAS PHP 8.4, image 8.3).

## Why the first draft (8719604) was unsafe (proved in scratch tests, Nov 7)
- It took SQLite's lock, then copy()/hash_file() opened and closed the DB file in the same process:
  POSIX locks are per process, so closing ANY descriptor drops SQLite's lock. A writer got through
  in 0.00 s instead of waiting (control: 1.5 s wait without the copy).
- A writer waiting on the old file wrote into it after the rename (the write was in the premove copy,
  not the live DB), and the script only reported it afterwards (exit 3).
- It left a permanent openevse.db symlink in the site folder.

## How the new script stays safe (tests/cfg_db_path_test.php, real concurrent processes)
1. Preflight: regular file, no -wal/-shm/-journal, journal_mode asked from SQLite (WAL refused even
   with no sidecars on disk), run as owner, no flag already, coordinator heartbeat has cfg_resolver.
2. Flag on; wait for jobs/notifier/setup processes to end; then --drain seconds (default 30) for web
   requests that opened before the flag. A write in this time lands in the old file BEFORE the copy (test A).
3. sha256, VACUUM INTO (no descriptor of the DB is closed while SQLite holds a lock), sha256 again:
   any write during the copy aborts with nothing published (test C). quick_check + per-table row counts; fsync.
4. Publish: rename copy -> data/openevse.db, then rename old -> data/openevse.db.premove-<ts>.
   The resolved path existed on every one of ~7 million checks during the move (test E).
5. An old handle that writes after the rename gets "attempt to write a readonly database" from SQLite
   itself (SQLITE_READONLY_DBMOVED, SQLite 3.8.3+; NAS 3.40.0): it fails loudly (test B).
   Belt and braces: if the old file changes during --settle (default 10 s) the script puts it back,
   moves the copy aside as .failed-<ts>, exits 3; nothing lost (test B2).
6. Flag off. --verify re-checks the old file's saved sha256 any time later.
Residual: none found for writes; a page that hit the window shows an error and the user retries.

## Phases (each needs Greg's approval)
1. Deploy code (backup, owner 1023:1023). No behaviour change: everything still uses ./openevse.db.
2. Restart the coordinator once so it runs the new coordinator.php (heartbeat shows cfg_resolver=1).
   Mosquitto is not restarted. Control pauses only for the container restart itself.
3. Window: `sudo -n -u http php private/cfg_db_move.php --check`, then `--move` (about 30-45 s with
   defaults; sign-in and saves fail during it, pages and charging continue). Then System status shows
   data/openevse.db; GET /openevse/openevse.db -> 404, /openevse/data/ -> 403.
4. Later: `--verify`; delete data/openevse.db.premove-* when happy. No symlink to remove.
Rollback: `--rollback` (same procedure, copies the CURRENT data/openevse.db back; keeps later writes).
