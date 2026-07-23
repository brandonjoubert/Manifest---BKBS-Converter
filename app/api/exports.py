"""Export package API."""

from __future__ import annotations

from fastapi import APIRouter, Depends, HTTPException
from fastapi.responses import FileResponse
from sqlalchemy.orm import Session

from app.db import get_db
from app.models import Export, Site
from app.schemas import ExportOut, ExportRequest
from app.services.export_package import create_export_package, zip_path_for_export
from app.services.publish_live import publish_site_live

router = APIRouter(tags=["exports"])


@router.post("/api/sites/{site_id}/export", response_model=ExportOut)
def export_site(
    site_id: str,
    body: ExportRequest | None = None,
    db: Session = Depends(get_db),
):
    site = db.get(Site, site_id)
    if not site:
        raise HTTPException(404, "Site not found")
    include_pending = body.include_pending if body else False
    export = create_export_package(db, site, include_pending=include_pending)
    if getattr(site, "auto_publish", False) and not include_pending:
        publish_site_live(db, site, include_pending=False)
    return export


@router.post("/api/sites/{site_id}/publish")
def publish_site(site_id: str, db: Session = Depends(get_db)):
    site = db.get(Site, site_id)
    if not site:
        raise HTTPException(404, "Site not found")
    result = publish_site_live(db, site, include_pending=False)
    if not result.ok:
        raise HTTPException(400, result.error or "Publish failed")
    return {
        "ok": True,
        "root": result.root,
        "files_written": result.files_written,
        "entity_count": result.entity_count,
    }


@router.get("/api/sites/{site_id}/exports", response_model=list[ExportOut])
def list_exports(site_id: str, db: Session = Depends(get_db)):
    site = db.get(Site, site_id)
    if not site:
        raise HTTPException(404, "Site not found")
    return (
        db.query(Export)
        .filter(Export.site_id == site_id)
        .order_by(Export.created_at.desc())
        .limit(50)
        .all()
    )


@router.get("/api/exports/{export_id}/download")
def download_export(export_id: str, db: Session = Depends(get_db)):
    export = db.get(Export, export_id)
    if not export:
        raise HTTPException(404, "Export not found")
    try:
        zpath = zip_path_for_export(export)
    except FileNotFoundError as exc:
        raise HTTPException(404, str(exc)) from exc
    return FileResponse(
        path=str(zpath),
        filename=f"bkbs-export-{export.id[:8]}.zip",
        media_type="application/zip",
    )
