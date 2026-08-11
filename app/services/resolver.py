"""Claim Ledger resolver (Stage 2: real resolve from claims + entity row)."""

from __future__ import annotations

from datetime import datetime
from typing import Any

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.models import Claim, Entity
from app.services.claim_codec import decode_claim_value, is_prop_attribute, prop_key
from app.services.resolved_entity import ResolvedEntity


def _latest_approved_claims(
    db: Session,
    entity_id: str,
    as_of: datetime | None = None,
) -> dict[str, Claim]:
    """Map attribute -> latest approved claim (highest id wins)."""
    q = select(Claim).where(
        Claim.entity_id == entity_id,
        Claim.status == "approved",
    )
    if as_of is not None:
        # Prefer approved_at when set; else created_at
        q = q.where(
            ((Claim.approved_at != None) & (Claim.approved_at <= as_of))  # noqa: E711
            | ((Claim.approved_at == None) & (Claim.created_at <= as_of))  # noqa: E711
        )
    rows = list(db.scalars(q).all())
    by_attr: dict[str, Claim] = {}
    for c in rows:
        prev = by_attr.get(c.attribute)
        if prev is None or (c.id or 0) > (prev.id or 0):
            by_attr[c.attribute] = c
    return by_attr


def _apply_claims_to_base(
    claims: dict[str, Claim],
    *,
    name: str,
    description: str | None,
    properties: dict[str, Any],
    relationships: list[Any],
    evidence: list[Any],
    trust_level: str,
    source: str,
    status: str,
) -> tuple[str, str | None, dict[str, Any], list[Any], list[Any], str, str, str]:
    """Overlay claim values onto entity-column base values."""
    props = dict(properties)
    for attr, claim in claims.items():
        raw = claim.value
        if is_prop_attribute(attr):
            props[prop_key(attr)] = decode_claim_value(raw)
            continue
        decoded = decode_claim_value(raw)
        if attr == "name":
            name = "" if decoded is None else str(decoded)
        elif attr == "description":
            if decoded is None or decoded == "":
                description = None
            else:
                description = str(decoded)
        elif attr == "relationships":
            relationships = decoded if isinstance(decoded, list) else []
        elif attr == "evidence":
            evidence = decoded if isinstance(decoded, list) else []
        elif attr == "trust_level":
            trust_level = str(decoded) if decoded is not None else trust_level
        elif attr == "source":
            source = str(decoded) if decoded is not None else source
        elif attr == "status":
            status = str(decoded) if decoded is not None else status
    return name, description, props, relationships, evidence, trust_level, source, status


def resolve_entity(
    entity_id: str | None = None,
    as_of: datetime | None = None,
    *,
    db: Session | None = None,
) -> ResolvedEntity | None:
    """Resolve an entity's attributes from claims (+ entity row hybrid).

    Stage 2 behavior:
    - No entity_id or no db → None (Stage 1 stub-compatible call sites)
    - Unknown entity id → None
    - Entity exists → always return envelope; claim attrs override when present;
      missing claim attrs filled from entity columns (safe dual-path fallback)
    """
    if not entity_id or db is None:
        return None

    ent = db.get(Entity, entity_id)
    if not ent:
        return None

    claims = _latest_approved_claims(db, entity_id, as_of)

    name, description, properties, relationships, evidence, trust_level, source, status = (
        _apply_claims_to_base(
            claims,
            name=ent.name,
            description=ent.description,
            properties=dict(ent.properties or {}),
            relationships=list(ent.relationships or []),
            evidence=list(ent.evidence or []),
            trust_level=ent.trust_level or "medium",
            source=ent.source or "scan",
            status=ent.status or "approved",
        )
    )

    return ResolvedEntity(
        id=ent.id,
        entity_type=ent.entity_type,
        name=name,
        description=description,
        properties=properties,
        relationships=relationships,
        evidence=evidence,
        version=ent.version or 1,
        trust_level=trust_level or "medium",
        source=source or "scan",
        status=status or "approved",
        last_updated=ent.last_updated,
        external_key=ent.external_key or "",
        notes=ent.notes,
        site_id=ent.site_id or "",
    )
