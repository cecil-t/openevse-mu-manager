# Move sessions.db into data/ (protected folder)

**Do not run the cutover until Greg approves a maintenance window.**
Do **not** create `private/sess_writers_pause` on production until that window.
Do **not** create live `data/sessions.db` until the copy step of the window.
Pre-creating empty `data/` + `data/.htaccess` (no DB file) is safe and preferred.

## Path resolution (app)

1. `private/sess_db_path` — optional one-line override (absolute or site-relative)
2. `data/sessions.db` — if the file exists
3. `sessions.db` at the site root — legacy install, if that file exists (never moved automatically)
4. `data/sessions.db` — nothing exists yet: a new install makes it here (Nov 7, 2026)

Safe CLI (as web user only): `private/sess_sqlite.sh …`  
Consistent copy (requires pause flag): `private/sess_consistent_copy.sh /abs/dest.db`

## Writers pause switch (preferred over kill)

Flag file: `private/sess_writers_pause` (any text; no secrets).

When present:

| Role | Effect |
|------|--------|
| `collector/collector.php` | Exits 0 immediately; does **not** open sessions.db; log line in collector.log |
| `emoncms/receiver.php` | HTTP **503** + body `maintenance` + `Retry-After: 60`; does **not** open DB |
| `collector/jobs.php` | Exits 0; skips unit setup that would touch sessions.db |
| `collector/notifier.php` | Exits 0; skips session-related notify reads |
| Web pages | Keep serving; read-only opens still use current path |
| Load balancer / Mosquitto / coordinator | **Unaffected** (do not stop them) |

There is **no** systemd unit for the collector on Synology in the normal install:
writers are **DSM Task Scheduler** (or cron) invoking PHP every minute as `http`,
plus continuous HTTP pushes to `emoncms/receiver.php`. Do **not** `kill` PHP
workers for cutover — use the pause flag, wait ≤1 minute for the next collector
tick to observe the skip, then copy.

### Receiver 503 vs unit queue

Firmware success is body **`ok`**. A 503/`maintenance` response is **not** a
durable offline queue; 2.x modules push on a **~30 s** timer and typically try
again on a later tick if the post was not OK. Expect **one missed push window
per unit** during a short freeze, then catch-up after resume — not multi-hour
buffering. Confirm on a spare/stage path before production if behavior matters.

## Ownership / mode

```bash
# as root, from /volume1/web/home_hasslers_net/openevse:
mkdir -p data
chown http:http data
chmod 750 data
# ensure data/.htaccess from the package is present
# After the DB lives here:
chown http:http data/sessions.db
chmod 640 data/sessions.db
# clear any -wal/-shm owned by the wrong user before resume
```

Never open the live DB (or create `-wal`/`-shm`) as grokbot.

## Why not `cp` an open WAL database

A plain `cp sessions.db` while the collector/receiver can still write is **not**
crash-safe: the main file and `-wal` can disagree. `PRAGMA wal_checkpoint` from a
**reader** does not give exclusive ownership if another writer is alive.

Required sequence: **pause writers → confirm idle → consistent copy → place
`data/sessions.db` → resume**.

Approved copy methods (as `http`, after pause):

1. `private/sess_consistent_copy.sh /abs/path/data/sessions.db` (`VACUUM INTO`, else `.backup`)
2. Or: `sudo -n -u http sqlite3 "$DB" "VACUUM INTO '.../data/sessions.db';"`

Do **not** rely on “idle but still scheduled” alone.

## Pre-production (no downtime; do anytime after code deploy)

1. Deploy app code with `sess_path.php`, pause hooks, `data/.htaccess` (no `data/sessions.db` yet).
2. `mkdir -p data && chown http:http data && chmod 750 data` — keep resolver on root.
3. Confirm live GETs: `/openevse/sessions.db` → 403; `/openevse/data/` → 404 until folder exists, then deny via htaccess.
4. Inventory external helpers (read-only): DSM Task Scheduler tasks for collector + `jobs_root.sh`; any backup/`cp` of root `sessions.db`; historical `@appdata/openevse_sessions` paths.
5. Optional: `private/stage_data_migration_demo.sh build|test|clean` under `backups/data-migration-stage/` (deny-all).

## Cutover (needs Greg’s OK) — short freeze

Expected freeze: **~1–3 minutes** of missed session writes (pages stay up).

1. Note last push age from System status (receiver line) — do **not** open the DB as grokbot.
2. `touch private/sess_writers_pause` (as root or http).
3. Wait until collector.log shows the pause skip (≤60 s) and pushes return 503.
4. `private/sess_consistent_copy.sh "$PWD/data/sessions.db"` then `chown http:http` / `chmod 640`.
5. Integrity as http: `private/sess_sqlite.sh -readonly 'PRAGMA integrity_check;'`
6. Smoke pages (index / sessions). Resolver now prefers `data/` because the file exists.
7. `rm private/sess_writers_pause` — writers resume into `data/sessions.db`.
8. Verify **≥5 new sample rows / push activity within ~20–40 s** (units push ~30 s; do not command units). System status receiver should go healthy again within a couple of minutes.
9. Move root `sessions.db*` aside into `backups/` (do not delete yet). Update external backup paths.

## Rollback

1. Re-touch `private/sess_writers_pause`.
2. Remove/rename `data/sessions.db*` (clear override file if any).
3. Restore root `sessions.db` from `backups/` if needed.
4. Remove pause flag; confirm pushes/`ok` and System status.

## External helpers (inventory; do not edit outside app from the agent)

- DSM Task Scheduler: collector every minute as `http` → `…/collector/collector.php` (historically also `/volume1/@appdata/openevse_sessions/…` — **confirm on NAS**, do not modify `@appdata` here).
- DSM Task Scheduler: root → `private/jobs_root.sh` launcher (jobs + notifier + system.json).
- Backups / Hyper Backup / rsync that name root `sessions.db` — after cutover include `data/sessions.db`.
- Agent scripts: use `private/sess_sqlite.sh`, never grokbot sqlite on live files.

## Staging kit

`private/stage_data_migration_demo.sh build|test|clean`  
Creates `backups/data-migration-stage/` (covered by backups deny-all). Fixture DB only.
