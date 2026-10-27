#!/bin/sh
# OpenEVSE: unit setup jobs from Settings + email notifications + System status.
# Run every minute as root (cron or any scheduler), through the launcher
# in README.md that only runs this file if it is owned by root, mode 755, not writable by
# anyone else and not a hard link (the site folder is writable by the web user, so the
# check is done on the opened file itself).
# Root is only used for Docker: the container states and the Mosquitto login file.
# Everything that touches the site, its files or the units runs as the web user,
# so root never writes into the site folder itself (no symlink tricks possible).
#
# Nothing to edit here. The site folder is worked out from where this file is
# (<site>/private/jobs_root.sh, also when the launcher runs it as /dev/fd/3).
# From <site>/loadbalancer/.env (read as text, never run): RUN_UID (the web user),
# OEV_NAME (container name prefix, default "openevse"). PHP and Docker are found on
# the PATH (the newest of php8.4/php84 ... php is used); set OEV_PHP / OEV_DOCKER in the scheduler to override.
SELF=$(readlink -f "$0" 2>/dev/null)
case "$SELF" in */private/jobs_root.sh) SITE=${SELF%/private/jobs_root.sh} ;; *) logger -t openevse "jobs_root.sh: can't tell the site folder from '$0'"; exit 1 ;; esac
SEC=$SITE/loadbalancer/secrets
STD=$SITE/private/status
ENVF=$SITE/loadbalancer/.env
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH
envv(){ sed -n "s/^$1=//p" "$ENVF" 2>/dev/null | tail -n 1 | tr -d '\r'; }
WUID=$(envv RUN_UID | tr -cd '0-9'); [ -n "$WUID" ] || WUID=$(stat -c %u "$SITE/collector" 2>/dev/null)
if [ -z "$WUID" ] || [ "$WUID" = 0 ]; then logger -t openevse "jobs_root.sh: web user unknown or root (set RUN_UID in loadbalancer/.env)"; exit 1; fi
N=$(envv OEV_NAME | tr -cd 'a-z0-9_.-'); N=${N:-openevse}
CB=$N-mosquitto; CC=$N-lb-coordinator
PHP=${OEV_PHP:-}; if [ -z "$PHP" ]; then for p in php8.4 php84 php8.3 php83 php8.2 php82 php; do PHP=$(command -v $p) && break; done; fi
DOCKER=${OEV_DOCKER:-$(command -v docker)}
IMG=eclipse-mosquitto:2
asweb(){ sudo -u "#$WUID" "$@"; }
LOCK=/tmp/openevse_jobs_root.$(printf '%s' "$SITE" | cksum | cut -d' ' -f1).lock
exec 9>"$LOCK"; flock -n 9 || exit 0
log(){ echo "$(date '+%Y-%m-%d %H:%M:%S') $1" | asweb sh -c "cat >> '$SITE/collector/jobs.log'"; }
[ -n "$PHP" ] || { logger -t openevse "jobs_root.sh: php not found"; exit 1; }

# System status (Settings > System status): container states + when this task ran. No secrets.
# The keys stay "openevse-mosquitto" / "openevse-lb-coordinator" whatever OEV_NAME is.
cst(){ [ -n "$DOCKER" ] && $DOCKER inspect -f '{{.State.Status}}{{if .State.Health}} {{.State.Health.Status}}{{end}}' "$1" 2>/dev/null | tr -cd 'a-z ' || true; }
B=$(cst "$CB"); C=$(cst "$CC")
printf '{"time":%s,"containers":{"openevse-mosquitto":"%s","openevse-lb-coordinator":"%s"}}\n' "$(date +%s)" "${B:-missing}" "${C:-missing}" \
	| asweb sh -c "cat > '$STD/system.json.tmp' && mv -f '$STD/system.json.tmp' '$STD/system.json'"

asweb "$PHP" "$SITE/collector/jobs.php" --logins

if [ -f "$SEC/reload.flag" ] && [ -n "$DOCKER" ]; then
	T=$(mktemp -d /tmp/oevpw.XXXXXX) && chmod 700 "$T"
	# plain "user:password" lines from mqtt_users.json (names and hex passwords only), read as the web user
	asweb "$PHP" -r '$u = json_decode(file_get_contents($argv[1]), true); foreach ((array)$u as $k => $v) if (preg_match("/^[a-z0-9]{1,16}$/", $k) && is_string($v) && preg_match("/^[a-f0-9]{16,64}$/", $v)) echo "$k:$v\n";' "$SEC/mqtt_users.json" > "$T/p"
	# hash in a throw-away container, then copy into the broker's file from inside a container too:
	# any symlink planted in secrets/ resolves inside that container, never on the host itself.
	if [ -s "$T/p" ] && [ "$(stat -c %u "$SEC/mosquitto_passwd")" = "1883" ] \
		&& $DOCKER run --rm -v "$T:/s" $IMG mosquitto_passwd -U /s/p >/dev/null 2>&1 \
		&& $DOCKER run --rm -v "$T:/t:ro" -v "$SEC:/sec" $IMG sh -c 'cat /t/p > /sec/mosquitto_passwd' >/dev/null 2>&1; then
		$DOCKER kill -s HUP "$CB" >/dev/null 2>&1
		asweb rm -f "$SEC/reload.flag"
		log "broker logins rebuilt and reloaded"
	else
		log "ERROR rebuilding broker logins (left as they were)"
	fi
	rm -rf "$T"
fi

asweb "$PHP" "$SITE/collector/jobs.php" --run
asweb "$PHP" "$SITE/collector/notifier.php"
