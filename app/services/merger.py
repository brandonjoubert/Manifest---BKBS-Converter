"""Entity normalization, dedupe, merge, and rescan semantics (Stage 3 claim-only scan)."""

from __future__ import annotations

import hashlib
import re
from typing import Any

from sqlalchemy.orm import Session

from app.models import Entity, EntityVersion, utcnow
from app.schemas import ExtractedEntity
from app.services.claim_writer import (
    propose_claims_from_extract,
    seed_pending_claims_for_new_entity,
)


def normalize_name(name: str) -> str:
    name = name.strip().lower()
    name = re.sub(r"\s+", " ", name)
    name = re.sub(r"[^\w\s\-&.]", "", name)
    return name


def external_key(site_id: str, entity_type: str, name: str) -> str:
    base = f"{site_id}|{entity_type}|{normalize_name(name)}"
    return hashlib.sha256(base.encode("utf-8")).hexdigest()[:32]


def _merge_dicts(a: dict, b: dict) -> dict:
    """Kept for unit tests / human-path helpers; scan path no longer uses this."""
    out = dict(a or {})
    for k, v in (b or {}).items():
        if v is None or v == "" or v == [] or v == {}:
            continue
        if k not in out or out[k] in (None, "", [], {}):
            out[k] = v
        elif isinstance(out[k], dict) and isinstance(v, dict):
            out[k] = _merge_dicts(out[k], v)
    return out


def _merge_list_of_dicts(
    a: list, b: list, key_fields: tuple[str, ...] = ("url", "predicate", "target_name")
) -> list:
    result = list(a or [])
    seen = set()
    for item in result:
        if not isinstance(item, dict):
            continue
        key = tuple(str(item.get(f) or "") for f in key_fields)
        seen.add(key)
    for item in b or []:
        if not isinstance(item, dict):
            continue
        key = tuple(str(item.get(f) or "") for f in key_fields)
        if key not in seen:
            result.append(item)
            seen.add(key)
    return result


def snapshot_entity(entity: Entity) -> dict[str, Any]:
    return {
        "entity_type": entity.entity_type,
        "name": entity.name,
        "description": entity.description,
        "properties": entity.properties or {},
        "relationships": entity.relationships or [],
        "evidence": entity.evidence or [],
        "status": entity.status,
        "trust_level": entity.trust_level,
        "source": entity.source,
        "version": entity.version,
    }


def apply_extracted(
    db: Session,
    site_id: str,
    extracted: list[ExtractedEntity],
    scan_job_id: str | None,
    is_rescan: bool = False,
) -> dict[str, int]:
    """
    Stage 3 claim-only scan merge.

    - New entities: INSERT entity shell (pending) + pending claims for all atoms.
    - Existing: do NOT UPDATE attribute columns from scan; insert pending claims
      for changed atoms; approved + any new claim → needs_edit.
    - Rescan: mark unseen non-manual entities stale (status only).

    Returns stats including claims_created / claims_unchanged.
    """
    stats = {
        "created": 0,
        "updated": 0,  # kept for callers; means "touched with claim activity or status"
        "unchanged": 0,
        "marked_stale": 0,
        "claims_created": 0,
        "claims_unchanged": 0,
        "total_in": len(extracted),
    }
    seen_keys: set[str] = set()

    for item in extracted:
        key = external_key(site_id, item.entity_type, item.name)
        seen_keys.add(key)
        existing = (
            db.query(Entity)
            .filter(Entity.site_id == site_id, Entity.external_key == key)
            .one_or_none()
        )

        if existing is None:
            ent = Entity(
                site_id=site_id,
                external_key=key,
                entity_type=item.entity_type,
                name=item.name,
                description=item.description,
                properties=item.properties or {},
                relationships=item.relationships or [],
                evidence=item.evidence or [],
                version=1,
                trust_level=item.trust_level or "medium",
                source=item.source or "scan",
                status="pending",
                last_scan_job_id=scan_job_id,
                last_updated=utcnow(),
            )
            db.add(ent)
            db.flush()
            n_claims = seed_pending_claims_for_new_entity(db, ent)
            stats["claims_created"] += n_claims
            db.add(
                EntityVersion(
                    entity_id=ent.id,
                    version=1,
                    snapshot_json=snapshot_entity(ent),
                    change_source=item.source or "scan",
                )
            )
            stats["created"] += 1
            continue

        # Existing: bookkeeping only on entity; claims for attribute proposals
        claim_stats = propose_claims_from_extract(db, existing, item)
        stats["claims_created"] += claim_stats["claims_created"]
        stats["claims_unchanged"] += claim_stats["claims_unchanged"]

        existing.last_scan_job_id = scan_job_id
        existing.last_updated = utcnow()

        touched = claim_stats["claims_created"] > 0
        if existing.status == "stale":
            existing.status = "pending"
            touched = True

        if claim_stats["claims_created"] > 0:
            if existing.status == "approved":
                existing.status = "needs_edit"
            elif existing.status == "rejected" and is_rescan:
                existing.status = "pending"
            existing.version = (existing.version or 1) + 1
            existing.source = "rescan_merge" if is_rescan else (item.source or existing.source)
            db.add(
                EntityVersion(
                    entity_id=existing.id,
                    version=existing.version,
                    snapshot_json=snapshot_entity(existing),
                    change_source="rescan_merge" if is_rescan else (item.source or "scan"),
                )
            )
            stats["updated"] += 1
        elif touched:
            stats["updated"] += 1
        else:
            stats["unchanged"] += 1

    if is_rescan and seen_keys:
        candidates = (
            db.query(Entity)
            .filter(Entity.site_id == site_id, Entity.status.in_(["pending", "approved", "needs_edit"]))
            .all()
        )
        for ent in candidates:
            if ent.external_key in seen_keys:
                continue
            if ent.source == "manual":
                continue
            if ent.status != "stale":
                ent.status = "stale"
                ent.last_updated = utcnow()
                ent.version = (ent.version or 1) + 1
                db.add(
                    EntityVersion(
                        entity_id=ent.id,
                        version=ent.version,
                        snapshot_json=snapshot_entity(ent),
                        change_source="rescan_stale",
                    )
                )
                stats["marked_stale"] += 1

    db.commit()
    return stats


def resolve_relationship_targets(db: Session, site_id: str) -> int:
    """Fill target_entity_id on relationships when target_name matches."""
    entities = db.query(Entity).filter(Entity.site_id == site_id).all()
    by_name: dict[str, str] = {normalize_name(e.name): e.id for e in entities}
    updated = 0
    for ent in entities:
        rels = ent.relationships or []
        changed = False
        new_rels = []
        for rel in rels:
            if not isinstance(rel, dict):
                continue
            rel = dict(rel)
            tname = rel.get("target_name")
            if tname and not rel.get("target_entity_id"):
                tid = by_name.get(normalize_name(str(tname)))
                if tid:
                    rel["target_entity_id"] = tid
                    changed = True
            new_rels.append(rel)
        if changed:
            ent.relationships = new_rels
            updated += 1
    if updated:
        db.commit()
    return updated
