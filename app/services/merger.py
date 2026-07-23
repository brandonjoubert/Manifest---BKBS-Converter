"""Entity normalization, dedupe, merge, and rescan semantics."""

from __future__ import annotations

import hashlib
import re
from typing import Any

from sqlalchemy.orm import Session

from app.models import Entity, EntityVersion, utcnow
from app.schemas import ExtractedEntity


def normalize_name(name: str) -> str:
    name = name.strip().lower()
    name = re.sub(r"\s+", " ", name)
    name = re.sub(r"[^\w\s\-&.]", "", name)
    return name


def external_key(site_id: str, entity_type: str, name: str) -> str:
    base = f"{site_id}|{entity_type}|{normalize_name(name)}"
    return hashlib.sha256(base.encode("utf-8")).hexdigest()[:32]


def _merge_dicts(a: dict, b: dict) -> dict:
    out = dict(a or {})
    for k, v in (b or {}).items():
        if v is None or v == "" or v == [] or v == {}:
            continue
        if k not in out or out[k] in (None, "", [], {}):
            out[k] = v
        elif isinstance(out[k], dict) and isinstance(v, dict):
            out[k] = _merge_dicts(out[k], v)
        # else keep existing preferred (approved human edits preserved by not overwriting unless empty)
    return out


def _merge_list_of_dicts(a: list, b: list, key_fields: tuple[str, ...] = ("url", "predicate", "target_name")) -> list:
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


def material_change(old: Entity, new_data: ExtractedEntity) -> bool:
    if normalize_name(old.name) != normalize_name(new_data.name):
        return True
    if (old.description or "").strip() != (new_data.description or "").strip():
        # Ignore if new is empty
        if new_data.description:
            return True
    # properties keys added
    old_props = old.properties or {}
    for k, v in (new_data.properties or {}).items():
        if v and old_props.get(k) != v:
            return True
    return False


def apply_extracted(
    db: Session,
    site_id: str,
    extracted: list[ExtractedEntity],
    scan_job_id: str | None,
    is_rescan: bool = False,
) -> dict[str, int]:
    """
    Merge extracted entities into the site graph.
    Returns stats: created, updated, unchanged, marked_stale.
    """
    stats = {"created": 0, "updated": 0, "unchanged": 0, "marked_stale": 0, "total_in": len(extracted)}
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

        # Merge into existing
        changed = material_change(existing, item)
        existing.properties = _merge_dicts(existing.properties or {}, item.properties or {})
        existing.relationships = _merge_list_of_dicts(
            existing.relationships or [], item.relationships or [], ("predicate", "target_name", "target_entity_id")
        )
        existing.evidence = _merge_list_of_dicts(
            existing.evidence or [], item.evidence or [], ("url", "snippet", "kind")
        )
        if item.description and not existing.description:
            existing.description = item.description
        elif item.description and existing.status in ("pending", "stale", "needs_edit") and existing.source != "manual":
            # allow refresh of non-approved auto content
            if len(item.description) > len(existing.description or ""):
                existing.description = item.description
                changed = True

        existing.last_scan_job_id = scan_job_id
        existing.last_updated = utcnow()
        if existing.status == "stale":
            existing.status = "pending"
            changed = True

        if changed:
            if existing.status == "approved":
                # Human-approved: flag for review rather than silently changing meaning
                existing.status = "needs_edit"
            elif existing.status == "rejected":
                # leave rejected unless we want re-open — re-open to pending on rescan change
                if is_rescan:
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
        else:
            stats["unchanged"] += 1

    # Mark stale: scan-sourced entities not seen this scan (not manual-only)
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
