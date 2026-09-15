"""Claim Ledger Stage 7: authenticated query API, as_of, claims, approved_at."""

from __future__ import annotations

from datetime import datetime, timedelta, timezone

from fastapi import FastAPI
from fastapi.testclient import TestClient
from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker

from app.db import get_db
from app.models import Base, Claim, Entity, Site
from app.schemas import ExtractedEntity
from app.services.api_auth import require_api_token, tokens_match
from app.services.claim_writer import apply_human_decision, insert_approved_claim
from app.services.merger import apply_extracted
from app.services.resolver import resolve_entity


TOKEN = "stage7-test-token"


def _session(tmp_path):
    eng = create_engine(f"sqlite:///{tmp_path / 's7.db'}", future=True)
    Base.metadata.create_all(bind=eng)
    return sessionmaker(bind=eng, future=True)()


def _client(db, monkeypatch):
    monkeypatch.setenv("BKBS_API_TOKEN", TOKEN)
    from app.api import entities as entities_api

    app = FastAPI()
    app.include_router(entities_api.router)

    def _db():
        yield db

    app.dependency_overrides[get_db] = _db
    return TestClient(app)


def _auth():
    return {"Authorization": f"Bearer {TOKEN}"}


def _seed_approved(db, tmp_path=None):
    site = Site(name="S7 Co", base_url="https://s7.example", max_pages=3)
    db.add(site)
    db.commit()
    apply_extracted(
        db,
        site.id,
        [
            ExtractedEntity(
                entity_type="business_identity",
                name="S7 Co",
                description="First approved",
                source="scan",
            )
        ],
        "job1",
    )
    ent = db.query(Entity).one()
    apply_human_decision(db, ent, "approve", approved_by="test")
    db.commit()
    db.refresh(ent)
    return site, ent


def test_tokens_match_rejects_wrong_and_empty():
    assert tokens_match("abc", "abc")
    assert not tokens_match("abc", "abd")
    assert not tokens_match("", "abc")
    assert not tokens_match("abc", "")


def test_approve_sets_approved_at(tmp_path):
    db = _session(tmp_path)
    _site, ent = _seed_approved(db)
    rows = db.query(Claim).filter(Claim.entity_id == ent.id, Claim.status == "approved").all()
    assert rows
    assert all(c.approved_at is not None for c in rows)


def test_as_of_uses_approved_at(tmp_path):
    db = _session(tmp_path)
    site, ent = _seed_approved(db)
    t1 = datetime(2026, 1, 1, 12, 0, tzinfo=timezone.utc)
    t2 = datetime(2026, 6, 1, 12, 0, tzinfo=timezone.utc)
    for c in db.query(Claim).filter(Claim.entity_id == ent.id, Claim.status == "approved"):
        c.approved_at = t1
        c.created_at = t1
    db.commit()
    insert_approved_claim(
        db,
        entity_id=ent.id,
        entity_type=ent.entity_type,
        attribute="description",
        value='"Second approved"',
        extraction_method="manual",
        approved_by="test",
        created_at=t2,
    )
    db.commit()
    early = resolve_entity(ent.id, as_of=t1 + timedelta(days=1), db=db)
    late = resolve_entity(ent.id, as_of=t2 + timedelta(days=1), db=db)
    now = resolve_entity(ent.id, db=db)
    assert early is not None and early.description == "First approved"
    assert late is not None and late.description == "Second approved"
    assert now is not None and now.description == "Second approved"


def test_unauthenticated_entity_is_401(tmp_path, monkeypatch):
    db = _session(tmp_path)
    _site, ent = _seed_approved(db)
    c = _client(db, monkeypatch)
    r = c.get(f"/api/entities/{ent.id}")
    assert r.status_code == 401
    assert "bearer" in (r.headers.get("www-authenticate") or "").lower()


def test_authenticated_entity_and_claims(tmp_path, monkeypatch):
    db = _session(tmp_path)
    _site, ent = _seed_approved(db)
    c = _client(db, monkeypatch)
    r = c.get(f"/api/entities/{ent.id}", headers=_auth())
    assert r.status_code == 200
    body = r.json()
    assert body["name"] == "S7 Co"
    assert body["description"] == "First approved"
    claims = c.get(f"/api/entities/{ent.id}/claims", headers=_auth())
    assert claims.status_code == 200
    rows = claims.json()
    assert len(rows) >= 1
    assert all(row.get("entity_id") == ent.id for row in rows)
    approved = [row for row in rows if row["status"] == "approved"]
    assert approved
    assert all(row.get("approved_at") for row in approved)


def test_as_of_query_param(tmp_path, monkeypatch):
    db = _session(tmp_path)
    _site, ent = _seed_approved(db)
    t1 = datetime(2026, 1, 1, 12, 0, tzinfo=timezone.utc)
    t2 = datetime(2026, 6, 1, 12, 0, tzinfo=timezone.utc)
    for c in db.query(Claim).filter(Claim.entity_id == ent.id, Claim.status == "approved"):
        c.approved_at = t1
        c.created_at = t1
    db.commit()
    insert_approved_claim(
        db,
        entity_id=ent.id,
        entity_type=ent.entity_type,
        attribute="description",
        value='"Second approved"',
        extraction_method="manual",
        approved_by="test",
        created_at=t2,
    )
    db.commit()
    client = _client(db, monkeypatch)
    early = client.get(
        f"/api/entities/{ent.id}",
        params={"as_of": "2026-02-01T00:00:00Z"},
        headers=_auth(),
    )
    late = client.get(
        f"/api/entities/{ent.id}",
        params={"as_of": "2026-07-01T00:00:00Z"},
        headers=_auth(),
    )
    assert early.status_code == 200
    assert early.json()["description"] == "First approved"
    assert late.status_code == 200
    assert late.json()["description"] == "Second approved"
    hist = client.get(
        f"/api/entities/{ent.id}/claims",
        params={"as_of": "2026-02-01T00:00:00Z"},
        headers=_auth(),
    )
    assert hist.status_code == 200
    descs = [row for row in hist.json() if row["attribute"] == "description"]
    assert descs
    assert all("Second" not in row["value"] for row in descs)


def test_x_api_key_accepted(tmp_path, monkeypatch):
    db = _session(tmp_path)
    _site, ent = _seed_approved(db)
    c = _client(db, monkeypatch)
    r = c.get(f"/api/entities/{ent.id}", headers={"X-API-Key": TOKEN})
    assert r.status_code == 200
    assert r.json()["name"] == "S7 Co"


def test_require_api_token_importable():
    assert callable(require_api_token)
