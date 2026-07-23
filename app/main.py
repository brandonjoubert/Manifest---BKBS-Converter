"""Manifest BKBS Converter — FastAPI application."""

from __future__ import annotations

import json
from pathlib import Path

from fastapi import Depends, FastAPI, Form, Request
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from sqlalchemy import func
from sqlalchemy.orm import Session

from app.api import entities as entities_api
from app.api import exports as exports_api
from app.api import scans as scans_api
from app.api import settings as settings_api
from app.api import sites as sites_api
from app.api.entities import parse_properties_field
from app.api.sites import site_to_out
from app.config import settings
from app.constants import ENTITY_TYPE_LABELS, ENTITY_TYPES, STATUS_LABELS
from app.db import get_db, init_db
from app.models import Entity, EntityVersion, Export, ScanJob, Site, utcnow
from app.services.export_package import create_export_package
from app.services.extractor_llm import convert_free_text
from app.services.llm_settings import (
    PROVIDER_PRESETS,
    public_llm_status,
    resolve_llm_config,
    save_llm_settings,
    test_llm_connection,
)
from app.services.merger import apply_extracted, external_key, snapshot_entity
from app.services.publish_live import (
    publish_site_live,
    resolve_publish_root,
    suggested_local_publish_root,
)
from app.services.scan_runner import enqueue_scan
from app.services.site_ops import delete_site_and_data

APP_DIR = Path(__file__).resolve().parent

app = FastAPI(
    title="Manifest BKBS Converter",
    description="Manifest BKBS Converter — scan websites into Business Knowledge Base Standard packages for AI agents.",
    version="1.0.0",
)

app.mount("/static", StaticFiles(directory=str(APP_DIR / "static")), name="static")
templates = Jinja2Templates(directory=str(APP_DIR / "templates"))

app.include_router(sites_api.router)
app.include_router(scans_api.router)
app.include_router(entities_api.router)
app.include_router(exports_api.router)
app.include_router(settings_api.router)


@app.on_event("startup")
def on_startup():
    init_db()


def llm_template_context(db: Session) -> dict:
    cfg = resolve_llm_config(db)
    status = public_llm_status(db)
    return {
        "has_llm": cfg.is_configured,
        "llm_status": status,
        "llm_provider_label": status.get("provider_label") or "LLM",
    }


def flash_redirect(url: str, message: str = "", error: str = "") -> RedirectResponse:
    # Simple query-param flash
    sep = "&" if "?" in url else "?"
    if message:
        url = f"{url}{sep}msg={message}"
    elif error:
        url = f"{url}{sep}err={error}"
    return RedirectResponse(url, status_code=303)


# ─── UI ───────────────────────────────────────────────────────────────────────


@app.get("/", response_class=HTMLResponse)
def dashboard(request: Request, db: Session = Depends(get_db)):
    sites = db.query(Site).order_by(Site.created_at.desc()).all()
    site_rows = [site_to_out(db, s) for s in sites]
    ctx = {
        "sites": site_rows,
        "msg": request.query_params.get("msg"),
        "err": request.query_params.get("err"),
    }
    ctx.update(llm_template_context(db))
    return templates.TemplateResponse(request, "dashboard.html", ctx)


@app.post("/sites")
def ui_create_site(
    name: str = Form(...),
    base_url: str = Form(...),
    max_pages: int = Form(40),
    crawl_delay_ms: int = Form(300),
    publish_root: str = Form(""),
    auto_publish: str | None = Form(None),
    db: Session = Depends(get_db),
):
    base_url = base_url.strip()
    if not base_url.startswith(("http://", "https://")):
        base_url = "https://" + base_url
    base_url = base_url.rstrip("/")
    site = Site(
        name=name.strip(),
        base_url=base_url,
        max_pages=max(1, min(max_pages, 500)),
        crawl_delay_ms=max(0, min(crawl_delay_ms, 10_000)),
        publish_root=publish_root.strip() or None,
        auto_publish=auto_publish is not None,
    )
    db.add(site)
    db.commit()
    db.refresh(site)
    return RedirectResponse(f"/sites/{site.id}", status_code=303)


