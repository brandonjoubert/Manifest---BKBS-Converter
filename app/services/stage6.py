"""Claim Ledger Stage 6 — envelope-only entities; restore attributes from claims."""

from __future__ import annotations

from sqlalchemy.orm import Session

from app.models import Claim, Entity, utcnow
from app.services.claim_codec import entity_attribute_pairs
from app.services.claim_writer import insert_approved_claim, insert_pending_claim, latest_approved_claim
from app.services.resolver import resolve_entity


def backfill_missing_claims_from_columns(db: Session, entity: Entity) -> int:
    """If the envelope still has column values and no claims, copy them onto the ledger."""
    n = 0
    eid = str(entity.id)
    etype = str(entity.entity_type or "unknown")
    method = str(entity.source or "manual")[:32]
    status = "approved" if entity.status in ("approved", "needs_edit", "stale") else "pending"
    for attr, value in entity_attribute_pairs(entity):
        if attr == "name" and (value == "" or value == '""'):
            continue
        if latest_approved_claim(db, eid, attr) is not None:
            continue
        from app.services.claim_writer import latest_pending_claim

        if latest_pending_claim(db, eid, attr) is not None:
            continue
        if status == "approved":
            insert_approved_claim(
                db,
                entity_id=eid,
                entity_type=etype,
                attribute=attr,
                value=value,
                extraction_method=method,
                approved_by="stage6-migrate",
            )
        else:
            insert_pending_claim(
                db,
                entity_id=eid,
                entity_type=etype,
                attribute=attr,
                value=value,
                extraction_method=method,
            )
        n += 1
    return n


def null_attribute_columns(db: Session) -> int:
    """Stage 6: backfill any leftover column facts, then clear attribute columns."""
    n = 0
    for ent in db.query(Entity).all():
        backfill_missing_claims_from_columns(db, ent)
        if (
            (ent.name or "") != ""
            or ent.description
            or (ent.properties or {})
            or (ent.relationships or [])
            or (ent.evidence or [])
        ):
            n += 1
        ent.name = ""
        ent.description = None
        ent.properties = {}
        ent.relationships = []
        ent.evidence = []
        ent.last_updated = utcnow()
    db.commit()
    return n


def restore_attribute_columns_from_claims(db: Session) -> int:
    """Rollback helper: copy latest approved (else pending) claims back onto columns."""
    n = 0
    for ent in db.query(Entity).all():
        resolved = resolve_entity(ent.id, db=db, overlay_pending=True)
        if not resolved:
            continue
        ent.name = resolved.name or ""
        ent.description = resolved.description
        ent.properties = dict(resolved.properties or {})
        ent.relationships = list(resolved.relationships or [])
        ent.evidence = list(resolved.evidence or [])
        n += 1
    db.commit()
    return n


def claims_for_entity_ids(db: Session, entity_ids: list[str]) -> int:
    if not entity_ids:
        return 0
    return db.query(Claim).filter(Claim.entity_id.in_(entity_ids)).count()
