"""Stage 9 host routes. 404 when the site has the capability layer off."""

from __future__ import annotations

import json

from fastapi import APIRouter, Depends, HTTPException, Request
from fastapi.responses import JSONResponse
from sqlalchemy.orm import Session

from app.db import get_db
from app.models import Site
from app.services.api_auth import require_api_token
from app.services.capability import (
    answer_a2a,
    answer_ask,
    answer_mcp,
    build_agent_card,
    capability_enabled,
)
from app.services.resolver import resolve_site

router = APIRouter(tags=["capability"])


def _site_or_404(db: Session, site_id: str) -> Site:
    site = db.get(Site, site_id)
    if not site or not capability_enabled(site):
        raise HTTPException(404, "Not found")
    return site


def _public(db: Session, site_id: str) -> list:
    return resolve_site(db, site_id, include_pending=False)


async def _json_body(request: Request) -> dict | None:
    raw = await request.body()
    if not raw:
        return {}
    try:
        data = json.loads(raw)
    except json.JSONDecodeError:
        return None
    if not isinstance(data, dict):
        return None
    return data


def _endpoint(request: Request, site_id: str) -> str:
    return str(request.base_url).rstrip("/") + f"/sites/{site_id}/a2a"


@router.get("/sites/{site_id}/.well-known/agent-card.json")
def agent_card(site_id: str, request: Request, db: Session = Depends(get_db)):
    """Discovery document. No claims. 404 when the layer is off."""
    site = _site_or_404(db, site_id)
    return build_agent_card(site.name, _endpoint(request, site.id))


@router.post("/sites/{site_id}/ask")
async def ask(site_id: str, request: Request, db: Session = Depends(get_db)):
    site = _site_or_404(db, site_id)
    require_api_token(request)
    body = await _json_body(request)
    if body is None or "query" not in body:
        return JSONResponse({"error": "query required"}, status_code=400)
    query = body.get("query")
    if not isinstance(query, str):
        return JSONResponse({"error": "query required"}, status_code=400)
    status, payload = answer_ask(_public(db, site.id), query, site.base_url)
    return JSONResponse(payload, status_code=status)


@router.post("/sites/{site_id}/mcp")
async def mcp(site_id: str, request: Request, db: Session = Depends(get_db)):
    site = _site_or_404(db, site_id)
    require_api_token(request)
    body = await _json_body(request)
    status, payload = answer_mcp(_public(db, site.id), body)
    return JSONResponse(payload, status_code=status)


@router.post("/sites/{site_id}/a2a")
async def a2a(site_id: str, request: Request, db: Session = Depends(get_db)):
    site = _site_or_404(db, site_id)
    require_api_token(request)
    body = await _json_body(request)
    status, payload = answer_a2a(_public(db, site.id), body)
    return JSONResponse(payload, status_code=status)