@app.get("/sites/{site_id}", response_class=HTMLResponse)
def site_detail(site_id: str, request: Request, db: Session = Depends(get_db)):
    site = db.get(Site, site_id)
    if not site:
        return RedirectResponse("/?err=Site+not+found", status_code=303)
    site_out = site_to_out(db, site)
    scans = (
        db.query(ScanJob)
        .filter(ScanJob.site_id == site_id)
        .order_by(ScanJob.created_at.desc())
        .limit(10)
        .all()
    )
    exports = (
        db.query(Export)
        .filter(Export.site_id == site_id)
        .order_by(Export.created_at.desc())
        .limit(10)
        .all()
    )
    status_counts = (
        db.query(Entity.status, func.count(Entity.id))
        .filter(Entity.site_id == site_id)
        .group_by(Entity.status)
        .all()
    )
    resolved_root = resolve_publish_root(site)
    local_hint = str(suggested_local_publish_root())
    return templates.TemplateResponse(
        request,
        "site_detail.html",
        {
            "site": site_out,
            "site_raw": site,
            "scans": scans,
            "exports": exports,
            "status_counts": dict(status_counts),
            "resolved_publish_root": str(resolved_root) if resolved_root else None,
            "local_publish_hint": local_hint,
            "msg": request.query_params.get("msg"),
            "err": request.query_params.get("err"),
        }
        | llm_template_context(db),
    )


@app.post("/sites/{site_id}/settings")
def ui_site_settings(
    site_id: str,
    name: str = Form(...),
    base_url: str = Form(...),
    max_pages: int = Form(40),
    crawl_delay_ms: int = Form(300),
    publish_root: str = Form(""),
    auto_publish: str | None = Form(None),
    db: Session = Depends(get_db),
):
    from urllib.parse import quote

    site = db.get(Site, site_id)
    if not site:
        return RedirectResponse("/?err=Site+not+found", status_code=303)
    base_url = base_url.strip()
    if not base_url.startswith(("http://", "https://")):
        base_url = "https://" + base_url
    site.name = name.strip()
    site.base_url = base_url.rstrip("/")
    site.max_pages = max(1, min(max_pages, 500))
    site.crawl_delay_ms = max(0, min(crawl_delay_ms, 10_000))
    site.publish_root = publish_root.strip() or None
    site.auto_publish = auto_publish is not None
    db.commit()
    return RedirectResponse(
        f"/sites/{site_id}?msg={quote('Site settings saved')}",
        status_code=303,
    )


@app.post("/sites/{site_id}/publish")
def ui_publish_live(site_id: str, db: Session = Depends(get_db)):
    from urllib.parse import quote

    site = db.get(Site, site_id)
    if not site:
        return RedirectResponse("/?err=Site+not+found", status_code=303)
    result = publish_site_live(db, site, include_pending=False)
    if not result.ok:
        return RedirectResponse(
            f"/sites/{site_id}?err={quote(result.error or 'Publish failed')}",
            status_code=303,
        )
    msg = quote(
        f"Published {result.entity_count} entities → {result.root} "
        f"({len(result.files_written)} files)"
    )
    return RedirectResponse(f"/sites/{site_id}?msg={msg}", status_code=303)


@app.post("/sites/{site_id}/delete")
def ui_delete_site(site_id: str, confirm_name: str = Form(""), db: Session = Depends(get_db)):
    site = db.get(Site, site_id)
    if not site:
        return RedirectResponse("/?err=Site+not+found", status_code=303)
    # Require typing the site name to confirm destructive delete
    if confirm_name.strip() != site.name:
        return RedirectResponse(
            f"/sites/{site_id}?err=Type+the+exact+site+name+to+confirm+delete",
            status_code=303,
        )
    name = delete_site_and_data(db, site)
    from urllib.parse import quote

    return RedirectResponse(
        f"/?msg={quote(f'Deleted site: {name}')}",
        status_code=303,
    )


@app.post("/sites/{site_id}/scan")
def ui_start_scan(site_id: str, db: Session = Depends(get_db)):
    site = db.get(Site, site_id)
    if not site:
        return RedirectResponse("/?err=Site+not+found", status_code=303)
    running = (
        db.query(ScanJob)
        .filter(ScanJob.site_id == site_id, ScanJob.status.in_(["queued", "running"]))
        .first()
    )
    if running:
        return RedirectResponse(f"/scans/{running.id}", status_code=303)
    job = ScanJob(site_id=site_id, status="queued")
    db.add(job)
    db.commit()
    db.refresh(job)
    enqueue_scan(job.id)
    return RedirectResponse(f"/scans/{job.id}", status_code=303)


