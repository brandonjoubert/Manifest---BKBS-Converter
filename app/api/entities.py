"""Entity CRUD, verification, manual create, free-text convert."""

from __future__ import annotations

import json
from types import SimpleNamespace

from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy.orm import Session

from app.db import get_db
from app.models import Claim, Entity, Site, utcnow
from app.schemas import (
    BulkVerify,
    EntityCreate,
    EntityOut,
    EntityUpdate,
    FreeTextConvert,
    VerifyAction,
)
from app.services.claim_diff import can_bulk_approve, claim_diff_for_entity
from app.services.claim_writer import apply_human_decision, write_surface_claims
from app.services.extractor_llm import convert_free_text
from app.services.merger import apply_extracted, external_key
from app.services.resolver import resolve_entity


def _to_out(db: Session, ent: Entity) -> EntityOut:
    r = resolve_entity(ent.id, db=db, overlay_pending=True)
    return EntityOut(
        id=ent.id,
        site_id=ent.site_id,
        external_key=ent.external_key,
        entity_type=ent.entity_type,
        name=(r.name if r else "") or "",
        description=r.description if r else None,
        properties=dict(r.properties) if r else {},
        relationships=list(r.relationships) if r else [],
        evidence=list(r.evidence) if r else [],
        version=ent.version or 1,
        trust_level=ent.trust_level or "medium",
        source=ent.source or "scan",
        status=ent.status or "pending",
        notes=ent.notes,
        last_updated=ent.last_updated,
        created_at=ent.created_at,
    )


def _clear_attribute_columns(ent: Entity) -> None:
    ent.name = ""
    ent.description = None
    ent.properties = {}
    ent.relationships = []
    ent.evidence = []

router = APIRouter(tags=["entities"])


@router.get("/api/sites/{site_id}/entities", response_model=list[EntityOut])
def list_entities(
    site_id: str,
    status: str | None = None,
    entity_type: str | None = None,
    q: str | None = None,
    db: Session = Depends(get_db),
):
    site = db.get(Site, site_id)
    if not site:
        raise HTTPException(404, "Site not found")
    query = db.query(Entity).filter(Entity.site_id == site_id)
    if status:
        query = query.filter(Entity.status == status)
    if entity_type:
        query = query.filter(Entity.entity_type == entity_type)
    if q:
        like = f"%{q}%"
        ids = [
            row[0]
            for row in db.query(Claim.entity_id)
            .filter(
                Claim.attribute.in_(("name", "description")),
                Claim.status.in_(("approved", "pending")),
                Claim.value.ilike(like),
            )
            .distinct()
            .all()
        ]
        query = query.filter(Entity.id.in_(ids or ["__none__"]))
    rows = query.order_by(Entity.status, Entity.entity_type, Entity.id).limit(500).all()
    return [_to_out(db, e) for e in rows]


@router.get("/api/entities/{entity_id}", response_model=EntityOut)
def get_entity(entity_id: str, db: Session = Depends(get_db)):
    ent = db.get(Entity, entity_id)
    if not ent:
        raise HTTPException(404, "Entity not found")
    return _to_out(db, ent)


@router.patch("/api/entities/{entity_id}", response_model=EntityOut)
def update_entity(entity_id: str, body: EntityUpdate, db: Session = Depends(get_db)):
    ent = db.get(Entity, entity_id)
    if not ent:
        raise HTTPException(404, "Entity not found")
    previous_status = ent.status
    data = body.model_dump(exclude_unset=True)
    current = _to_out(db, ent)
    name = data["name"] if "name" in data and data["name"] else current.name
    etype = data["entity_type"] if data.get("entity_type") else ent.entity_type
    surface = SimpleNamespace(
        entity_type=etype,
        name=name,
        description=data["description"] if "description" in data else current.description,
        properties=data["properties"] if "properties" in data else current.properties,
        relationships=data["relationships"] if "relationships" in data else current.relationships,
        evidence=data["evidence"] if "evidence" in data else current.evidence,
        trust_level=data["trust_level"] if "trust_level" in data else current.trust_level,
        source=current.source,
        status=ent.status,
    )
    if "name" in data or "entity_type" in data:
        ent.external_key = external_key(ent.site_id, etype, name)
        ent.entity_type = etype
    if "notes" in data:
        ent.notes = data["notes"]
    if "status" in data and data["status"]:
        ent.status = data["status"]
    if "trust_level" in data and data["trust_level"]:
        ent.trust_level = data["trust_level"]
    ent.version = (ent.version or 1) + 1
    ent.last_updated = utcnow()
    write_surface_claims(
        db,
        ent,
        claim_status="pending",
        extraction_method="manual",
        surface=surface,
    )
    if previous_status == "approved":
        ent.status = "needs_edit"
    _clear_attribute_columns(ent)
    db.commit()
    db.refresh(ent)
    return _to_out(db, ent)


