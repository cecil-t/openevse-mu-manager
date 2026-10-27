#!/bin/sh
# OpenEVSE MU Manager: set up or check a new install on a Linux server. Run as root from
# anywhere; it works on the folder it is in. Safe to run again: it never overwrites the
# key, the databases, .env or the MQTT logins once they exist.
#   sudo sh setup.sh --check                         check the requirements only (no changes)
#   sudo sh setup.sh [--web-user www-data]           make the folders, key, .env, databases, logins
#   sudo sh setup.sh --admin you@example.com         ... and the first admin login (asks for the password)
# It does not start Docker or add the scheduled tasks: it prints the commands for those.
set -u
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH   # sudo and cron often leave /usr/local/bin out
SITE=$(cd "$(dirname "$0")" && pwd -P)
CHECK=0; WU=${WEB_USER:-}; ADMIN=""
while [ $# -gt 0 ]; do case "$1" in
	--check) CHECK=1 ;;
	--web-user) WU=$2; shift ;;
	--admin) ADMIN=$2; shift ;;
	-h|--help) sed -n '2,8p' "$0"; exit 0 ;;
	*) echo "unknown option: $1"; exit 2 ;;
esac; shift; done
ok(){ printf '  OK    %s\n' "$1"; }
warn(){ printf '  WARN  %s\n' "$1"; WARN=$((WARN+1)); }
bad(){ printf '  FAIL  %s\n' "$1"; FAIL=$((FAIL+1)); }
WARN=0; FAIL=0
[ "$(id -u)" = 0 ] || { echo "Run this as root (sudo sh setup.sh ...)."; exit 1; }
[ -f "$SITE/index.php" ] && [ -f "$SITE/collector/setup_auth.php" ] || { echo "$SITE does not look like the OpenEVSE site folder."; exit 1; }

echo "OpenEVSE MU Manager setup: $SITE"
echo "Requirements"
# the web server's user: --web-user, else the usual names
if [ -z "$WU" ]; then for u in www-data apache http; do id "$u" >/dev/null 2>&1 && { WU=$u; break; }; done; fi
if [ -n "$WU" ] && id "$WU" >/dev/null 2>&1 && [ "$(id -u "$WU")" != 0 ]; then ok "web user: $WU (uid $(id -u "$WU"), gid $(id -g "$WU"))"; else bad "web user not found: use --web-user <name> (the user Apache or PHP-FPM runs as)"; WU=""; fi
PHP=${OEV_PHP:-}; if [ -z "$PHP" ]; then for p in php8.4 php84 php8.3 php83 php8.2 php82 php; do PHP=$(command -v $p) && break; done; fi
if [ -z "$PHP" ]; then bad "command-line PHP not found (install php-cli 8.x, or set OEV_PHP=/path/to/php)"
else
	V=$("$PHP" -r 'echo PHP_VERSION;' 2>/dev/null)
	if "$PHP" -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);' 2>/dev/null; then ok "PHP $V ($PHP)"; else bad "PHP $V: version 8.0 or later is needed"; fi
	for x in pdo_sqlite curl openssl ctype filter json; do
		if "$PHP" -r "exit(extension_loaded('$x') ? 0 : 1);" 2>/dev/null; then ok "PHP extension $x"; else bad "PHP extension $x missing"; fi
	done
	for x in mbstring; do
		if "$PHP" -r "exit(extension_loaded('$x') ? 0 : 1);" 2>/dev/null; then ok "PHP extension $x"; else warn "PHP extension $x missing (optional: site names with accents)"; fi
	done
fi
for c in sudo flock readlink stat logger; do command -v $c >/dev/null 2>&1 && ok "$c" || bad "$c not found (needed by the scheduled jobs task)"; done
DOCKER=$(command -v docker || true)
if [ -n "$DOCKER" ]; then ok "docker ($DOCKER)"; if docker compose version >/dev/null 2>&1; then ok "docker compose"; else warn "docker compose plugin not found (needed for the load balancer)"; fi
else warn "docker not found: the load balancer and MQTT broker need it (the rest of the site works without)"; fi
[ -f "$SITE/PHPMailer/src/PHPMailer.php" ] && ok "PHPMailer" || warn "PHPMailer/ missing: email alerts won't work (copy PHPMailer's src/ into PHPMailer/src/)"
[ -n "$WU" ] && [ -n "$PHP" ] && { sudo -u "$WU" test -w "$SITE/collector" 2>/dev/null && ok "collector/ writable by $WU" || { [ $CHECK = 1 ] && warn "collector/ not writable by $WU (setup fixes this)"; }; }

if [ $CHECK = 1 ]; then
	for f in private/key/secret.key openevse.db sessions.db loadbalancer/.env loadbalancer/secrets/coordinator.env; do [ -e "$SITE/$f" ] && ok "$f exists" || warn "$f not made yet"; done
	echo "$FAIL failed, $WARN warnings."; [ $FAIL = 0 ]; exit $?
fi
[ $FAIL = 0 ] || { echo "Fix the failed items above, then run this again."; exit 1; }
U=$(id -u "$WU"); G=$(id -g "$WU")
W(){ sudo -u "$WU" "$@"; }

