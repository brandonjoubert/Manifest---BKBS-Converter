"""Claim Ledger resolver (Stage 2 hybrid + Stage 4a resolve_site public-set)."""

from __future__ import annotations

from datetime import datetime
from typing import Any

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.models import Claim, Entity
from app.services.claim_codec import decode_claim_value, is_prop_attribute, prop_key
from app.services.resolved_entity import ResolvedEntity

# Live / production files. Envelope pending (never approved) and rejected are excluded.
PUBLIC_ENVELOPE_STATUSES = ("approved", "needs_edit", "stale")
# Draft ZIP / include_pending. Rejected stays out.
DRAFT_ENVELOPE_STATUSES = ("approved", "pending", "needs_edit", "stale")


def _latest_approved_claims(
    db: Session,
    entity_id: str,
    as_of: datetime | None = None,
) -> dict[str, Claim]:
    """Map attribute -> latest approved claim (highest id wins)."""
    if as_of is not None:
        # Point-in-time: include rows later superseded so as_of can see prior facts.
        q = select(Claim).where(
            Claim.entity_id == entity_id,
            Claim.status.in_(("approved", "superseded")),
            ((Claim.approved_at != None) & (Claim.approved_at <= as_of))  # noqa: E711
            | ((Claim.approved_at == None) & (Claim.created_at <= as_of)),  # noqa: E711
        )
    else:
        q = select(Claim).where(
            Claim.entity_id == entity_id,
            Claim.status == "approved",
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


def _resolved_from_entity(ent: Entity, claims: dict[str, Claim]) -> ResolvedEntity:
    # Stage 6: attributes come from claims only. Envelope review_status / trust / source
    # stay on the entity row (not from a status claim).
    name, description, properties, relationships, evidence, _tl, _src, _st = (
        _apply_claims_to_base(
            claims,
            name="",
            description=None,
            properties={},
            relationships=[],
            evidence=[],
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
        trust_level=ent.trust_level or "medium",
        source=ent.source or "scan",
        status=ent.status or "approved",
        last_updated=ent.last_updated,
        external_key=ent.external_key or "",
        notes=ent.notes,
        site_id=ent.site_id or "",
    )


def _latest_claims_for_ids(
    db: Session,
    entity_ids: list[str],
    *,
    status: str,
    as_of: datetime | None = None,
) -> dict[str, dict[str, Claim]]:
    if not entity_ids:
        return {}
    q = select(Claim).where(
        Claim.entity_id.in_(entity_ids),
        Claim.status == status,
    )
    if as_of is not None:
        q = q.where(
            ((Claim.approved_at != None) & (Claim.approved_at <= as_of))  # noqa: E711
            | ((Claim.approved_at == None) & (Claim.created_at <= as_of))  # noqa: E711
        )
    out: dict[str, dict[str, Claim]] = {}
    for c in db.scalars(q).all():
        by_attr = out.setdefault(c.entity_id, {})
        prev = by_attr.get(c.attribute)
        if prev is None or (c.id or 0) > (prev.id or 0):
            by_attr[c.attribute] = c
    return out


def _merge_claim_maps(
    approved: dict[str, Claim], pending: dict[str, Claim]
) -> dict[str, Claim]:
    out = dict(approved)
    out.update(pending)
    return out


def _latest_approved_claims_for_ids(
    db: Session,
    entity_ids: list[str],
    as_of: datetime | None = None,
) -> dict[str, dict[str, Claim]]:
    """Batch: entity_id -> {attribute: latest approved claim}."""
    return _latest_claims_for_ids(db, entity_ids, status="approved", as_of=as_of)


def resolve_entity(
    entity_id: str | None = None,
    as_of: datetime | None = None,
    *,
    db: Session | None = None,
    overlay_pending: bool = False,
) -> ResolvedEntity | None:
    """Resolve attributes from claims only (Stage 6). Envelope comes from the entity row.

    overlay_pending: operator/draft view — pending claims win over approved.
    """
    if not entity_id or db is None:
        return None

    ent = db.get(Entity, entity_id)
    if not ent:
        return None

    claims = _latest_approved_claims(db, entity_id, as_of)
    if overlay_pending:
        pending = _latest_claims_for_ids(db, [entity_id], status="pending", as_of=as_of)
        claims = _merge_claim_maps(claims, pending.get(entity_id, {}))
    return _resolved_from_entity(ent, claims)


def in_public_set(envelope_status: str | None) -> bool:
    return (envelope_status or "") in PUBLIC_ENVELOPE_STATUSES


def resolve_site(
    db: Session,
    site_id: str,
    *,
    include_pending: bool = False,
    as_of: datetime | None = None,
) -> list[ResolvedEntity]:
    """Resolve a site's publication set (batched claim query).

    Public (include_pending=False):
      envelope approved / needs_edit / stale → last approved snapshot
      envelope pending (never approved) and rejected → excluded
    Draft (include_pending=True): include pending envelopes; pending claims overlay approved.
    """
    statuses = DRAFT_ENVELOPE_STATUSES if include_pending else PUBLIC_ENVELOPE_STATUSES
    ents = (
        db.query(Entity)
        .filter(Entity.site_id == site_id, Entity.status.in_(statuses))
        .order_by(Entity.entity_type, Entity.id)
        .all()
    )
    if not include_pending:
        ents = [e for e in ents if in_public_set(e.status)]
    ids = [e.id for e in ents]
    by_id = _latest_approved_claims_for_ids(db, ids, as_of)
    pending_by = (
        _latest_claims_for_ids(db, ids, status="pending", as_of=as_of)
        if include_pending
        else {}
    )
    out: list[ResolvedEntity] = []
    for e in ents:
        claims = by_id.get(e.id, {})
        if include_pending:
            claims = _merge_claim_maps(claims, pending_by.get(e.id, {}))
        out.append(_resolved_from_entity(e, claims))
    return out
