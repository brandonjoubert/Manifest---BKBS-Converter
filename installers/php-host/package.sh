#!/usr/bin/env bash
# Build a zip of the PHP edition for upload to shared hosting.
# Primary output (shipped with the repo / installer directory):
#   installers/php-host/bkbs-php-edition.zip
# Also copies to:
#   dist/bkbs-php-edition.zip
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
INSTALLER_DIR="$(cd "$(dirname "$0")" && pwd)"
DIST_DIR="$ROOT/dist"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/bkbs-php" "$DIST_DIR"
cp -a "$ROOT/php/." "$STAGE/bkbs-php/"
# do not ship local config/db
rm -f "$STAGE/bkbs-php/config.php"
rm -f "$STAGE/bkbs-php/data/"*.sqlite* 2>/dev/null || true
# ensure data dir exists
mkdir -p "$STAGE/bkbs-php/data"
touch "$STAGE/bkbs-php/data/.gitkeep"
# do not nest an old zip inside the package
rm -f "$STAGE/bkbs-php/"*.zip 2>/dev/null || true

ZIP_NAME="bkbs-php-edition.zip"
ZIP_INSTALLER="$INSTALLER_DIR/$ZIP_NAME"
ZIP_DIST="$DIST_DIR/$ZIP_NAME"

rm -f "$ZIP_INSTALLER" "$ZIP_DIST"
(cd "$STAGE" && zip -qr "$ZIP_INSTALLER" bkbs-php)
cp -f "$ZIP_INSTALLER" "$ZIP_DIST"

echo "Created (installer — use this):"
echo "  $ZIP_INSTALLER"
echo "Also copied to:"
echo "  $ZIP_DIST"
echo
echo "Upload and extract into public_html/bkbs/ then open install.php"
