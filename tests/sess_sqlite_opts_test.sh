#!/bin/bash
# tests/sess_sqlite_opts_test.sh — option parsing for private/sess_sqlite.sh
# Synthetic fixture only; never opens production sessions.db.
set -e
f=0
ck() { if [ "$1" = 1 ]; then echo "PASS $2"; else echo "FAIL $2"; f=$((f+1)); fi; }

ROOT=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
TMP=$(mktemp -d "$ROOT/tests/tmp/sess_sqlite_opts.XXXXXX")
cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT

mkdir -p "$TMP/bin" "$TMP/site/private" "$TMP/site/data" "$TMP/cwd"
# Fixture site: resolver always returns our temp DB (no live path).
cat > "$TMP/site/sess_path.php" <<'PHP'
<?php
define('SESS_DB_PATH', getenv('OEV_TEST_DB') ?: (__DIR__ . '/data/sessions.db'));
function oev_sess_writers_paused(){ return false; }
PHP
# Real tiny sqlite DB
DB="$TMP/site/data/sessions.db"
export OEV_TEST_DB="$DB"
sqlite3 "$DB" 'CREATE TABLE t(x INTEGER); INSERT INTO t VALUES (42);'

# Copy helper under fake site so SITE= resolves to fixture
cp "$ROOT/private/sess_sqlite.sh" "$TMP/site/private/sess_sqlite.sh"
chmod 755 "$TMP/site/private/sess_sqlite.sh"

# Fake sudo: drop -n/-u USER, run the rest as current user (no privilege change).
cat > "$TMP/bin/sudo" <<'SUDO'
#!/bin/bash
while [ $# -gt 0 ]; do
	case "$1" in
		-n) shift ;;
		-u) shift 2 ;;
		*) exec "$@" ;;
	esac
done
echo "fake sudo: no command" >&2
exit 1
SUDO
chmod 755 "$TMP/bin/sudo"

# Spy sqlite3: log argv (NUL-separated → printable), then call real sqlite3.
REAL_SQLITE=$(command -v sqlite3)
cat > "$TMP/bin/sqlite3" <<SPY
#!/bin/bash
printf '%s\0' "\$@" > "$TMP/sqlite.argv"
exec "$REAL_SQLITE" "\$@"
SPY
chmod 755 "$TMP/bin/sqlite3"

export PATH="$TMP/bin:$PATH"
export OEV_WEB_USER=$(id -un)
export OEV_PHP=$(command -v php || command -v php8.4 || command -v php84)

cd "$TMP/cwd"

# 1) Documented invocation: -bail -cmd '.timeout 2000' 'SELECT 1;'
out=$("$TMP/site/private/sess_sqlite.sh" -bail -cmd '.timeout 2000' 'SELECT 1;' 2>&1) || rc=$?
rc=${rc:-0}
ck $([ "$rc" = 0 ] && [ "$out" = "1" ] && echo 1 || echo 0) "documented -bail -cmd SELECT 1 → 1 (rc=$rc out=$(printf %q "$out"))"

# argv: sqlite3 -bail -cmd .timeout 2000 <db> SELECT 1;
mapfile -d '' -t argv < "$TMP/sqlite.argv"
# Expect: -bail, -cmd, .timeout 2000, $DB, SELECT 1;
ck $([ "${argv[0]}" = "-bail" ] && echo 1 || echo 0) "argv[0]=-bail"
ck $([ "${argv[1]}" = "-cmd" ] && echo 1 || echo 0) "argv[1]=-cmd"
ck $([ "${argv[2]}" = ".timeout 2000" ] && echo 1 || echo 0) "argv[2] is single '.timeout 2000' arg"
ck $([ "${argv[3]}" = "$DB" ] && echo 1 || echo 0) "argv[3] is fixture DB path"
ck $([ "${argv[4]}" = "SELECT 1;" ] && echo 1 || echo 0) "argv[4] is SQL"

# No stray DB named after the -cmd payload
ck $([ ! -e "$TMP/cwd/.timeout 2000" ] && [ ! -e "$TMP/site/.timeout 2000" ] && [ ! -e "$ROOT/.timeout 2000" ] && echo 1 || echo 0) "no stray '.timeout 2000' database file"

# 2) Plain SQL
out=$("$TMP/site/private/sess_sqlite.sh" 'SELECT x FROM t;' 2>&1) || rc=$?
rc=0
out=$("$TMP/site/private/sess_sqlite.sh" 'SELECT x FROM t;')
ck $([ "$out" = "42" ] && echo 1 || echo 0) "plain SQL SELECT x → 42"

# 3) -readonly form
out=$("$TMP/site/private/sess_sqlite.sh" -readonly 'SELECT x FROM t;')
ck $([ "$out" = "42" ] && echo 1 || echo 0) "-readonly SELECT x → 42"
mapfile -d '' -t argv < "$TMP/sqlite.argv"
ck $([ "${argv[0]}" = "-readonly" ] && [ "${argv[1]}" = "$DB" ] && [ "${argv[2]}" = "SELECT x FROM t;" ] && echo 1 || echo 0) "-readonly argv order"

# 4) Missing -cmd arg should fail cleanly
if "$TMP/site/private/sess_sqlite.sh" -cmd 2>/dev/null; then
	ck 0 "missing -cmd arg exits non-zero"
else
	ck 1 "missing -cmd arg exits non-zero"
fi

# 5) -init with a file (one-arg option)
echo 'SELECT 7;' > "$TMP/init.sql"
out=$("$TMP/site/private/sess_sqlite.sh" -bail -init "$TMP/init.sql" 'SELECT 8;')
# -init runs first (prints 7), then SQL (prints 8)
ck $([ "$out" = $'7\n8' ] || [ "$out" = "7
8" ] && echo 1 || echo 0) "-init then SQL (out=$(printf %q "$out"))"
mapfile -d '' -t argv < "$TMP/sqlite.argv"
ck $([ "${argv[0]}" = "-bail" ] && [ "${argv[1]}" = "-init" ] && [ "${argv[2]}" = "$TMP/init.sql" ] && [ "${argv[3]}" = "$DB" ] && echo 1 || echo 0) "-init argv preserves filename"

echo "failures=$f"
exit $f
