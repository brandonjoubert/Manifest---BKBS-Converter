#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
if [[ ! -d .venv ]]; then
  python3 -m venv .venv
  # shellcheck disable=SC1091
  source .venv/bin/activate
  pip install -r requirements.txt
else
  # shellcheck disable=SC1091
  source .venv/bin/activate
fi
if [[ ! -f .env ]]; then
  cp .env.example .env
  echo "Created .env — configure LLM in Settings UI or set keys in .env."
fi
mkdir -p data/exports data/live data/live-public
echo "Manifest BKBS Converter → http://${BKBS_HOST:-127.0.0.1}:${BKBS_PORT:-8765}"
exec uvicorn app.main:app --host "${BKBS_HOST:-127.0.0.1}" --port "${BKBS_PORT:-8765}" --reload
