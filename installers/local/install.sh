#!/usr/bin/env bash
# BKBS Converter — Local PC installer (Python edition)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

echo "=== BKBS Converter · Local PC install (Python) ==="
echo "Install path: $ROOT"
echo

if ! command -v python3 >/dev/null 2>&1; then
  echo "ERROR: python3 not found. Install Python 3.10+ and re-run."
  exit 1
fi

python3 -m venv .venv
# shellcheck disable=SC1091
source .venv/bin/activate
python -m pip install --upgrade pip
pip install -r requirements.txt

if [[ ! -f .env ]]; then
  cp .env.example .env
  echo "Created .env — optional: set API keys, or use Settings in the UI."
fi

mkdir -p data/exports data/live

echo
echo "Install complete."
echo "Start with:"
echo "  cd \"$ROOT\""
echo "  source .venv/bin/activate"
echo "  ./run.sh"
echo "  # or: uvicorn app.main:app --host 127.0.0.1 --port 8765"
echo
echo "Then open http://127.0.0.1:8765"
echo "For local demo publish root, use e.g. $ROOT/data/live-public"
