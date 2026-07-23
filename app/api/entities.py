"""Entity CRUD, verification, manual create, free-text convert."""

from __future__ import annotations

import json

from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy.orm import Session

from app.db import get_db
from app.models import Entity, EntityVersion, Site, utcnow
from app.schemas import (
    BulkVerify,
    EntityCreate,
    EntityOut,
    EntityUpdate,
    FreeTextConvert,
    VerifyAction,
)
from app.services.extractor_llm import convert_free_text
from app.services.merger import apply_extracted, external_key, snapshot_entity

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
        query = query.filter((Entity.name.ilike(like)) | (Entity.description.ilike(like)))
    return query.order_by(Entity.status, Entity.entity_type, Entity.name).limit(500).all()


@router.get("/api/entities/{entity_id}", response_model=EntityOut)
def get_entity(entity_id: str, db: Session = Depends(get_db)):
    ent = db.get(Entity, entity_id)
    if not ent:
        raise HTTPException(404, "Entity not found")
    return ent


@router.patch("/api/entities/{entity_id}", response_model=EntityOut)
def update_entity(entity_id: str, body: EntityUpdate, db: Session = Depends(get_db)):
    ent = db.get(Entity, entity_id)
    if not ent:
        raise HTTPException(404, "Entity not found")
    data = body.model_dump(exclude_unset=True)
    for k, v in data.items():
        setattr(ent, k, v)
    # Recompute external key if type/name changed
    if "name" in data or "entity_type" in data:
        ent.external_key = external_key(ent.site_id, ent.entity_type, ent.name)
    ent.version = (ent.version or 1) + 1
    ent.last_updated = utcnow()
    ent.source = "manual" if ent.source == "manual" else ent.source
    db.add(
        EntityVersion(
            entity_id=ent.id,
            version=ent.version,
            snapshot_json=snapshot_entity(ent),
            change_source="manual_edit",
        )
    )
    db.commit()
    db.refresh(ent)
    return ent


@router.post("/api/entities/{entity_id}/verify", response_model=EntityOut)
def verify_entity(entity_id: str, body: VerifyAction, db: Session = Depends(get_db)):
    ent = db.get(Entity, entity_id)
    if not ent:
        raise HTTPException(404, "Entity not found")
    status_map = {"approve": "approved", "reject": "rejected", "needs_edit": "needs_edit"}
    ent.status = status_map[body.action]
    ent.last_updated = utcnow()
    ent.version = (ent.version or 1) + 1
    db.add(
        EntityVersion(
            entity_id=ent.id,
            version=ent.version,
            snapshot_json=snapshot_entity(ent),
            change_source=f"verify_{body.action}",
        )
    )
    db.commit()
    db.refresh(ent)
    return ent


@router.post("/api/entities/bulk-verify")
def bulk_verify(body: BulkVerify, db: Session = Depends(get_db)):
    status_map = {"approve": "approved", "reject": "rejected", "needs_edit": "needs_edit"}
    new_status = status_map[body.action]
    updated = 0
    for eid in body.entity_ids:
        ent = db.get(Entity, eid)
        if not ent:
            continue
        ent.status = new_status
        ent.last_updated = utcnow()
        ent.version = (ent.version or 1) + 1
        db.add(
            EntityVersion(
                entity_id=ent.id,
                version=ent.version,
                snapshot_json=snapshot_entity(ent),
                change_source=f"bulk_verify_{body.action}",
            )
        )
        updated += 1
    db.commit()
    return {"updated": updated}


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
    db.add(
        EntityVersion(
            entity_id=ent.id,
            version=1,
            snapshot_json=snapshot_entity(ent),
            change_source="manual_create",
        )
    )
    db.commit()
    db.refresh(ent)
    return ent


@router.post("/api/sites/{site_id}/entities/from-text", response_model=list[EntityOut])
def entities_from_text(site_id: str, body: FreeTextConvert, db: Session = Depends(get_db)):
    site = db.get(Site, site_id)
    if not site:
        raise HTTPException(404, "Site not found")
    extracted = convert_free_text(
        body.text,
        base_url=site.base_url,
        default_entity_type=body.default_entity_type,
    )
    if body.approve_immediately:
        # create approved via direct insert after merge as pending then approve
        pass
    apply_extracted(db, site_id, extracted, scan_job_id=None, is_rescan=False)
    # Fetch the entities we just created/updated by names
    names = {e.name for e in extracted}
    results = (
        db.query(Entity)
        .filter(Entity.site_id == site_id, Entity.name.in_(list(names)))
        .all()
    )
    if body.approve_immediately:
        for ent in results:
            ent.status = "approved"
            ent.source = "manual"
        db.commit()
        for ent in results:
            db.refresh(ent)
    return results


@router.delete("/api/entities/{entity_id}")
def delete_entity(entity_id: str, db: Session = Depends(get_db)):
    ent = db.get(Entity, entity_id)
    if not ent:
        raise HTTPException(404, "Entity not found")
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
