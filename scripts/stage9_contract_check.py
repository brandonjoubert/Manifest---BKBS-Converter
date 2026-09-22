#!/usr/bin/env python3
"""Claim Ledger Stage 9 contract: capability layer, all three editions.

Exit 0 on PASS, 1 on FAIL.
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def fail(msg: str) -> None:
    print(f"FAIL: {msg}", file=sys.stderr)
    raise SystemExit(1)


def run(cmd: list[str]) -> None:
    print("+", " ".join(cmd))
    proc = subprocess.run(cmd, cwd=ROOT)
    if proc.returncode != 0:
        fail(f"exit {proc.returncode}: {' '.join(cmd)}")


def main() -> None:
    php = shutil.which("php")
    if not php:
        fail("php is required for the Stage 9 contract")
    run([sys.executable, "-m", "pytest", "tests/test_stage9.py", "-q"])
    run([php, "php/scripts/verify_stage9.php"])
    run([php, "php/scripts/verify_stage9_wp.php"])
    print("Stage 9 contract: PASS")


if __name__ == "__main__":
    main()
