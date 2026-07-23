"""Scan job API routes."""

from __future__ import annotations

from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session

from app.db import get_db
from app.models import ScanJob, Site
from app.schemas import ScanJobOut
from app.services.scan_runner import enqueue_scan

router = APIRouter(tags=["scans"])


@router.post("/api/sites/{site_id}/scan", response_model=ScanJobOut)
def start_scan(site_id: str, db: Session = Depends(get_db)):
    site = db.get(Site, site_id)
    if not site:
        raise HTTPException(404, "Site not found")
    # Prevent stacking many running jobs for same site
    running = (
        db.query(ScanJob)
        .filter(ScanJob.site_id == site_id, ScanJob.status.in_(["queued", "running"]))
        .first()
    )
    if running:
        return running

    job = ScanJob(site_id=site_id, status="queued")
    db.add(job)
    db.commit()
    db.refresh(job)
    enqueue_scan(job.id)
    return job


@router.get("/api/sites/{site_id}/scans", response_model=list[ScanJobOut])
def list_scans(site_id: str, db: Session = Depends(get_db)):
    site = db.get(Site, site_id)
    if not site:
        raise HTTPException(404, "Site not found")
    return (
        db.query(ScanJob)
        .filter(ScanJob.site_id == site_id)
        .order_by(ScanJob.created_at.desc())
        .limit(50)
        .all()
    )


@router.get("/api/scans/{job_id}", response_model=ScanJobOut)
def get_scan(job_id: str, db: Session = Depends(get_db)):
    job = db.get(ScanJob, job_id)
    if not job:
        raise HTTPException(404, "Scan job not found")
    return job
