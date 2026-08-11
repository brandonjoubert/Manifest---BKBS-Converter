#!/usr/bin/env python3
"""Claim Ledger Stage 2 — verify exports built from resolve match Stage 0 goldens.

1. Load Stage 0 fixture into a temp DB
2. Backfill claims from entities
3. Resolve each approved entity
4. Run the same export builders with ResolvedEntity duck-types
5. Compare to golden with Stage 0 normalizer

Usage:
  python scripts/verify_exports_via_resolve.py
  python scripts/verify_exports_via_resolve.py --edition python
  python scripts/verify_exports_via_resolve.py --edition all

Exit 0 on match, 1 on mismatch.
"""

from __future__ import annotations

import argparse
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
    load_fixture,
    maps_equal,
    zip_to_normalized_map,
)


REQUIRED_CLAIM_ATTRS_MIN = frozenset({"name", "relationships", "evidence"})


def _require_claims(db, entity_id: str, entity) -> list[str]:
    """Return missing required claim attributes for verify gate."""
    from sqlalchemy import select

    from app.models import Claim
    from app.services.claim_codec import prop_attribute

    rows = list(
        db.scalars(
            select(Claim).where(
                Claim.entity_id == entity_id,
                Claim.status == "approved",
            )
        ).all()
    )
    present = {c.attribute for c in rows}
    missing: list[str] = []
    for attr in sorted(REQUIRED_CLAIM_ATTRS_MIN):
        if attr not in present:
            missing.append(attr)
    props = entity.properties or {}
    if isinstance(props, dict):
        for k in props:
            pa = prop_attribute(str(k))
            if pa not in present:
                missing.append(pa)
    return missing


def verify_python() -> bool:
    if not GOLDEN_ZIP.exists() and not GOLDEN_DIR.exists():
        print("ERROR: golden not found. Run: python scripts/capture_golden.py")
        return False

    from sqlalchemy import create_engine
    from sqlalchemy.orm import sessionmaker

    from app.models import Base, Entity, Site
    from app.services.export_graph import build_graph
    from app.services.export_jsonld import (
        build_agent_json,
        build_organization_jsonld,
        build_services_jsonld,
    )
    from app.services.export_llms import render_llms_full, render_llms_txt
    from app.services.export_package import _readme, _robots_suggestion, _sitemap_suggestion
    from app.services.resolver import resolve_entity
    from scripts.backfill_claims import backfill_site

    fixture = load_fixture()
    with tempfile.TemporaryDirectory() as td:
        td_path = Path(td)
        db_path = td_path / "via-resolve.db"
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
        entities_by_id: dict[str, Entity] = {}
        for e in fixture["entities"]:
            ent = Entity(
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
            db.add(ent)
            entities_by_id[e["id"]] = ent
        db.commit()

        totals = backfill_site(db, s["id"], include_pending=False, dry_run=False, update=False)
        print(
            f"Backfill: entities={totals['entities']} inserted={totals['inserted']} "
            f"skipped={totals['skipped']}"
        )
        if totals["entities"] < 1 or totals["inserted"] < 1:
            print("FAIL: backfill produced no claims for approved entities")
            db.close()
            return False

        approved = [
            e
            for e in entities_by_id.values()
            if e.status == "approved"
        ]
        approved.sort(key=lambda x: (x.entity_type, x.name))

        resolved = []
        claim_errors: list[str] = []
        for ent in approved:
            missing = _require_claims(db, ent.id, ent)
            if missing:
                claim_errors.append(f"{ent.id}: missing claims {missing}")
            r = resolve_entity(ent.id, db=db)
            if r is None:
                claim_errors.append(f"{ent.id}: resolve returned None")
            else:
                resolved.append(r)

        if claim_errors:
            print("FAIL: claim/resolve gate:")
            for err in claim_errors:
                print(f"  - {err}")
            db.close()
            return False

        out_dir = td_path / "export"
        out_dir.mkdir()
        (out_dir / "llms.txt").write_text(render_llms_txt(site, resolved), encoding="utf-8")
        (out_dir / "llms-full.txt").write_text(render_llms_full(site, resolved), encoding="utf-8")

        import json

        def _wj(path: Path, data) -> None:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

        _wj(out_dir / "graph.json", build_graph(site, resolved))
        _wj(out_dir / "schema" / "organization.jsonld", build_organization_jsonld(site, resolved))
        _wj(out_dir / "schema" / "services.jsonld", build_services_jsonld(site, resolved))
        _wj(out_dir / ".well-known" / "agent.json", build_agent_json(site, resolved))
        (out_dir / "robots.txt.suggestion").write_text(_robots_suggestion(site), encoding="utf-8")
        (out_dir / "sitemap.xml.suggestion").write_text(
            _sitemap_suggestion(site, resolved), encoding="utf-8"
        )
        (out_dir / "README.md").write_text(_readme(site), encoding="utf-8")
        db.close()

        current_map = dir_to_normalized_map(out_dir)

    if GOLDEN_DIR.exists():
        golden_map = dir_to_normalized_map(GOLDEN_DIR)
    else:
        golden_map = zip_to_normalized_map(GOLDEN_ZIP)

    ok, diffs = maps_equal(golden_map, current_map)
    if ok:
        print("Python via-resolve: OK — export matches golden (normalized)")
        return True
    print("Python via-resolve: FAIL — differences in:")
    for d in diffs:
        print(f"  - {d}")
        g = golden_map.get(d, "")
        c = current_map.get(d, "")
        print(f"    golden bytes: {len(g)} current bytes: {len(c)}")
    return False


def verify_php() -> bool:
    php = shutil.which("php")
    if not php:
        print("PHP via-resolve: SKIP — php binary not found on PATH")
        return True

    script = ROOT / "php" / "scripts" / "verify_exports_via_resolve.php"
    if not script.exists():
        print(f"PHP via-resolve: FAIL — missing {script}")
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
        print("PHP via-resolve: OK")
        return True
    print("PHP via-resolve: FAIL — exit", proc.returncode)
    return False


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--edition",
        choices=["python", "php", "all"],
        default="all",
    )
    args = parser.parse_args()

    print("=== Stage 2 verify_exports_via_resolve ===")
    print("Compared files:", ", ".join(COMPARE_FILES))
    ok = True
    if args.edition in ("python", "all"):
        ok = verify_python() and ok
    if args.edition in ("php", "all"):
        ok = verify_php() and ok
    if ok:
        print("RESULT: PASS")
        return 0
    print("RESULT: FAIL")
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
