#!/usr/bin/env bash
# Run OpenEVSE MU Manager shared fixture previews + layout measure + contact sheet.
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$DIR/../.." && pwd)"
OUT="${OUT:-$DIR/out}"
SRC="${SRC:-$ROOT}"
WIDTHS="${WIDTHS:-360,390,412,1280}"
DEST="${DEST:-/workspace/preview-fixtures-412.png}"
CHROME="${CHROME:-/usr/bin/google-chrome-stable}"

# Prefer local puppeteer-core if present; else use /tmp/oev-layout-tools
export NODE_PATH="${NODE_PATH:-}:/tmp/oev-layout-tools/node_modules:$DIR/node_modules"

echo "== render from SRC=$SRC =="
node "$DIR/render.mjs" --src "$SRC" --out "$OUT"

echo "== measure widths=$WIDTHS =="
node "$DIR/measure.mjs" --out "$OUT" --widths "$WIDTHS"

echo "== contact sheet → $DEST =="
CHROME="$CHROME" node "$DIR/sheet.mjs" --out "$OUT" --dest "$DEST"

echo "Done. Artifacts in $OUT and $DEST"
