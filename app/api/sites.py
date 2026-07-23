"""Site API routes."""

from __future__ import annotations

from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy import func
from sqlalchemy.orm import Session

from app.db import get_db
from app.models import Entity, ScanJob, Site
from app.schemas import SiteCreate, SiteOut, SiteUpdate
from app.services.site_ops import delete_site_and_data

router = APIRouter(prefix="/api/sites", tags=["sites"])


def site_to_out(db: Session, site: Site) -> SiteOut:
    counts = (
        db.query(Entity.status, func.count(Entity.id))
        .filter(Entity.site_id == site.id)
        .group_by(Entity.status)
        .all()
    )
    by_status = {s: c for s, c in counts}
    total = sum(by_status.values())
    last = (
        db.query(ScanJob)
        .filter(ScanJob.site_id == site.id)
        .order_by(ScanJob.created_at.desc())
        .first()
    )
    return SiteOut(
        id=site.id,
        name=site.name,
        base_url=site.base_url,
        max_pages=site.max_pages,
        crawl_delay_ms=site.crawl_delay_ms,
        rescan_cron=site.rescan_cron,
        publish_root=getattr(site, "publish_root", None),
        auto_publish=True if getattr(site, "auto_publish", None) is None else bool(site.auto_publish),
        created_at=site.created_at,
        updated_at=site.updated_at,
        pending_count=by_status.get("pending", 0) + by_status.get("needs_edit", 0),
        approved_count=by_status.get("approved", 0),
        entity_count=total,
        last_scan_status=last.status if last else None,
    )


@router.get("", response_model=list[SiteOut])
def list_sites(db: Session = Depends(get_db)):
    sites = db.query(Site).order_by(Site.created_at.desc()).all()
    return [site_to_out(db, s) for s in sites]


@router.post("", response_model=SiteOut)
def create_site(body: SiteCreate, db: Session = Depends(get_db)):
    site = Site(
        name=body.name,
        base_url=body.base_url,
        max_pages=body.max_pages,
        crawl_delay_ms=body.crawl_delay_ms,
        publish_root=(body.publish_root or "").strip() or None,
        auto_publish=body.auto_publish,
    )
    db.add(site)
    db.commit()
    db.refresh(site)
    return site_to_out(db, site)


@router.get("/{site_id}", response_model=SiteOut)
def get_site(site_id: str, db: Session = Depends(get_db)):
    site = db.get(Site, site_id)
    if not site:
        raise HTTPException(404, "Site not found")
    return site_to_out(db, site)


@router.patch("/{site_id}", response_model=SiteOut)
def update_site(site_id: str, body: SiteUpdate, db: Session = Depends(get_db)):
    site = db.get(Site, site_id)
    if not site:
        raise HTTPException(404, "Site not found")
    data = body.model_dump(exclude_unset=True)
    if "base_url" in data and data["base_url"]:
        url = data["base_url"].strip()
        if not url.startswith(("http://", "https://")):
            url = "https://" + url
        data["base_url"] = url.rstrip("/")
    for k, v in data.items():
        setattr(site, k, v)
    db.commit()
    db.refresh(site)
    return site_to_out(db, site)


@router.delete("/{site_id}")
def delete_site(site_id: str, db: Session = Depends(get_db)):
    site = db.get(Site, site_id)
    if not site:
        raise HTTPException(404, "Site not found")
    name = delete_site_and_data(db, site)
    return {"ok": True, "deleted": name, "id": site_id}
