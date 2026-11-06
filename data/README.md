# Session database folder

Live file after cutover: `sessions.db` (plus SQLite `-wal` / `-shm` while writers run).

Web access is denied by `.htaccess` (and by the site root `*.db` rule). Still keep
this directory mode `750` (or `700`) owned by the web user so other accounts on
the host cannot read the DB even via a misconfigured vhost.

## Ownership (Synology / Linux)

```bash
# from the site folder, as root:
mkdir -p data
chown http:http data          # www-data:www-data on Debian
chmod 750 data
# After the DB is moved here:
chown http:http data/sessions.db data/sessions.db-wal data/sessions.db-shm 2>/dev/null
chmod 640 data/sessions.db
```

Only the web user (PHP-FPM / collector / emoncms receiver) should open this file.
Agents and interactive logins must use `sudo -n -u http` (see `private/sess_sqlite.sh`);
never open the live DB as another account — even `sqlite3 -readonly` can create
`-wal`/`-shm` owned by the wrong user and stop logging.

Cutover steps: `docs/sessions-db-data-folder.md`.
