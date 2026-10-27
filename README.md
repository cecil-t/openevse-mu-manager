# OpenEVSE MU Manager

**Multi-Unit Manager with Load Balancing**

A small web site for running several [OpenEVSE](https://openevse.com) car chargers from one place:
live status, session reports, a load balancer that shares one supply between chargers,
email alerts, plug-in reminders, logins and a view-only link. It runs on any Linux server
that has a web server with PHP, and Docker for the load balancer.

No passwords or keys are kept in this repository. Everything secret is made on your server
during setup and stays there (see `.gitignore`).

License: MIT (see LICENSE). The bundled PHPMailer keeps its own license (LGPL 2.1).

OpenEVSE's own API documents: the [HTTP developer guide (WiFi 2.x, PDF)](https://github.com/OpenEVSE/ESP8266_WiFi_v2.x/raw/master/Developers_Guides/Developers%20Guide_HTTP.pdf)
and the [WiFi v4/v5 HTTP API (api.yml)](https://github.com/OpenEVSE/openevse_esp32_firmware/blob/master/api.yml).

## What you need

- **Linux** with root access (Debian, Ubuntu, Raspberry Pi OS, Fedora, a NAS, ...).
- **Apache 2.4 with PHP 8.0 or later** (8.3 or 8.4 recommended), mod_php or PHP-FPM.
  - PHP extensions, needed: `pdo_sqlite`, `curl`, `openssl`, `ctype`, `filter`, `json`.
    Optional: `mbstring` (site names with accents).
    Debian/Ubuntu: `sudo apt install apache2 php php-cli php-sqlite3 php-curl php-mbstring`
    (openssl, ctype, filter and json are built in).
  - `mod_rewrite` on and `.htaccess` files allowed for the site folder (`AllowOverride All`).
    `mod_alias` and `mod_env` are used when present.
- **The same PHP on the command line** (`php`, `php-cli`) for the scheduled scripts.
- **Docker with the compose plugin** (`docker compose`) for two containers: the MQTT broker
  (Mosquitto) and the load balancer. Not needed if you don't use load groups.
- `sudo`, `flock`, `openssl` (or PHP makes the key), and `cron`.
- **HTTPS** is recommended (login cookies are marked Secure on HTTPS). WiFi 2.x units post their
  readings over plain HTTP, so keep `emoncms/` reachable on HTTP from your LAN.
- OpenEVSE units on your network. ESP32 units (WiFi firmware v4 or later) are recommended.
  Older ESP8266 units on 2.9.1 or 2.8.2 also work.

The site works at any web address: the server's root (`https://evse.example.com/`) or a folder at
any depth (`https://example.com/openevse/` or `https://example.com/a/b/openevse/`). All links are
relative, and the cookies and the home-screen app use the folder the site is in.

## Folder layout

```
<site>/                   the web site (index.php, unit.php, settings.php, help.php, ...)
  setup.sh                checks the requirements and does setup steps 2 to 5 (run as root)
  openevse.db             units, load groups, users, settings (SQLite, made by setup)
  sessions.db             readings and charging sessions (SQLite, made by setup)
  emoncms/receiver.php    where units send their 30-second readings ("Internal logging")
  collector/              scheduled scripts: collector.php, jobs.php, notifier.php, setup_auth.php
  loadbalancer/           docker-compose.yml, env.example, coordinator/ (load balancer code), mosquitto/,
                          secrets/ (MQTT logins, made by setup), run/ (heartbeat + log)
  private/                key/secret.key (encryption key), jobs_root.sh (root task), status/,
                          firmware/ (normally empty: a temporary place for firmware files during updates)
  backups/                copies made before changes
  PHPMailer/              email library
```

`collector/`, `loadbalancer/`, `private/` and `backups/` each have an `.htaccess` that denies all web
access. The site's own `.htaccess` also hides the databases, logs, keys, scripts and dot files.

## Setup, step by step

The examples use `/var/www/html/openevse` as the site folder and `www-data` as the web server's user.
Use your own folder and user (`apache`, `http`, ...). `setup.sh` does steps 2 to 5 for you;
the steps are also written out so you can do them by hand.

1. **Copy the site** into your web folder (for example with `git clone` or by unpacking a release).

2. **Check the requirements and set up** (folders, owners, encryption key, `loadbalancer/.env`,
   MQTT logins, databases and the first admin login):
   ```sh
   cd /var/www/html/openevse
   sudo sh setup.sh --check                                         # only checks, changes nothing
   sudo sh setup.sh --web-user www-data --admin you@example.com     # asks for the admin password
   ```
   It is safe to run again: it never replaces the key, the databases, `.env` or the logins.
   **Back up `private/key/secret.key`.** Without it, saved unit and mail passwords can't be read
   and have to be typed in again.

   By hand, the same thing is:
   ```sh
   cd /var/www/html/openevse
   sudo chown www-data:www-data . collector emoncms
   sudo mkdir -p private/key private/status private/firmware backups loadbalancer/run loadbalancer/secrets \
        loadbalancer/mosquitto/data loadbalancer/mosquitto/log
   sudo chown root:root private private/jobs_root.sh && sudo chmod 755 private private/jobs_root.sh
   sudo chown www-data:www-data private/key private/status private/firmware backups loadbalancer/run loadbalancer/secrets
   sudo chmod 700 private/key loadbalancer/secrets
   sudo chown -R 1883:1883 loadbalancer/mosquitto/data loadbalancer/mosquitto/log
   sudo -u www-data sh -c 'umask 077; openssl rand -hex 32 > private/key/secret.key; chmod 400 private/key/secret.key'
   sudo -u www-data env OEV_ADMIN_EMAIL=you@example.com php collector/setup_auth.php
   ```
   (Type the admin password, press Enter, then Ctrl-D.) Then make `loadbalancer/.env` and the logins as
   in step 3.

3. **Load balancer settings and logins** (`setup.sh` makes these; by hand:)
   ```sh
   cd loadbalancer
   cp env.example .env        # set OPENEVSE_SITE (full path of the site), MQTT_BIND (this server's LAN address),
                              # RUN_UID/RUN_GID (id -u www-data, id -g www-data); keep LB_DRY_RUN=1 to start
   sudo -u www-data php tools/make_logins.php     # the load balancer's MQTT login, in secrets/ (not shown)
   sudo -u www-data rm -f secrets/passwd.plain
   sudo touch secrets/mosquitto_passwd && sudo chown 1883:1883 secrets/mosquitto_passwd && sudo chmod 600 secrets/mosquitto_passwd
   sudo -u www-data touch secrets/reload.flag     # the jobs task (step 5) fills in the broker's logins within a minute
   ```

4. **Start the two containers** (MQTT broker + load balancer):
   ```sh
   cd /var/www/html/openevse/loadbalancer && sudo docker compose up -d --build
   ```
   - `openevse-mosquitto` (eclipse-mosquitto:2) listens on `MQTT_BIND:MQTT_PORT` (1883), your LAN only.
     Never forward that port on your router.
   - `openevse-lb-coordinator` runs as the web user and reads the site read-only.
   - Both restart on their own after a reboot (`restart: unless-stopped`).
   - It starts in dry run (`LB_DRY_RUN=1` in `.env`: it only logs what it would do). When the
     load balancer page looks right, set `LB_DRY_RUN=0` and run `sudo docker compose up -d` again.
   - Running a second copy on the same server (a test site): give it its own `OEV_NAME` and
     `MQTT_PORT` in its `.env`, so its containers and port don't clash with the first one.

5. **Add the two scheduled tasks**, both every minute, both started by root.
   With cron, run `sudo crontab -e` and add (`setup.sh` prints these with your paths):
   ```crontab
   * * * * * sudo -u www-data /usr/bin/php /var/www/html/openevse/collector/collector.php
   * * * * * F=/var/www/html/openevse/private/jobs_root.sh; exec 3<"$F" || exit 1; set -- $(stat -L -c '\%u \%a \%h \%F' /dev/fd/3); if [ "$1" = 0 ] && [ "$3" = 1 ] && [ "$4 $5" = "regular file" ] && [ "$2" = 755 ]; then exec sh /dev/fd/3; else logger -t openevse "jobs_root.sh refused: $*"; exit 1; fi
   ```
   (In a crontab a `%` has to be written `\%`.) The first line is the session collector. The second
   is the jobs and notifications task: it has to run as root, only to reload the MQTT broker's logins
   with Docker; everything else in it runs as the web user. Because the web user can write to the site
   folder, the task does not run the script by name: it opens the file and runs it only if it is owned
   by root, mode 755, and not a hard link. `jobs_root.sh` needs no editing: it finds the site folder
   from its own location, the web user from `RUN_UID` in `loadbalancer/.env`, and `php` and `docker`
   on the PATH (`OEV_PHP` / `OEV_DOCKER` override them).

   A systemd timer (`OnCalendar=minutely`) running the same commands works too (write `%` as `%%` there).

   On a Synology NAS, make the same two tasks in Task Scheduler however you like (user root, every minute).

6. **Log in** at your site's address. The password can be changed under Account (tap your initials,
   top right). Open **Settings** once: that saves the site's web address for the scheduled scripts.

7. **Add your units** in Settings > Units: name, address, and the unit's username and password if its web
   page has a login. Press **Test connection**: it checks the unit before you save and reads its firmware
   and the most current it allows.
   - **Emoncms: Internal logging** (the default) points the unit at `emoncms/receiver.php` on this server
     and sets its key for you. The unit then sends readings every 30 seconds, which keeps the pages fast.
     Its Emoncms node is the unit number (unit 4 sends as node 4).
   - **Load group:** putting a unit in a load group turns on MQTT on the unit with its own login, set up
     automatically by the jobs task within a minute. Taking it out turns MQTT off. Units outside a group
     can use MQTT any way you like.
   - Units in a load group should share one breaker panel or feeder. Set the group's capacity to that limit.
   - **Names:** `names.php` (admins; linked from Settings > Units) shows each unit's number, name, IP,
     hostname, MAC, controller serial, Emoncms node, MQTT topic (`openevse/u<unit number>`) and firmware, read
     live from the units, with a CSV download. Settings > Units suggests a hostname made from the name (rule
     in Help), and "Set hostname on unit" saves it and restarts only the unit's WiFi module.
   - **Site name:** Settings > Preferences. Used in page titles, under the logo, and in email subjects and
     the from-name. Default: OpenEVSE MU Manager.
   - **Site web address:** Settings > Preferences (for example `https://example.com/openevse/`, or
     `https://evse.example.com/` at the root). Used for the link in emails, view-only links, and the
     Emoncms server given to units on Internal logging (`<site web address>emoncms`). Blank = detected:
     the pages use the address they were opened with, and the scheduled scripts use the last https
     address seen when an admin opened Settings. Fill it in if the site is behind a proxy that changes
     the path, or if you only use plain http.

8. **Email (optional):** Settings > Preferences > Mail server. Any SMTP service works (port 587 with TLS is
   usual). Turn on "Send notifications" when it works, then pick what each user gets under Users.

9. **Check it:** Settings > System status should show everything OK: jobs task, MQTT broker, load balancer,
   MQTT listener, data collector and the units' readings. `sudo sh setup.sh --check` checks the server side.

## Locking it down

- Every page needs a login, except `emoncms/receiver.php` (the units post to it, with their own key),
  `login.php`, and the view-only link if you make one.
- The `.htaccess` files deny web access to the library files, `collector/`, `loadbalancer/`, `private/`,
  `backups/`, dot files, a `.git` folder, and to `*.db *.txt *.md *.bak *.log *.lock *.key *.sh *.env *.ini`
  and similar files (only those file endings, so a name like `feedback.js` is still served). After setup, check from another computer that `openevse.db`, `private/key/secret.key`,
  `loadbalancer/.env` and `collector/jobs.log` all return 403 or 404.
- **Folder permissions:** keep the site folder owned by the web user and not writable by other users
  (`chmod 755`). `private/` and `jobs_root.sh` stay owned by root. If the folder has to stay writable by
  others, the jobs task's launcher still refuses a script that isn't root's.
- Logins: salted password hashes, cookies are HttpOnly, SameSite=Lax and Secure on HTTPS, and every form
  has a CSRF token. Unit and mail passwords are stored encrypted (AES-256-GCM, key in `private/key/`).

## Updating

Copy new files over the old ones, keeping the owners (`private/jobs_root.sh` stays root's, mode 755).
Your databases, key, `.env`, logins and logs are not part of the files, so they stay. Then run
`sudo sh setup.sh` again (it adds any new database tables or columns) and, after changing anything in
`loadbalancer/`, `sudo docker compose up -d --build` in `loadbalancer/`. The load balancer also loads
`include_v5.php` (and `include.php`, `util_lib.php`) when it starts, so after changing those, restart it
when convenient: `sudo docker compose restart coordinator`.

Shared code: `util_lib.php` (h(), ago(), the read-only sessions.db connection), `live.js` (the 5-second
live update and its coloured dot on the home, unit and load balancer pages), `include_v5.php`
evse_post_2x() (saving a form on a WiFi 2.x unit) and `loadbalancer/tools/users_lib.php` (the MQTT login file).
