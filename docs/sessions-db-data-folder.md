# Move sessions.db into data/ (protected folder)

**Do not run the cutover until Greg approves a maintenance window.**
This document is preparation only. App code already prefers `data/sessions.db`
when that file exists (`sess_path.php`).

## Path resolution (app)

1. `private/sess_db_path` — optional one-line override (absolute or site-relative)
2. `data/sessions.db` — if the file exists
3. `sessions.db` at the site root — legacy default

Safe CLI read/query (as web user only): `private/sess_sqlite.sh …`

## Ownership / mode (production)

```bash
# as root, from the site folder (/volume1/web/home_hasslers_net/openevse):
mkdir -p data
chown http:http data          # www-data on Debian
chmod 750 data
# ensure data/.htaccess from the package is present (deny-all)
# After the DB lives here:
chown http:http data/sessions.db data/sessions.db-wal data/sessions.db-shm 2>/dev/null || true
chmod 640 data/sessions.db
```

Never open the live DB (or create `-wal`/`-shm`) as grokbot or any non-web account.

## Pre-production (no writer stop)

Safe anytime:

1. Deploy app code that includes `sess_path.php` + wired libs (this change).
2. Create `data/` + install `data/.htaccess` + `chmod 750` / `chown http:http`.
3. Confirm site still uses root `sessions.db` (because `data/sessions.db` does not exist yet).
4. Update **external** helpers that hardcode the root path (see below) so they resolve via `sess_path.php` or `private/sess_sqlite.sh` — do not edit outside this app tree from the agent; list and wait for Greg.

## Cutover (closed-DB, brief; needs Greg’s OK)

Goal: short pause of writers only; pages stay up (read-only against old file until swap, then new path). Transparent for clients once `data/sessions.db` exists.

1. Confirm no open maintenance elsewhere; note last reading time (as http):  
   `private/sess_sqlite.sh -readonly "SELECT max(ts) FROM readings;"` (adjust SQL to schema).
2. Stop / pause **writers only** that open sessions.db (collector tick, emoncms receiver writers, notifier if it writes). Do **not** stop the whole web site. Do **not** command units.
3. As http, checkpoint and close:  
   `private/sess_sqlite.sh "PRAGMA wal_checkpoint(TRUNCATE);"`
4. Copy (do not yet delete) as http:  
   `sudo -n -u http cp -a sessions.db data/sessions.db`  
   (if `-wal`/`-shm` remain after checkpoint, include them or re-checkpoint).
5. Ownership/mode on the copy (http:http, 640).
6. Integrity on the **copy** only (still as http):  
   `private/sess_sqlite.sh -readonly "PRAGMA integrity_check;"`  
   — after step 4 the resolver already points at `data/` once the file exists. If anything fails, remove `data/sessions.db*` so the app falls back to root and abort.
7. Brief smoke: home / sessions page load; one read-only count via `sess_sqlite.sh`.
8. Resume writers. Confirm they open `data/sessions.db` (collector log / `lsof` as root if needed).
9. After ~5 new unit readings appear in the new DB (passive observation only — do not actuate units), rename root leftovers aside into `backups/` (e.g. `sessions.db.pre-data-YYYYMMDD`) rather than deleting immediately.
10. Update any still-hardcoded external backup/cron paths if not done in pre-step 4.

Checksum / integrity checks for rehearsal: use **isolated fixtures** under `tests/tmp` (see `tests/sess_path_test.php`), never the live file.

## Rollback

1. Pause writers again if needed.
2. Remove or rename `data/sessions.db*` (and clear `private/sess_db_path` if set) so resolution returns to root `sessions.db`.
3. If the root file was already moved aside, restore it from `backups/` **before** removing the data/ copy, then resume writers.
4. Verify pages and a new reading.

## Verification after cutover

- Site pages (index, sessions, report7) load without error.
- `SESS_DB_PATH` resolves to `…/data/sessions.db` (`php -r 'require "sess_path.php"; echo SESS_DB_PATH;' as appropriate`).
- `data/.htaccess` still deny-all; HTTP fetch of `/data/sessions.db` is 403/404.
- Passive: ≥5 new readings after resume (no unit commands).
- No unexpected `-wal`/`-shm` owned by non-http users.

## External helpers that may still hardcode the root path

These live **outside** the app tree (or are host cron/DSM tasks). Do **not** change them from this repo task; update in the maintenance window after Greg approves:

- DSM Task Scheduler jobs that invoke a collector or `sqlite3` against
  `/volume1/web/home_hasslers_net/openevse/sessions.db` (historically also
  `/volume1/@appdata/openevse_sessions/…` — confirm current paths on the NAS;
  do not edit `@appdata` from this agent).
- Any shell/`cp`/`rsync` backup that names root `sessions.db` — after cutover
  include `data/sessions.db` (and `-wal`/`-shm` if present). Greg’s rule: app
  backups stay under `…/openevse/backups/` only.
- Hyper Backup / S3 jobs that inventory the site: ensure `data/` is included and
  that root `sessions.db` is not assumed after cutover.
- Ad-hoc agent scripts that opened `sessions.db` as grokbot — replace with
  `private/sess_sqlite.sh` (runs `sqlite3` as `http` via `sudo -n -u http`).
- Optional override: `private/sess_db_path` (one line, no secrets) for a forced path.

### In-app runtime consumers (already path-aware after this change)

Via `SESS_DB_PATH` / `sess_db()` / `oev_sess_ro()`:

- `sessions_lib.php`, `util_lib.php`, `sess_path.php`
- Pages: `index.php`, `sessions.php`, `report7.php`, `help.php`, `include_v5.php`, `unitlist.php`, …
- Collector: `collector/collector.php`, `collector/jobs.php`, `collector/notifier.php`, `collector/push_logic.php`, `collector/setup_auth.php`
- `emoncms/receiver.php` (through sessions_lib)
- CLI helper: `private/sess_sqlite.sh`
- Setup: `setup.sh` (creates `data/`, checks either path)

Inside the app, all PHP consumers go through `sess_db()` / `oev_sess_ro()` / `SESS_DB_PATH` once this change is deployed.
