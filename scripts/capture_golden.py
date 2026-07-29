#!/usr/bin/env python3
"""
Claim Ledger Stage 0 — capture golden exports from deterministic fixture.

Editions:
  - python  → test-fixtures/golden-v0/ + golden-v0.zip  (full export package)
  - php     → test-fixtures/golden-v0-php/ via php/scripts/capture_golden.php
  - all     → both (covers local + python-host + php-host install paths)

Usage:
  python scripts/capture_golden.py
  python scripts/capture_golden.py --edition all
"""

from __future__ import annotations

import argparse
import json
import shutil
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from scripts.stage0_common import (  # noqa: E402
    ENTITY_COUNTS_JSON,
    FIXTURES,
    GOLDEN_DIR,
    GOLDEN_ZIP,
    STAGE0_REPORT,
    expected_entity_counts,
    load_fixture,
    sha256_file,
)

GOLDEN_PHP_DIR = FIXTURES / "golden-v0-php"


def _export_settings_proxy(export_root: Path):
    from app import config as config_module
    from app.services import export_package as ep_mod

    real = config_module.settings

    class Proxy:
        def __getattr__(self, name):
            if name == "exports_dir":
                return export_root
            return getattr(real, name)

    prev = ep_mod.settings
    ep_mod.settings = Proxy()  # type: ignore
    return ep_mod, prev, real


def seed_and_export_python(tmp: Path) -> Path:
    from sqlalchemy import create_engine
    from sqlalchemy.orm import sessionmaker

    from app.models import Base, Entity, Site
    from app.services.export_package import create_export_package

    db_path = tmp / "stage0.db"
    if db_path.exists():
        db_path.unlink()
    engine = create_engine(f"sqlite:///{db_path}", future=True)
    Base.metadata.create_all(bind=engine)
    Session = sessionmaker(bind=engine, future=True)
    db = Session()

    fixture = load_fixture()
    s = fixture["site"]
    site = Site(
        id=s["id"],
        name=s["name"],
        base_url=s["base_url"],
        max_pages=s.get("max_pages", 10),
        crawl_delay_ms=s.get("crawl_delay_ms", 0),
        publish_root=s.get("publish_root"),
        auto_publish=bool(s.get("auto_publish", False)),
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
                notes=e.get("notes"),
            )
        )
    db.commit()

    export_root = tmp / "exports"
    export_root.mkdir(parents=True, exist_ok=True)
    ep_mod, prev, _ = _export_settings_proxy(export_root)
    try:
        export = create_export_package(db, site, include_pending=False)
        out_path = Path(export.path)
    finally:
        ep_mod.settings = prev
        db.close()
    return out_path


def capture_python() -> dict:
    tmp = FIXTURES / ".tmp-python"
    if tmp.exists():
        shutil.rmtree(tmp)
    tmp.mkdir(parents=True)
    try:
        export_path = seed_and_export_python(tmp)
        if GOLDEN_DIR.exists():
            shutil.rmtree(GOLDEN_DIR)
        shutil.copytree(export_path, GOLDEN_DIR)
        # Zip
        base = FIXTURES / "golden-v0"
        zip_path = Path(str(base) + ".zip")
        if zip_path.exists():
            zip_path.unlink()
        shutil.make_archive(str(base), "zip", root_dir=GOLDEN_DIR)
        assert GOLDEN_ZIP.exists() or zip_path.exists()
        z = GOLDEN_ZIP if GOLDEN_ZIP.exists() else zip_path
        return {
            "golden_dir": str(GOLDEN_DIR.relative_to(ROOT)),
            "golden_zip": str(z.relative_to(ROOT)),
            "zip_sha256": sha256_file(z),
        }
    finally:
        if tmp.exists():
            shutil.rmtree(tmp, ignore_errors=True)


def capture_php() -> dict:
    php = shutil.which("php")
    if not php:
        return {"skipped": True, "reason": "php not on PATH"}
    script = ROOT / "php" / "scripts" / "capture_golden.php"
    proc = subprocess.run([php, str(script)], cwd=str(ROOT), capture_output=True, text=True)
    print(proc.stdout)
    if proc.returncode != 0:
        print(proc.stderr, file=sys.stderr)
        raise RuntimeError("PHP capture_golden failed")
    return {
        "golden_dir": str(GOLDEN_PHP_DIR.relative_to(ROOT)),
        "skipped": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Capture Stage 0 golden export")
    parser.add_argument(
        "--edition",
        choices=["python", "php", "all"],
        default="all",
        help="python | php | all (default all — covers all install paths)",
    )
    args = parser.parse_args()

    FIXTURES.mkdir(parents=True, exist_ok=True)
    fixture = load_fixture()
    counts = expected_entity_counts(fixture)
    ENTITY_COUNTS_JSON.write_text(json.dumps(counts, indent=2) + "\n", encoding="utf-8")
    print("Entity counts (fixture):", json.dumps(counts))

    report: dict = {
        "stage": 0,
        "captured_at": datetime.now(timezone.utc).isoformat(),
        "fixture": str(FIXTURE_JSON_rel()),
        "entity_counts": counts,
        "install_paths_covered": [
            "local (Python)",
            "python-host (Python)",
            "php-host (PHP)",
        ],
        "editions": {},
    }

    if args.edition in ("python", "all"):
        print("--- Python edition ---")
        report["editions"]["python"] = capture_python()
        print("Python golden:", report["editions"]["python"])

    if args.edition in ("php", "all"):
        print("--- PHP edition ---")
        report["editions"]["php"] = capture_php()
        print("PHP golden:", report["editions"]["php"])

    STAGE0_REPORT.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(f"Baseline report: {STAGE0_REPORT}")
    print("Stage 0 capture complete.")
    return 0


def FIXTURE_JSON_rel() -> str:
    from scripts.stage0_common import FIXTURE_JSON

    return str(FIXTURE_JSON.relative_to(ROOT))


if __name__ == "__main__":
    raise SystemExit(main())
