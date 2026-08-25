"""Claim Ledger Stage 3/4a — append-only claim writes (scan + human decisions)."""

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
    db.flush()
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


def insert_approved_claim(
    db: Session,
    *,
    entity_id: str,
    entity_type: str,
    attribute: str,
    value: str,
    extraction_method: str = "manual",
    approved_by: str | None = None,
    created_at: datetime | None = None,
) -> Claim:
    """Insert an approved claim; supersede prior approved and prior pending."""
    now = created_at or utcnow()
    approved = latest_approved_claim(db, entity_id, attribute)
    prior_pending = latest_pending_claim(db, entity_id, attribute)

    supersedes_id: int | None = None
    if approved is not None:
        supersedes_id = approved.id
        approved.status = "superseded"
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
        extraction_method=(extraction_method or "manual")[:32],
        confidence=None,
        status="approved",
        supersedes_id=supersedes_id,
        created_at=now,
        approved_by=approved_by,
        approved_at=now,
    )
    db.add(claim)
    db.flush()
    return claim


def write_surface_claims(
    db: Session,
    entity: object,
    *,
    claim_status: str,
    extraction_method: str = "manual",
    approved_by: str | None = None,
    surface: object | None = None,
) -> int:
    """Insert claims from an entity-like surface (omits envelope status)."""
    src = surface if surface is not None else entity
    entity_id = str(getattr(entity, "id"))
    entity_type = str(getattr(entity, "entity_type") or "unknown")
    method = (extraction_method or "manual")[:32]
    n = 0
    for attr, value in scan_attribute_pairs(src):
        if claim_status == "approved":
            existing = latest_approved_claim(db, entity_id, attr)
            if existing is not None and existing.value == value:
                pending = latest_pending_claim(db, entity_id, attr)
                if pending is not None:
                    pending.status = "superseded"
                continue
            insert_approved_claim(
                db,
                entity_id=entity_id,
                entity_type=entity_type,
                attribute=attr,
                value=value,
                extraction_method=method,
                approved_by=approved_by,
            )
            n += 1
            continue
        if claim_status == "pending":
            pending = latest_pending_claim(db, entity_id, attr)
            if pending is not None and pending.value == value:
                continue
            approved = latest_approved_claim(db, entity_id, attr)
            if approved is not None and approved.value == value and pending is None:
                continue
            insert_pending_claim(
                db,
                entity_id=entity_id,
                entity_type=entity_type,
                attribute=attr,
                value=value,
                extraction_method=method,
            )
            n += 1
    return n


def promote_pending_claims(
    db: Session,
    entity: object,
    *,
    approved_by: str | None = None,
) -> int:
    """Promote latest pending claim per attribute to approved; supersede prior approved."""
    entity_id = str(getattr(entity, "id"))
    rows = list(
        db.scalars(
            select(Claim).where(
                Claim.entity_id == entity_id,
                Claim.status == "pending",
            )
        ).all()
    )
    latest: dict[str, Claim] = {}
    for c in rows:
        prev = latest.get(c.attribute)
        if prev is None or (c.id or 0) > (prev.id or 0):
            latest[c.attribute] = c

    now = utcnow()
    n = 0
    for attr, pending in latest.items():
        for other in rows:
            if other.attribute == attr and other.id != pending.id:
                other.status = "superseded"
        prior = latest_approved_claim(db, entity_id, attr)
        if prior is not None and prior.id != pending.id:
            prior.status = "superseded"
            if pending.supersedes_id is None:
                pending.supersedes_id = prior.id
        pending.status = "approved"
        pending.approved_at = now
        pending.approved_by = approved_by
        n += 1
    return n


def seed_missing_approved_claims(
    db: Session,
    entity: object,
    *,
    approved_by: str | None = None,
    extraction_method: str | None = None,
) -> int:
    """Insert approved claims for attributes that have none (legacy / no-pending approve)."""
    entity_id = str(getattr(entity, "id"))
    entity_type = str(getattr(entity, "entity_type") or "unknown")
    method = (extraction_method or getattr(entity, "source", None) or "manual")[:32]
    n = 0
    for attr, value in scan_attribute_pairs(entity):
        if latest_approved_claim(db, entity_id, attr) is not None:
            continue
        insert_approved_claim(
            db,
            entity_id=entity_id,
            entity_type=entity_type,
            attribute=attr,
            value=value,
            extraction_method=method,
            approved_by=approved_by,
        )
        n += 1
    return n


def reject_pending_claims(db: Session, entity: object) -> int:
    entity_id = str(getattr(entity, "id"))
    n = 0
    for c in list(
        db.scalars(
            select(Claim).where(
                Claim.entity_id == entity_id,
                Claim.status == "pending",
            )
        ).all()
    ):
        c.status = "rejected"
        n += 1
    return n


def apply_human_decision(
    db: Session,
    entity: object,
    action: str,
    *,
    approved_by: str | None = None,
    surface: object | None = None,
) -> dict[str, int]:
    """
    Stage 4a: approve / reject / needs_edit write claims and set envelope status.

    Approve with surface=None (verify): promote pending claims; seed gaps from columns.
    Approve with surface (save & approve): write approved claims from the saved surface.
    Reject: pending claims → rejected; last approved claims stay approved.
    needs_edit: envelope only; last approved snapshot stays live.
    """
    stats = {"claims_written": 0, "claims_promoted": 0, "claims_rejected": 0}
    if action == "approve":
        if surface is not None:
            stats["claims_written"] = write_surface_claims(
                db,
                entity,
                claim_status="approved",
                extraction_method=str(getattr(surface, "source", None) or "manual"),
                approved_by=approved_by,
                surface=surface,
            )
        else:
            stats["claims_promoted"] = promote_pending_claims(
                db, entity, approved_by=approved_by
            )
            stats["claims_written"] = seed_missing_approved_claims(
                db, entity, approved_by=approved_by
            )
        entity.status = "approved"  # type: ignore[attr-defined]
    elif action == "reject":
        stats["claims_rejected"] = reject_pending_claims(db, entity)
        entity.status = "rejected"  # type: ignore[attr-defined]
    elif action == "needs_edit":
        entity.status = "needs_edit"  # type: ignore[attr-defined]
    return stats


def apply_save_claims(
    db: Session,
    entity: object,
    *,
    intent: str,
    previous_status: str,
    approved_by: str | None = None,
) -> dict[str, int]:
    """After entity columns are updated: write claims for Save / Save & approve / reject."""
    if intent == "save_reject":
        return apply_human_decision(db, entity, "reject", approved_by=approved_by)
    if intent == "save_approve" or getattr(entity, "status", None) == "approved":
        return apply_human_decision(
            db, entity, "approve", approved_by=approved_by, surface=entity
        )

    n = write_surface_claims(
        db,
        entity,
        claim_status="pending",
        extraction_method=str(getattr(entity, "source", None) or "manual"),
        approved_by=approved_by,
        surface=entity,
    )
    envelope = str(getattr(entity, "status", "") or "")
    if n and previous_status == "approved" and envelope not in ("pending", "rejected"):
        entity.status = "needs_edit"  # type: ignore[attr-defined]
    return {"claims_written": n, "claims_promoted": 0, "claims_rejected": 0}
