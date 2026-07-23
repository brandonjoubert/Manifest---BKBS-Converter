#!/usr/bin/env bash
# Master installer chooser — pick Local / Python host / PHP host
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "=============================================="
echo "  BKBS Converter — choose install path"
echo "=============================================="
echo
echo "  1) Local PC (Python)     — develop / run on your computer"
echo "  2) Python-enabled host   — VPS or cPanel Python App"
echo "  3) Non-Python host       — shared hosting PHP edition"
echo "  4) Show docs only"
echo
read -r -p "Enter 1, 2, 3, or 4: " choice

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
    fi
    echo
    echo "Non-Python host package is ready:"
    echo "  $ZIP"
    echo
    echo "Next:"
    echo "  1. Upload installers/php-host/bkbs-php-edition.zip to your host"
    echo "  2. Extract into public_html/bkbs/"
    echo "  3. Open https://YOURDOMAIN/bkbs/install.php"
    echo
    echo "Details: installers/php-host/README.md"
    ;;
  4)
    echo
    echo "See INSTALL.md in the project root."
    ;;
  *)
    echo "Invalid choice."
    exit 1
    ;;
esac