echo "Folders and owners"
chown "$U:$G" "$SITE" "$SITE/collector" "$SITE/emoncms" 2>/dev/null
mkdir -p "$SITE/private" "$SITE/backups" "$SITE/loadbalancer/run" "$SITE/loadbalancer/secrets" "$SITE/loadbalancer/mosquitto/data" "$SITE/loadbalancer/mosquitto/log"
chown root:root "$SITE/private" && chmod 755 "$SITE/private"
for d in key status firmware; do mkdir -p "$SITE/private/$d"; done
chown "$U:$G" "$SITE/private/key" "$SITE/private/status" "$SITE/private/firmware" "$SITE/backups" "$SITE/loadbalancer/run" "$SITE/loadbalancer/secrets"
chmod 700 "$SITE/private/key" "$SITE/loadbalancer/secrets"; chmod 750 "$SITE/private/firmware"
chown -R 1883:1883 "$SITE/loadbalancer/mosquitto/data" "$SITE/loadbalancer/mosquitto/log"
chown root:root "$SITE/private/jobs_root.sh" && chmod 755 "$SITE/private/jobs_root.sh"
[ "$(stat -c %h "$SITE/private/jobs_root.sh")" = 1 ] || warn "private/jobs_root.sh has more than one hard link: copy it (cp) instead, or the jobs task will refuse it"
ok "private/ (root), key/ status/ firmware/ backups/ run/ secrets/ ($WU), mosquitto data/log (uid 1883)"

echo "Encryption key"
if [ -s "$SITE/private/key/secret.key" ]; then ok "private/key/secret.key already there (kept)"
else W sh -c "umask 077; openssl rand -hex 32 > '$SITE/private/key/secret.key' 2>/dev/null || '$PHP' -r 'echo bin2hex(random_bytes(32)), \"\\n\";' > '$SITE/private/key/secret.key'; chmod 400 '$SITE/private/key/secret.key'" && ok "private/key/secret.key made: back it up" || bad "could not make the key"; fi

echo "loadbalancer/.env"
ENVF=$SITE/loadbalancer/.env
if [ -f "$ENVF" ]; then ok ".env already there (kept)"
else
	IP=$(ip -4 route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([0-9.]*\).*/\1/p'); IP=${IP:-127.0.0.1}
	sed -e "s#^OPENEVSE_SITE=.*#OPENEVSE_SITE=$SITE#" -e "s#^MQTT_BIND=.*#MQTT_BIND=$IP#" -e "s#^RUN_UID=.*#RUN_UID=$U#" -e "s#^RUN_GID=.*#RUN_GID=$G#" "$SITE/loadbalancer/env.example" > "$ENVF"
	chown "$U:$G" "$ENVF"; chmod 640 "$ENVF"
	ok ".env made: OPENEVSE_SITE=$SITE, MQTT_BIND=$IP, RUN_UID=$U, RUN_GID=$G, LB_DRY_RUN=1 (check MQTT_BIND)"
fi

echo "MQTT logins"
SEC=$SITE/loadbalancer/secrets
if [ -s "$SEC/coordinator.env" ]; then ok "secrets/ already there (kept)"
else
	(cd "$SITE/loadbalancer" && W "$PHP" tools/make_logins.php) && W rm -f "$SEC/passwd.plain"
	touch "$SEC/mosquitto_passwd" && chown 1883:1883 "$SEC/mosquitto_passwd" && chmod 600 "$SEC/mosquitto_passwd"
	W touch "$SEC/reload.flag"
	ok "load balancer login made (not shown); the jobs task loads it into the broker within a minute"
fi

echo "Databases"
if [ -n "$ADMIN" ] && ! W "$PHP" -r '$d = new PDO("sqlite:" . $argv[1] . "/openevse.db"); try { exit((int)$d->query("SELECT count(*) FROM users")->fetchColumn() ? 0 : 1); } catch (Exception $e) { exit(1); }' "$SITE" 2>/dev/null; then
	printf 'Password for %s (8+ characters, not shown): ' "$ADMIN"; stty -echo 2>/dev/null; read -r PW; stty echo 2>/dev/null; echo
	printf '%s\n' "$PW" | W env OEV_ADMIN_EMAIL="$ADMIN" "$PHP" "$SITE/collector/setup_auth.php"; R=$?; PW=""
else
	W env OEV_SKIP_ADMIN=1 "$PHP" "$SITE/collector/setup_auth.php" </dev/null; R=$?
fi
[ $R = 0 ] && ok "openevse.db and sessions.db ready" || bad "setup_auth.php failed"

cat <<EON

Next steps (README.md has the details):
1. Start the MQTT broker and the load balancer:
     cd $SITE/loadbalancer && docker compose up -d --build
2. Add the two scheduled tasks to root's crontab (crontab -e as root):
     * * * * * sudo -u $WU $PHP $SITE/collector/collector.php
     * * * * * F=$SITE/private/jobs_root.sh; exec 3<"\$F" || exit 1; set -- \$(stat -L -c '\%u \%a \%h \%F' /dev/fd/3); if [ "\$1" = 0 ] && [ "\$3" = 1 ] && [ "\$4 \$5" = "regular file" ] && [ "\$2" = 755 ]; then exec sh /dev/fd/3; else logger -t openevse "jobs_root.sh refused: \$*"; exit 1; fi
3. Open the site, log in, open Settings once, and check Settings > System status.
EON
[ -n "$ADMIN" ] || echo "   No admin login yet? Run: sudo sh setup.sh --admin you@example.com"
echo "$FAIL failed, $WARN warnings."
[ $FAIL = 0 ]
