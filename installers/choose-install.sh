#!/usr/bin/env bash
# Master installer chooser — pick Local / Python host / PHP host
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "=============================================="
echo "  Manifest BKBS Converter — choose install path"
echo "=============================================="
echo
echo "  1) Local PC (Python)     — develop / run on your computer"
echo "  2) Python-enabled host   — VPS or cPanel Python App"
echo "  3) Non-Python host       — shared hosting PHP edition"
echo "  4) Verify baselines only — Stage 0 export checks (Python + PHP)"
echo "  5) Show docs only"
echo
read -r -p "Enter 1-5: " choice

case "$choice" in
  1)
    bash "$ROOT/installers/local/install.sh"
    ;;
  2)
    bash "$ROOT/installers/python-host/install.sh"
    ;;
  3)
    ZIP="$ROOT/installers/php-host/bkbs-php-edition.zip"
    if [[ ! -f "$ZIP" ]]; then
      echo "Zip missing — building now..."
      bash "$ROOT/installers/php-host/package.sh"
    else
      echo "Refreshing PHP package from current source..."
      bash "$ROOT/installers/php-host/package.sh"
    fi
    echo
    echo "Non-Python host package is ready:"
    echo "  $ZIP"
    echo
    echo "Next:"
    echo "  1. Upload installers/php-host/bkbs-php-edition.zip to your host"
    echo "  2. Extract into public_html/bkbs/"
    echo "  3. Open https://YOURDOMAIN/bkbs/install.php"
    echo "  4. Scan → Edit pending entities → Approve → Publish live"
    echo
    echo "Details: installers/php-host/README.md · INSTALL.md Path C"
    ;;
  4)
    # shellcheck disable=SC1091
    if [[ -f "$ROOT/.venv/bin/activate" ]]; then
      source "$ROOT/.venv/bin/activate"
    fi
    echo "=== pytest ==="
    pytest -q
    echo
    echo "=== Stage 0 verify (all editions) ==="
    python "$ROOT/scripts/verify_exports.py" --edition all
    echo
    echo "=== Stage 1 claim ledger contract (all editions) ==="
    python "$ROOT/scripts/stage1_contract_check.py"
    echo
    echo "=== Stage 2 export-via-resolve (all editions) ==="
    python "$ROOT/scripts/verify_exports_via_resolve.py" --edition all
    ;;
  5)
    echo
    echo "See INSTALL.md and USER_MANUAL.md in the project root."
    echo "Roadmap: ROADMAP.md · Claim ledger plan: CLAIM_LEDGER_IMPLEMENTATION_PLAN.md"
    ;;
  *)
    echo "Invalid choice."
    exit 1
    ;;
esac
