#!/bin/sh
# Consistent copy of sessions.db AFTER writers are paused (private/sess_writers_pause).
# Runs sqlite3 as the web user only. Never open the live DB as grokbot.
# Usage: private/sess_consistent_copy.sh /abs/path/to/dest.db
# Prefers VACUUM INTO (SQLite ≥3.27); falls back to .backup.
set -e
SITE=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
DEST=${1:?destination .db path required}
case "$DEST" in
	/*) ;;
	*) echo "sess_consistent_copy: destination must be an absolute path" >&2; exit 1 ;;
esac
PHP=${OEV_PHP:-php}
for p in php8.4 php84 php8.3 php83 php8.2 php82 php; do
	command -v "$p" >/dev/null 2>&1 && PHP=$(command -v "$p") && break
done
DB=$("$PHP" -r 'require $argv[1]; echo SESS_DB_PATH;' "$SITE/sess_path.php")
[ -n "$DB" ] && [ -e "$DB" ] || { echo "sess_consistent_copy: source DB missing (resolved='$DB')" >&2; exit 1; }
[ -f "$SITE/private/sess_writers_pause" ] || {
	echo "sess_consistent_copy: refuse — create private/sess_writers_pause first (writers must be frozen)" >&2
	exit 2
}
WU=${OEV_WEB_USER:-}
if [ -z "$WU" ]; then
	for u in http www-data nginx apache; do id "$u" >/dev/null 2>&1 && WU=$u && break; done
fi
[ -n "$WU" ] || { echo "sess_consistent_copy: set OEV_WEB_USER" >&2; exit 1; }
DDIR=$(dirname "$DEST")
sudo -n -u "$WU" test -d "$DDIR" || { echo "sess_consistent_copy: dest dir not usable by $WU: $DDIR" >&2; exit 1; }
# Escape single quotes for SQL string literal
sql_quote(){ printf "%s" "$1" | sed "s/'/''/g"; }
QDEST=$(sql_quote "$DEST")
ERR=$(mktemp)
if sudo -n -u "$WU" sqlite3 "$DB" "VACUUM INTO '$QDEST';" 2>"$ERR"; then
	echo "ok VACUUM INTO $DEST"
	rm -f "$ERR"; exit 0
fi
if sudo -n -u "$WU" sqlite3 "$DB" ".backup '$QDEST'" 2>>"$ERR"; then
	echo "ok .backup $DEST"
	rm -f "$ERR"; exit 0
fi
echo "sess_consistent_copy: failed" >&2
cat "$ERR" >&2 || true
rm -f "$ERR"
exit 1
