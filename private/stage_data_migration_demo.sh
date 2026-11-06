#!/bin/sh
# Build / tear down an isolated staging tree for the sessions.db → data/ move.
# Default location: <site>/backups/data-migration-stage/  (inside app tree only).
# Does NOT touch live sessions.db, DSM, or writers.
#
#   private/stage_data_migration_demo.sh build
#   private/stage_data_migration_demo.sh test    # PHP fixture tests + optional local HTTP
#   private/stage_data_migration_demo.sh clean
set -e
SITE=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
STAGE=${OEV_STAGE_DIR:-$SITE/backups/data-migration-stage}
PHP=${OEV_PHP:-php}
for p in php8.4 php84 php8.3 php83 php8.2 php82 php; do
	command -v "$p" >/dev/null 2>&1 && PHP=$(command -v "$p") && break
done

cmd=${1:-}
case "$cmd" in
build)
	rm -rf "$STAGE"
	mkdir -p "$STAGE/fake_site/private" "$STAGE/fake_site/data" "$STAGE/fake_site/collector" \
		"$STAGE/fake_site/emoncms" "$STAGE/fake_site/backups" "$STAGE/httpd_docroot"
	# Minimal site copies for path + receiver pause + htaccess demos
	cp "$SITE/sess_path.php" "$STAGE/fake_site/"
	cp "$SITE/data/.htaccess" "$STAGE/fake_site/data/"
	cp "$SITE/emoncms/receiver.php" "$STAGE/fake_site/emoncms/"
	# Deny web listing of the stage tree itself
	cp "$SITE/backups/.htaccess" "$STAGE/.htaccess" 2>/dev/null || cat > "$STAGE/.htaccess" <<'HT'
<IfModule mod_authz_core.c>
	Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
	Order allow,deny
	Deny from all
</IfModule>
HT
	# Fixture DB at legacy path (empty schema-ish)
	"$PHP" -r '
$p=$argv[1];
$db=new PDO("sqlite:$p");
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("CREATE TABLE meta(k TEXT PRIMARY KEY, v TEXT)");
$db->exec("CREATE TABLE samples(id INTEGER PRIMARY KEY, unit_id INT, ts INT, amp REAL)");
$db->exec("INSERT INTO meta VALUES(\"n\", \"1\")");
$db->exec("INSERT INTO samples(unit_id,ts,amp) VALUES(1,strftime(\"%s\",\"now\"),10)");
echo "fixture $p\n";
' "$STAGE/fake_site/sessions.db"
	# Unique public demo path materials (NOT wired into live vhost by this script)
	mkdir -p "$STAGE/httpd_docroot/oev-stage-sessdb"
	cp "$STAGE/fake_site/data/.htaccess" "$STAGE/httpd_docroot/oev-stage-sessdb/.htaccess"
	cp "$STAGE/fake_site/sessions.db" "$STAGE/httpd_docroot/oev-stage-sessdb/sessions.db"
	# Touch wal/shm placeholders for deny tests (empty files; not live)
	: > "$STAGE/httpd_docroot/oev-stage-sessdb/sessions.db-wal"
	: > "$STAGE/httpd_docroot/oev-stage-sessdb/sessions.db-shm"
	printf '%s\n' "stage built at $STAGE" "fake_site ready; no live DB opened" \
		"HTTP demo files under httpd_docroot/oev-stage-sessdb (serve only if you point a throwaway Alias; backups/ deny-all covers the stage tree)"
	;;
test)
	[ -d "$STAGE/fake_site" ] || { echo "run build first" >&2; exit 1; }
	"$PHP" "$SITE/tests/sess_path_test.php"
	"$PHP" "$SITE/tests/sess_writers_pause_test.php"
	# Local PHP built-in server for htaccess is useless (ignores .htaccess).
	# Document: Apache-only. Still verify fixture file layout.
	[ -f "$STAGE/httpd_docroot/oev-stage-sessdb/.htaccess" ]
	[ -f "$STAGE/fake_site/data/.htaccess" ]
	echo "stage test layout ok (Apache .htaccess runtime needs WebStation; content checked in PHP tests)"
	;;
clean)
	rm -rf "$STAGE"
	echo "stage removed $STAGE"
	;;
*)
	echo "usage: $0 build|test|clean" >&2; exit 1
	;;
esac