@app.get("/scans/{job_id}", response_class=HTMLResponse)
def scan_status(job_id: str, request: Request, db: Session = Depends(get_db)):
    job = db.get(ScanJob, job_id)
    if not job:
        return RedirectResponse("/?err=Scan+not+found", status_code=303)
    site = db.get(Site, job.site_id)
    return templates.TemplateResponse(
        request,
        "scan_status.html",
        {
            "job": job,
            "site": site,
            "auto_refresh": job.status in ("queued", "running"),
        }
        | llm_template_context(db),
    )


@app.get("/sites/{site_id}/entities", response_class=HTMLResponse)
def ui_entities(
    site_id: str,
    request: Request,
    status: str | None = None,
    entity_type: str | None = None,
    q: str | None = None,
    db: Session = Depends(get_db),
):
    site = db.get(Site, site_id)
    if not site:
        return RedirectResponse("/?err=Site+not+found", status_code=303)
    query = db.query(Entity).filter(Entity.site_id == site_id)
    if status:
        query = query.filter(Entity.status == status)
    if entity_type:
        query = query.filter(Entity.entity_type == entity_type)
    if q:
        like = f"%{q}%"
        query = query.filter((Entity.name.ilike(like)) | (Entity.description.ilike(like)))
    entities = query.order_by(Entity.status, Entity.entity_type, Entity.name).limit(500).all()
    return templates.TemplateResponse(
        request,
        "entities.html",
        {
            "site": site,
            "entities": entities,
            "status": status or "",
            "entity_type": entity_type or "",
            "q": q or "",
            "entity_types": ENTITY_TYPES,
            "entity_type_labels": ENTITY_TYPE_LABELS,
            "status_labels": STATUS_LABELS,
            "msg": request.query_params.get("msg"),
            "err": request.query_params.get("err"),
        }
        | llm_template_context(db),
    )


@app.post("/entities/bulk-verify")
async def ui_bulk_verify(request: Request, db: Session = Depends(get_db)):
    form = await request.form()
    site_id = form.get("site_id")
    action = form.get("action", "approve")
    ids = form.getlist("entity_ids")
    status_map = {"approve": "approved", "reject": "rejected", "needs_edit": "needs_edit"}
    new_status = status_map.get(str(action), "approved")
    for eid in ids:
        ent = db.get(Entity, str(eid))
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
                change_source=f"ui_bulk_{action}",
            )
        )
    db.commit()
    return RedirectResponse(
        f"/sites/{site_id}/entities?msg=Updated+{len(ids)}+entities",
        status_code=303,
    )


@app.get("/entities/{entity_id}", response_class=HTMLResponse)
def ui_entity_edit(entity_id: str, request: Request, db: Session = Depends(get_db)):
    ent = db.get(Entity, entity_id)
    if not ent:
        return RedirectResponse("/?err=Entity+not+found", status_code=303)
    site = db.get(Site, ent.site_id)
    return templates.TemplateResponse(
        request,
        "entity_edit.html",
        {
            "entity": ent,
            "site": site,
            "entity_types": ENTITY_TYPES,
            "entity_type_labels": ENTITY_TYPE_LABELS,
            "status_labels": STATUS_LABELS,
            "properties_json": json.dumps(ent.properties or {}, indent=2),
            "relationships_json": json.dumps(ent.relationships or [], indent=2),
            "evidence_json": json.dumps(ent.evidence or [], indent=2),
            "msg": request.query_params.get("msg"),
            "err": request.query_params.get("err"),
        }
        | llm_template_context(db),
    )


