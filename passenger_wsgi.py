"""
Passenger / cPanel Python App entrypoint for shared hosting.

Point the application's startup file to this file, and set the application
root to the bkbs-converter directory.
"""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

# Ensure data lives under the app directory on shared hosts
os.environ.setdefault("BKBS_DATA_DIR", str(ROOT / "data"))

from app.main import app as application  # noqa: E402  # Passenger expects `application`
