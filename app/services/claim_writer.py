"""Claim Ledger Stage 3 — append-only pending claim writes for scan merge."""

from __future__ import annotations

from datetime import datetime

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.models import Claim, utcnow
from app.services.claim_codec import entity_attribute_pairs


def latest_claim(
    db: Session,
    entity_id: str,
    attribute: str,
    *,
    status: str,
) -> Claim | None:
    rows = list(
        db.scalars(
            select(Claim)
            .where(
                Claim.entity_id == entity_id,
                Claim.attribute == attribute,
                Claim.status == status,
            )
            .order_by(Claim.id.desc())
        ).all()
    )
    return rows[0] if rows else None


def latest_approved_claim(db: Session, entity_id: str, attribute: str) -> Claim | None:
    return latest_claim(db, entity_id, attribute, status="approved")


def latest_pending_claim(db: Session, entity_id: str, attribute: str) -> Claim | None:
    return latest_claim(db, entity_id, attribute, status="pending")


def baseline_encoded_value(db: Session, entity: object, attribute: str) -> str | None:
    """Encoded current truth: latest approved claim, else entity column encoding."""
    eid = getattr(entity, "id", None)
    if eid:
        approved = latest_approved_claim(db, str(eid), attribute)
        if approved is not None:
            return approved.value
    pairs = dict(entity_attribute_pairs(entity))
    return pairs.get(attribute)


def scan_attribute_pairs(extracted: object) -> list[tuple[str, str]]:
    """Attribute pairs for scan proposals (omit entity status)."""
    return [(a, v) for a, v in entity_attribute_pairs(extracted) if a != "status"]


def insert_pending_claim(
    db: Session,
    *,
    entity_id: str,
    entity_type: str,
    attribute: str,
    value: str,
    extraction_method: str = "scan",
    created_at: datetime | None = None,
) -> Claim:
    """Insert a pending claim; supersede prior approved (pointer) and prior pending."""
    approved = latest_approved_claim(db, entity_id, attribute)
    prior_pending = latest_pending_claim(db, entity_id, attribute)

    supersedes_id: int | None = None
    if approved is not None:
        supersedes_id = approved.id
    if prior_pending is not None:
        prior_pending.status = "superseded"
        if supersedes_id is None:
            supersedes_id = prior_pending.id

    claim = Claim(
        entity_id=entity_id,
        entity_type=entity_type,
        attribute=attribute,
        value=value,
        source_url=None,
        extraction_method=(extraction_method or "scan")[:32],
        confidence=None,
        status="pending",
        supersedes_id=supersedes_id,
        created_at=created_at or utcnow(),
        approved_by=None,
        approved_at=None,
    )
    db.add(claim)
    return claim


def propose_claims_from_extract(
    db: Session,
    entity: object,
    extracted: object,
) -> dict[str, int]:
    """
    Compare extract atoms to approved-claim-or-entity baseline; insert pending claims.

    Returns counts: claims_created, claims_unchanged.
    Does not mutate entity attribute columns.
    """
    stats = {"claims_created": 0, "claims_unchanged": 0}
    entity_id = str(getattr(entity, "id"))
    entity_type = str(getattr(entity, "entity_type") or "unknown")
    extraction = str(getattr(extracted, "source", None) or "scan")[:32]

    for attr, incoming in scan_attribute_pairs(extracted):
        current = baseline_encoded_value(db, entity, attr)
        if current is not None and current == incoming:
            stats["claims_unchanged"] += 1
            continue
        if attr == "description" and incoming == "":
            stats["claims_unchanged"] += 1
            continue

        insert_pending_claim(
            db,
            entity_id=entity_id,
            entity_type=entity_type,
            attribute=attr,
            value=incoming,
            extraction_method=extraction,
        )
        stats["claims_created"] += 1

    return stats


def seed_pending_claims_for_new_entity(db: Session, entity: object) -> int:
    """Insert pending claims mirroring a newly created entity shell."""
    n = 0
    entity_id = str(getattr(entity, "id"))
    entity_type = str(getattr(entity, "entity_type") or "unknown")
    extraction = str(getattr(entity, "source", None) or "scan")[:32]
    for attr, value in scan_attribute_pairs(entity):
        insert_pending_claim(
            db,
            entity_id=entity_id,
            entity_type=entity_type,
            attribute=attr,
            value=value,
            extraction_method=extraction,
        )
        n += 1
    return n