@app.post("/entities/{entity_id}")
def ui_entity_save(
    entity_id: str,
    name: str = Form(...),
    entity_type: str = Form(...),
    description: str = Form(""),
    status: str = Form("pending"),
    trust_level: str = Form("medium"),
    notes: str = Form(""),
    properties_json: str = Form("{}"),
    relationships_json: str = Form("[]"),
    evidence_json: str = Form("[]"),
    db: Session = Depends(get_db),
):
    ent = db.get(Entity, entity_id)
    if not ent:
        return RedirectResponse("/?err=Entity+not+found", status_code=303)
    try:
        props = json.loads(properties_json or "{}")
        if not isinstance(props, dict):
            props = {}
    except json.JSONDecodeError:
        return RedirectResponse(
            f"/entities/{entity_id}?err=Invalid+properties+JSON", status_code=303
        )
    try:
        rels = json.loads(relationships_json or "[]")
        if not isinstance(rels, list):
            rels = []
    except json.JSONDecodeError:
        return RedirectResponse(
            f"/entities/{entity_id}?err=Invalid+relationships+JSON", status_code=303
        )
    try:
        evid = json.loads(evidence_json or "[]")
        if not isinstance(evid, list):
            evid = []
    except json.JSONDecodeError:
        return RedirectResponse(
            f"/entities/{entity_id}?err=Invalid+evidence+JSON", status_code=303
        )

    ent.name = name.strip()
    ent.entity_type = entity_type
    ent.description = description.strip() or None
    ent.status = status
    ent.trust_level = trust_level
    ent.notes = notes.strip() or None
    ent.properties = props
    ent.relationships = rels
    ent.evidence = evid
    ent.external_key = external_key(ent.site_id, ent.entity_type, ent.name)
    ent.version = (ent.version or 1) + 1
    ent.last_updated = utcnow()
    db.add(
        EntityVersion(
            entity_id=ent.id,
            version=ent.version,
            snapshot_json=snapshot_entity(ent),
            change_source="ui_edit",
        )
    )
    db.commit()
    return RedirectResponse(f"/entities/{entity_id}?msg=Saved", status_code=303)


@app.post("/entities/{entity_id}/verify")
def ui_verify(
    entity_id: str,
    action: str = Form(...),
    db: Session = Depends(get_db),
):
    ent = db.get(Entity, entity_id)
    if not ent:
        return RedirectResponse("/?err=Entity+not+found", status_code=303)
    status_map = {"approve": "approved", "reject": "rejected", "needs_edit": "needs_edit"}
    if action not in status_map:
        return RedirectResponse(f"/entities/{entity_id}?err=Bad+action", status_code=303)
    ent.status = status_map[action]
    ent.last_updated = utcnow()
    ent.version = (ent.version or 1) + 1
    db.add(
        EntityVersion(
            entity_id=ent.id,
            version=ent.version,
            snapshot_json=snapshot_entity(ent),
            change_source=f"ui_verify_{action}",
        )
    )
    db.commit()
    return RedirectResponse(
        f"/sites/{ent.site_id}/entities?msg=Entity+{action}d",
        status_code=303,
    )


@app.get("/sites/{site_id}/manual", response_class=HTMLResponse)
def ui_manual(site_id: str, request: Request, db: Session = Depends(get_db)):
    site = db.get(Site, site_id)
    if not site:
        return RedirectResponse("/?err=Site+not+found", status_code=303)
    return templates.TemplateResponse(
        request,
        "manual.html",
        {
            "site": site,
            "entity_types": ENTITY_TYPES,
            "entity_type_labels": ENTITY_TYPE_LABELS,
            "msg": request.query_params.get("msg"),
            "err": request.query_params.get("err"),
        }
        | llm_template_context(db),
    )


@app.post("/sites/{site_id}/manual")
def ui_manual_create(
    site_id: str,
    entity_type: str = Form(...),
    name: str = Form(...),
    description: str = Form(""),
    properties_json: str = Form("{}"),
    approve_immediately: str | None = Form(None),
    db: Session = Depends(get_db),
):
    site = db.get(Site, site_id)
    if not site:
        return RedirectResponse("/?err=Site+not+found", status_code=303)
    props = parse_properties_field(properties_json)
    key = external_key(site_id, entity_type, name.strip())
    existing = (
        db.query(Entity)
        .filter(Entity.site_id == site_id, Entity.external_key == key)
        .one_or_none()
    )
    if existing:
        return RedirectResponse(
            f"/sites/{site_id}/manual?err=Entity+already+exists",
            status_code=303,
        )
    status = "approved" if approve_immediately else "pending"
    ent = Entity(
        site_id=site_id,
        external_key=key,
        entity_type=entity_type,
        name=name.strip(),
        description=description.strip() or None,
        properties=props,
        relationships=[],
        evidence=[{"url": site.base_url, "snippet": "Manual entry", "kind": "manual"}],
        source="manual",
        status=status,
        trust_level="high",
    )
    db.add(ent)
    db.flush()
    db.add(
        EntityVersion(
            entity_id=ent.id,
            version=1,
            snapshot_json=snapshot_entity(ent),
            change_source="ui_manual",
        )
    )
    db.commit()
    return RedirectResponse(f"/entities/{ent.id}?msg=Created", status_code=303)


