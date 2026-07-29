#!/usr/bin/env python3
"""
Claim Ledger Stage 0 — verify current exports match golden fixture.

Usage:
  python scripts/verify_exports.py
  python scripts/verify_exports.py --edition python
  python scripts/verify_exports.py --edition php
  python scripts/verify_exports.py --edition all

Exit 0 on match, 1 on mismatch.
"""

from __future__ import annotations

import argparse
import json
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from scripts.stage0_common import (  # noqa: E402
    COMPARE_FILES,
    GOLDEN_DIR,
    GOLDEN_ZIP,
    dir_to_normalized_map,
    expected_entity_counts,
    load_fixture,
    maps_equal,
    zip_to_normalized_map,
)


def verify_python() -> bool:
    if not GOLDEN_ZIP.exists() and not GOLDEN_DIR.exists():
        print("ERROR: golden not found. Run: python scripts/capture_golden.py")
        return False

    from sqlalchemy import create_engine
    from sqlalchemy.orm import sessionmaker

    from app.models import Base, Entity, Site
    from app.services.export_package import create_export_package

    fixture = load_fixture()
    with tempfile.TemporaryDirectory() as td:
        td_path = Path(td)
        db_path = td_path / "verify.db"
        engine = create_engine(f"sqlite:///{db_path}", future=True)
        Base.metadata.create_all(bind=engine)
        Session = sessionmaker(bind=engine, future=True)
        db = Session()
        s = fixture["site"]
        site = Site(
            id=s["id"],
            name=s["name"],
            base_url=s["base_url"],
            max_pages=s.get("max_pages", 10),
            crawl_delay_ms=s.get("crawl_delay_ms", 0),
            auto_publish=False,
        )
        db.add(site)
        for e in fixture["entities"]:
            db.add(
                Entity(
                    id=e["id"],
                    site_id=s["id"],
                    external_key=e["external_key"],
                    entity_type=e["entity_type"],
                    name=e["name"],
                    description=e.get("description"),
                    properties=e.get("properties") or {},
                    relationships=e.get("relationships") or [],
                    evidence=e.get("evidence") or [],
                    version=e.get("version", 1),
                    trust_level=e.get("trust_level", "medium"),
                    source=e.get("source", "fixture"),
                    status=e["status"],
                )
            )
        db.commit()

        # Redirect exports into temp by patching settings
        import app.config as cfg_mod

        orig = cfg_mod.settings.model_copy()
        # pydantic settings — override exports via env not easy; monkeypatch property
        export_root = td_path / "exports"
        export_root.mkdir()

        class _S:
            @property
            def exports_dir(self):
                return export_root

        # create_export_package uses settings.exports_dir from app.config.settings
        from app import config as config_module
        from app.services import export_package as ep_mod

        real_settings = config_module.settings
        # Use object that proxies settings but overrides exports_dir
        class Proxy:
            def __getattr__(self, name):
                if name == "exports_dir":
                    return export_root
                return getattr(real_settings, name)

        ep_mod.settings = Proxy()  # type: ignore
        try:
            export = create_export_package(db, site, include_pending=False)
            current_dir = Path(export.path)
            current_map = dir_to_normalized_map(current_dir)
        finally:
            ep_mod.settings = real_settings
            db.close()

    if GOLDEN_DIR.exists():
        golden_map = dir_to_normalized_map(GOLDEN_DIR)
    else:
        golden_map = zip_to_normalized_map(GOLDEN_ZIP)

    ok, diffs = maps_equal(golden_map, current_map)
    if ok:
        print("Python: OK — export matches golden (normalized)")
        return True
    print("Python: FAIL — differences in:")
    for d in diffs:
        print(f"  - {d}")
        g = golden_map.get(d, "")
        c = current_map.get(d, "")
        print(f"    golden bytes: {len(g)} current bytes: {len(c)}")
    return False


def verify_php() -> bool:
    """Run PHP capture-to-temp and compare against golden."""
    php = shutil.which("php")
    if not php:
        print("PHP: SKIP — php binary not found on PATH")
        return True  # do not fail whole suite if PHP absent on dev box

    script = ROOT / "php" / "scripts" / "verify_exports.php"
    if not script.exists():
        print(f"PHP: FAIL — missing {script}")
        return False
    proc = subprocess.run(
        [php, str(script)],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
    )
    print(proc.stdout)
    if proc.stderr:
        print(proc.stderr, file=sys.stderr)
    if proc.returncode == 0:
        print("PHP: OK — publisher output matches golden (normalized)")
        return True
    print("PHP: FAIL — verify_exports.php exit", proc.returncode)
    return False


def verify_counts() -> bool:
    counts = expected_entity_counts()
    print("Fixture entity counts:", json.dumps(counts["by_status"]))
    # pending must not be in production export; approved >= 1
    if counts["approved"] < 1:
        print("FAIL: fixture must have at least one approved entity")
        return False
    if counts["pending"] < 1:
        print("WARN: fixture has no pending entity (recommended for export filter test)")
    print("Counts: OK")
    return True


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--edition",
        choices=["python", "php", "all"],
        default="all",
        help="Which edition(s) to verify (all = python + php when available)",
    )
    args = parser.parse_args()

    print("=== Stage 0 verify_exports ===")
    print("Compared files:", ", ".join(COMPARE_FILES))
    ok = True
    ok = verify_counts() and ok

    if args.edition in ("python", "all"):
        ok = verify_python() and ok
    if args.edition in ("php", "all"):
        ok = verify_php() and ok

    # Document third "version": install paths share these two editions
    if args.edition == "all":
        print(
            "Install paths covered by this baseline: "
            "local (Python), python-host (Python), php-host (PHP)."
        )

    if ok:
        print("RESULT: PASS")
        return 0
    print("RESULT: FAIL")
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
