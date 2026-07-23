#!/usr/bin/env bash
# Manifest BKBS Converter — installer for Python-enabled hosting (VPS / cPanel Python App)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

echo "=== Manifest BKBS Converter · Python host install ==="
echo "App root: $ROOT"
echo

if ! command -v python3 >/dev/null 2>&1; then
  echo "ERROR: python3 not found on this host."
  echo "Use the PHP edition instead: installers/php-host/"
  exit 1
fi

python3 -m venv .venv
# shellcheck disable=SC1091
source .venv/bin/activate
pip install --upgrade pip
pip install -r requirements.txt

if [[ ! -f .env ]]; then
  cp .env.example .env
fi

# Guess public_html
DEFAULT_PUB=""
if [[ -d "$HOME/public_html" ]]; then
  DEFAULT_PUB="$HOME/public_html"
elif [[ -d "$(dirname "$ROOT")/public_html" ]]; then
  DEFAULT_PUB="$(cd "$(dirname "$ROOT")/public_html" && pwd)"
fi

if [[ -n "$DEFAULT_PUB" ]] && ! grep -q '^DEFAULT_PUBLISH_ROOT=.\+' .env 2>/dev/null; then
  # append if empty
  if grep -q '^DEFAULT_PUBLISH_ROOT=$' .env 2>/dev/null || ! grep -q DEFAULT_PUBLISH_ROOT .env 2>/dev/null; then
    sed -i.bak "s|^DEFAULT_PUBLISH_ROOT=.*|DEFAULT_PUBLISH_ROOT=$DEFAULT_PUB|" .env 2>/dev/null \
      || echo "DEFAULT_PUBLISH_ROOT=$DEFAULT_PUB" >> .env
    echo "Set DEFAULT_PUBLISH_ROOT=$DEFAULT_PUB"
  fi
fi

mkdir -p data/exports data/live
chmod 750 data 2>/dev/null || true

echo
echo "=== Next steps ==="
echo
echo "A) VPS / SSH — run the app:"
echo "   source .venv/bin/activate"
echo "   uvicorn app.main:app --host 127.0.0.1 --port 8765"
echo "   (put Nginx reverse-proxy in front; keep running via systemd)"
echo
echo "B) cPanel Setup Python App:"
echo "   Application root:  $(basename "$ROOT")  (or full path)"
echo "   Startup file:      passenger_wsgi.py"
echo "   Entry point:       application"
echo "   Then Restart the Python App in cPanel."
echo
echo "C) In the UI, set each site's Web root to your public_html path,"
echo "   approve entities, click Publish live."
echo
echo "Docs: deploy/SHARED_HOSTING.md"
