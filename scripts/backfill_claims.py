#!/usr/bin/env python3
"""Claim Ledger Stage 2 — backfill approved claims from entity rows (Python).

Usage:
  python scripts/backfill_claims.py --all-sites
  python scripts/backfill_claims.py --site-id <uuid>
  python scripts/backfill_claims.py --all-sites --dry-run
  python scripts/backfill_claims.py --all-sites --include-pending
  python scripts/backfill_claims.py --all-sites --update   # supersede on value change

Idempotency (default): skip when an approved claim already exists with the same
value for (entity_id, attribute). With --update, value changes insert a new
approved claim and mark the previous approved as status=superseded.
"""

from __future__ import annotations

import argparse
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from sqlalchemy import select  # noqa: E402
from sqlalchemy.orm import Session  # noqa: E402

from app.db import SessionLocal, init_db  # noqa: E402
from app.models import Claim, Entity  # noqa: E402
from app.services.claim_codec import entity_attribute_pairs  # noqa: E402


def _utcnow() -> datetime:
    return datetime.now(timezone.utc)


def _latest_approved(db: Session, entity_id: str, attribute: str) -> Claim | None:
    rows = list(
        db.scalars(
            select(Claim)
            .where(
                Claim.entity_id == entity_id,
                Claim.attribute == attribute,
                Claim.status == "approved",
            )
            .order_by(Claim.id.desc())
        ).all()
    )
    return rows[0] if rows else None


def backfill_entity(
    db: Session,
    entity: Entity,
    *,
    dry_run: bool = False,
    update: bool = False,
) -> dict[str, int]:
    """Backfill claims for one entity. Returns counters."""
    stats = {"inserted": 0, "skipped": 0, "superseded": 0}
    pairs = entity_attribute_pairs(entity)
    approved_at = entity.last_updated or entity.created_at or _utcnow()
    extraction = (entity.source or "scan")[:32]
    entity_type = entity.entity_type or "unknown"

    for attr, value in pairs:
        existing = _latest_approved(db, entity.id, attr)
        if existing is not None:
            if existing.value == value:
                stats["skipped"] += 1
                continue
            if not update:
                stats["skipped"] += 1
                continue
            # Supersede previous approved
            if not dry_run:
                existing.status = "superseded"
                claim = Claim(
                    entity_id=entity.id,
                    entity_type=entity_type,
                    attribute=attr,
                    value=value,
                    source_url=None,
                    extraction_method=extraction,
                    confidence=None,
                    status="approved",
                    supersedes_id=existing.id,
                    created_at=approved_at,
                    approved_by="backfill",
                    approved_at=approved_at,
                )
                db.add(claim)
            stats["superseded"] += 1
            stats["inserted"] += 1
            continue

        if not dry_run:
            claim = Claim(
                entity_id=entity.id,
                entity_type=entity_type,
                attribute=attr,
                value=value,
                source_url=None,
                extraction_method=extraction,
                confidence=None,
                status="approved",
                supersedes_id=None,
                created_at=approved_at,
                approved_by="backfill",
                approved_at=approved_at,
            )
            db.add(claim)
        stats["inserted"] += 1

    return stats


def backfill_site(
    db: Session,
    site_id: str | None,
    *,
    include_pending: bool = False,
    dry_run: bool = False,
    update: bool = False,
) -> dict[str, int]:
    q = select(Entity)
    if site_id:
        q = q.where(Entity.site_id == site_id)
    if include_pending:
        q = q.where(Entity.status.in_(["approved", "pending", "needs_edit"]))
    else:
        q = q.where(Entity.status == "approved")

    entities = list(db.scalars(q.order_by(Entity.entity_type, Entity.name)).all())
    totals = {
        "entities": 0,
        "inserted": 0,
        "skipped": 0,
        "superseded": 0,
    }
    for ent in entities:
        totals["entities"] += 1
        s = backfill_entity(db, ent, dry_run=dry_run, update=update)
        for k in ("inserted", "skipped", "superseded"):
            totals[k] += s[k]
    if not dry_run:
        db.commit()
    return totals


def main() -> int:
    parser = argparse.ArgumentParser(description="Backfill claims from entity rows (Stage 2)")
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--site-id", help="Backfill one site")
    group.add_argument("--all-sites", action="store_true", help="Backfill all sites")
    parser.add_argument(
        "--include-pending",
        action="store_true",
        help="Also backfill pending/needs_edit entities (default: approved only)",
    )
    parser.add_argument("--dry-run", action="store_true", help="Count only; no writes")
    parser.add_argument(
        "--update",
        action="store_true",
        help="On value change, supersede previous approved claim",
    )
    args = parser.parse_args()

    init_db()
    db = SessionLocal()
    try:
        site_id = None if args.all_sites else args.site_id
        totals = backfill_site(
            db,
            site_id,
            include_pending=args.include_pending,
            dry_run=args.dry_run,
            update=args.update,
        )
        mode = "DRY-RUN " if args.dry_run else ""
        print(
            f"{mode}Stage 2 backfill: entities={totals['entities']} "
            f"inserted={totals['inserted']} skipped={totals['skipped']} "
            f"superseded={totals['superseded']}"
        )
        return 0
    finally:
        db.close()


if __name__ == "__main__":
    raise SystemExit(main())
