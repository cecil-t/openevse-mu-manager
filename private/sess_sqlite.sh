#!/bin/sh
# Run sqlite3 against the resolved sessions.db as the web user only.
# Never open the live DB as grokbot/root/your login — that creates -wal/-shm
# owned by the wrong account and can stop the collector.
# Usage:
#   private/sess_sqlite.sh 'SELECT count(*) FROM sessions;'
#   private/sess_sqlite.sh -readonly 'PRAGMA integrity_check;'
#   private/sess_sqlite.sh -bail -cmd '.timeout 2000' 'SELECT 1;'
set -e
SITE=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
PHP=${OEV_PHP:-php}
for p in php8.4 php84 php8.3 php83 php8.2 php82 php; do
	command -v "$p" >/dev/null 2>&1 && PHP=$(command -v "$p") && break
done
WU=${OEV_WEB_USER:-}
if [ -z "$WU" ]; then
	# Synology web user is usually "http"; Debian "www-data"
	for u in http www-data nginx apache; do id "$u" >/dev/null 2>&1 && WU=$u && break; done
fi
[ -n "$WU" ] || { echo "sess_sqlite: set OEV_WEB_USER to the PHP/web account" >&2; exit 1; }
# Resolve as the web user: data/ is mode 750 and invisible to other accounts,
# so is_file(data/sessions.db) would falsely fall back to the legacy root path.
DB=$(sudo -n -u "$WU" "$PHP" -r 'require $argv[1]; echo SESS_DB_PATH;' "$SITE/sess_path.php")
[ -n "$DB" ] || { echo "sess_sqlite: could not resolve SESS_DB_PATH" >&2; exit 1; }
# sqlite3 [OPTIONS] FILENAME [SQL] — peel leading -options, then DB, then rest
OPTS=
while [ $# -gt 0 ]; do
	case "$1" in
		-*) OPTS="$OPTS $1"; shift ;;
		*) break ;;
	esac
done
# shellcheck disable=SC2086
exec sudo -n -u "$WU" sqlite3 $OPTS "$DB" "$@"