@app.post("/sites/{site_id}/manual/from-text")
def ui_manual_from_text(
    site_id: str,
    text: str = Form(...),
    default_entity_type: str = Form(""),
    approve_immediately: str | None = Form(None),
    db: Session = Depends(get_db),
):
    site = db.get(Site, site_id)
    if not site:
        return RedirectResponse("/?err=Site+not+found", status_code=303)
    try:
        extracted = convert_free_text(
            text,
            base_url=site.base_url,
            default_entity_type=default_entity_type or None,
            db=db,
        )
    except Exception as exc:
        return RedirectResponse(
            f"/sites/{site_id}/manual?err=AI+conversion+failed:+{str(exc)[:80]}",
            status_code=303,
        )
    apply_extracted(db, site_id, extracted, scan_job_id=None, is_rescan=False)
    if approve_immediately:
        names = [e.name for e in extracted]
        ents = db.query(Entity).filter(Entity.site_id == site_id, Entity.name.in_(names)).all()
        for ent in ents:
            ent.status = "approved"
            ent.source = "manual"
        db.commit()
    return RedirectResponse(
        f"/sites/{site_id}/entities?msg=Converted+{len(extracted)}+entities+from+text",
        status_code=303,
    )


@app.post("/sites/{site_id}/export")
def ui_export(
    site_id: str,
    include_pending: str | None = Form(None),
    db: Session = Depends(get_db),
):
    site = db.get(Site, site_id)
    if not site:
        return RedirectResponse("/?err=Site+not+found", status_code=303)
    draft = bool(include_pending)
    export = create_export_package(db, site, include_pending=draft)
    if site.auto_publish and not draft:
        publish_site_live(db, site, include_pending=False)
    return RedirectResponse(
        f"/api/exports/{export.id}/download",
        status_code=303,
    )


@app.get("/settings", response_class=HTMLResponse)
def ui_settings(request: Request, db: Session = Depends(get_db)):
    status = public_llm_status(db)
    return templates.TemplateResponse(
        request,
        "settings.html",
        {
            "status": status,
            "presets": PROVIDER_PRESETS,
            "msg": request.query_params.get("msg"),
            "err": request.query_params.get("err"),
            "test_result": request.query_params.get("test"),
        }
        | llm_template_context(db),
    )


@app.post("/settings/llm")
def ui_save_llm_settings(
    provider: str = Form("custom"),
    api_key: str = Form(""),
    base_url: str = Form(""),
    model: str = Form(""),
    enabled: str | None = Form(None),
    clear_key: str | None = Form(None),
    db: Session = Depends(get_db),
):
    from urllib.parse import quote

    try:
        save_llm_settings(
            db,
            provider=provider,
            api_key=api_key if api_key.strip() else None,
            base_url=base_url,
            model=model,
            enabled=enabled is not None,
            clear_key=clear_key is not None,
        )
    except Exception as exc:
        return RedirectResponse(
            f"/settings?err={quote(str(exc)[:120])}",
            status_code=303,
        )
    return RedirectResponse(
        f"/settings?msg={quote('LLM settings saved')}",
        status_code=303,
    )


@app.post("/settings/llm/test")
def ui_test_llm(db: Session = Depends(get_db)):
    from urllib.parse import quote

    result = test_llm_connection(db)
    if result.get("ok"):
        preview = quote((result.get("response_preview") or "ok")[:80])
        return RedirectResponse(
            f"/settings?msg={quote('Connection OK')}&test={preview}",
            status_code=303,
        )
    err = quote((result.get("error") or "Connection failed")[:200])
    return RedirectResponse(f"/settings?err={err}", status_code=303)


@app.get("/health")
def health(db: Session = Depends(get_db)):
    cfg = resolve_llm_config(db)
    return {
        "ok": True,
        "llm_configured": cfg.is_configured,
        "llm_provider": cfg.provider if cfg.is_configured else None,
        "llm_model": cfg.model if cfg.is_configured else None,
        "llm_source": cfg.source,
    }