@router.get("/api/entities/{entity_id}/diff")
def entity_diff(entity_id: str, db: Session = Depends(get_db)):
    ent = db.get(Entity, entity_id)
    if not ent:
        raise HTTPException(404, "Entity not found")
    diff = claim_diff_for_entity(db, entity_id)
    diff["envelope_status"] = ent.status
    if not diff.get("display_name"):
        resolved = resolve_entity(entity_id, db=db, overlay_pending=True)
        diff["display_name"] = resolved.name if resolved else ""
    return diff


@router.post("/api/entities/{entity_id}/verify", response_model=EntityOut)
def verify_entity(entity_id: str, body: VerifyAction, db: Session = Depends(get_db)):
    ent = db.get(Entity, entity_id)
    if not ent:
        raise HTTPException(404, "Entity not found")
    apply_human_decision(db, ent, body.action, approved_by="api")
    ent.last_updated = utcnow()
    ent.version = (ent.version or 1) + 1
    _clear_attribute_columns(ent)
    db.commit()
    db.refresh(ent)
    return _to_out(db, ent)


@router.post("/api/entities/bulk-verify")
def bulk_verify(body: BulkVerify, db: Session = Depends(get_db)):
    updated = 0
    skipped: list[str] = []
    for eid in body.entity_ids:
        ent = db.get(Entity, eid)
        if not ent:
            continue
        if body.action == "approve" and not can_bulk_approve(db, ent.id):
            skipped.append(ent.id)
            continue
        apply_human_decision(db, ent, body.action, approved_by="api")
        ent.last_updated = utcnow()
        ent.version = (ent.version or 1) + 1
        _clear_attribute_columns(ent)
        updated += 1
    db.commit()
    return {"updated": updated, "skipped": skipped}


@router.post("/api/sites/{site_id}/entities", response_model=EntityOut)
def create_entity(site_id: str, body: EntityCreate, db: Session = Depends(get_db)):
    site = db.get(Site, site_id)
    if not site:
        raise HTTPException(404, "Site not found")
    key = external_key(site_id, body.entity_type, body.name)
    existing = (
        db.query(Entity)
        .filter(Entity.site_id == site_id, Entity.external_key == key)
        .one_or_none()
    )
    if existing:
        raise HTTPException(409, "An entity with this type and name already exists")
    status = "approved" if body.approve_immediately else "pending"
    ent = Entity(
        site_id=site_id,
        external_key=key,
        entity_type=body.entity_type,
        name=body.name,
        description=body.description,
        properties=body.properties or {},
        relationships=body.relationships or [],
        evidence=body.evidence or [],
        trust_level=body.trust_level,
        source="manual",
        status=status,
    )
    db.add(ent)
    db.flush()
    write_surface_claims(
        db,
        ent,
        claim_status="approved" if status == "approved" else "pending",
        extraction_method="manual",
        approved_by="api" if status == "approved" else None,
        surface=ent,
    )
    _clear_attribute_columns(ent)
    db.commit()
    db.refresh(ent)
    return _to_out(db, ent)


@router.post("/api/sites/{site_id}/entities/from-text", response_model=list[EntityOut])
def entities_from_text(site_id: str, body: FreeTextConvert, db: Session = Depends(get_db)):
    site = db.get(Site, site_id)
    if not site:
        raise HTTPException(404, "Site not found")
    extracted = convert_free_text(
        body.text,
        base_url=site.base_url,
        default_entity_type=body.default_entity_type,
        db=db,
    )
    if body.approve_immediately:
        # create approved via direct insert after merge as pending then approve
        pass
    apply_extracted(db, site_id, extracted, scan_job_id=None, is_rescan=False)
    names = {e.name for e in extracted}
    results = []
    for ent in db.query(Entity).filter(Entity.site_id == site_id).all():
        resolved = resolve_entity(ent.id, db=db, overlay_pending=True)
        if resolved and resolved.name in names:
            results.append(ent)
    if body.approve_immediately:
        for ent in results:
            ent.source = "manual"
            apply_human_decision(db, ent, "approve", approved_by="api")
        db.commit()
        for ent in results:
            db.refresh(ent)
    return [_to_out(db, e) for e in results]


@router.delete("/api/entities/{entity_id}")
def delete_entity(entity_id: str, db: Session = Depends(get_db)):
    ent = db.get(Entity, entity_id)
    if not ent:
        raise HTTPException(404, "Entity not found")
    db.query(Claim).filter(Claim.entity_id == ent.id).delete(synchronize_session=False)
    db.delete(ent)
    db.commit()
    return {"ok": True}


# Helper used by form posts that send properties as JSON string
def parse_properties_field(raw: str | None) -> dict:
    if not raw or not raw.strip():
        return {}
    try:
        data = json.loads(raw)
        return data if isinstance(data, dict) else {}
    except json.JSONDecodeError:
        return {"notes": raw}
