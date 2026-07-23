"""Orchestrate crawl → extract → merge for a scan job."""

from __future__ import annotations

import asyncio
import logging
from concurrent.futures import ThreadPoolExecutor

from app.db import SessionLocal
from app.models import Page, ScanJob, Site, utcnow
from app.services.crawler import crawl_site
from app.services.extractor_heuristic import extract_heuristic
from app.services.extractor_llm import extract_with_llm
from app.services.merger import apply_extracted, resolve_relationship_targets

logger = logging.getLogger(__name__)

# Shared executor so we don't block the event loop with LLM HTTP
_executor = ThreadPoolExecutor(max_workers=2)


def run_scan_job(job_id: str) -> None:
    """Synchronous scan pipeline (run in thread)."""
    db = SessionLocal()
    try:
        job = db.get(ScanJob, job_id)
        if not job:
            logger.error("Scan job %s not found", job_id)
            return
        site = db.get(Site, job.site_id)
        if not site:
            job.status = "failed"
            job.error = "Site not found"
            job.finished_at = utcnow()
            db.commit()
            return

        # Detect rescan: prior completed jobs exist
        prior = (
            db.query(ScanJob)
            .filter(
                ScanJob.site_id == site.id,
                ScanJob.status == "completed",
                ScanJob.id != job.id,
            )
            .count()
        )
        is_rescan = prior > 0

        job.status = "running"
        job.started_at = utcnow()
        job.error = None
        db.commit()

        try:
            crawl = asyncio.run(
                crawl_site(
                    base_url=site.base_url,
                    max_pages=site.max_pages,
                    crawl_delay_ms=site.crawl_delay_ms,
                )
            )
        except Exception as exc:
            logger.exception("Crawl failed")
            job.status = "failed"
            job.error = f"Crawl failed: {exc}"
            job.finished_at = utcnow()
            db.commit()
            return

        # Persist pages
        for p in crawl.pages:
            db.add(
                Page(
                    site_id=site.id,
                    scan_job_id=job.id,
                    url=p.url,
                    status_code=p.status_code,
                    title=p.title,
                    content_text=p.content_text,
                    json_ld_raw=p.json_ld,
                    meta_json=p.meta,
                )
            )
        job.pages_fetched = len(crawl.pages)
        db.commit()

        # Extract
        heuristic = extract_heuristic(crawl.pages)
        llm_entities, llm_raw = [], ""
        try:
            llm_entities, llm_raw = extract_with_llm(crawl.pages, site.base_url)
        except Exception as exc:
            logger.exception("LLM extraction failed")
            llm_raw = f"LLM error: {exc}"

        job.llm_raw = llm_raw[:500_000] if llm_raw else None
        combined = heuristic + llm_entities

        stats = apply_extracted(
            db,
            site_id=site.id,
            extracted=combined,
            scan_job_id=job.id,
            is_rescan=is_rescan,
        )
        resolve_relationship_targets(db, site.id)

        job.entities_found = stats.get("created", 0) + stats.get("updated", 0)
        job.stats_json = {
            "crawl": crawl.stats,
            "merge": stats,
            "heuristic_count": len(heuristic),
            "llm_count": len(llm_entities),
            "is_rescan": is_rescan,
        }
        job.status = "completed"
        job.finished_at = utcnow()
        db.commit()
        logger.info("Scan job %s completed: %s", job_id, job.stats_json)
    except Exception as exc:
        logger.exception("Scan job %s failed", job_id)
        try:
            job = db.get(ScanJob, job_id)
            if job:
                job.status = "failed"
                job.error = str(exc)
                job.finished_at = utcnow()
                db.commit()
        except Exception:
            pass
    finally:
        db.close()


def enqueue_scan(job_id: str) -> None:
    _executor.submit(run_scan_job, job_id)
