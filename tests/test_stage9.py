"""Claim Ledger Stage 9: optional host capability layer (card, MCP, /ask, A2A)."""

from __future__ import annotations

import json
from pathlib import Path

from fastapi import FastAPI
from fastapi.testclient import TestClient
from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker

from app.db import get_db
from app.exports.agent_json import build_agent_json
from app.models import Base, Entity, Site
from app.schemas import ExtractedEntity
from app.services.capability import build_agent_card, search_approved
from app.services.claim_writer import apply_human_decision, insert_pending_claim
from app.services.merger import apply_extracted

ROOT = Path(__file__).resolve().parents[1]
CONTRACT = json.loads(
    (ROOT / "test-fixtures" / "stage9_capability_contract.json").read_text(encoding="utf-8")
)
TOKEN = "stage9-test-token"


def _session(tmp_path):
    eng = create_engine(f"sqlite:///{tmp_path / 's9.db'}", future=True)
    Base.metadata.create_all(bind=eng)
    return sessionmaker(bind=eng, future=True)()


def _client(db, monkeypatch):
    monkeypatch.setenv("BKBS_API_TOKEN", TOKEN)
    from app.api import capability as capability_api

    app = FastAPI()
    app.include_router(capability_api.router)

    def _db():
        yield db

    app.dependency_overrides[get_db] = _db
    return TestClient(app)


def _auth():
    return {"Authorization": f"Bearer {TOKEN}"}


def _seed(db, *, enabled: bool):
    site = Site(
        name="Acme",
        base_url="https://acme.example",
        max_pages=3,
        capability_enabled=enabled,
    )
    db.add(site)
    db.commit()
    apply_extracted(
        db,
        site.id,
        [
            ExtractedEntity(
                entity_type="business_identity",
                name="Acme Phone",
                description="Public desk",
                properties={"phone": "555-0100"},
                source="scan",
            )
        ],
        "job9",
    )
    ent = db.query(Entity).one()
    apply_human_decision(db, ent, "approve", approved_by="test")
    ent.notes = "SECRET PENDING"
    insert_pending_claim(
        db,
        entity_id=ent.id,
        entity_type=ent.entity_type,
        attribute="internal_note",
        value="SECRET PENDING",
    )
    db.commit()
    db.refresh(site)
    return site


def test_agent_json_ignores_capability_flag():
    class S:
        name = "Acme"
        base_url = "https://acme.example"

    off = build_agent_json(S(), [])
    on = build_agent_json(S(), [])
    assert off == on
    assert set(off) == {"name", "url", "knowledge"}
    assert "protocol" not in off
    assert "skills" not in off


def test_card_shape_matches_contract():
    card = build_agent_card("Acme", "https://host/sites/1/a2a")
    assert list(card) == CONTRACT["card_keys"]
    assert card["protocolVersion"] == CONTRACT["protocolVersion"]
    assert card["skills"][0]["id"] == CONTRACT["skill_id"]
    assert card["url"].endswith("/a2a")
    assert "knowledge" not in card
    assert card["securitySchemes"]["bearer"]["scheme"] == "bearer"


def test_search_drops_notes_and_pending_text():
    class E:
        id = "e1"
        entity_type = "business_identity"
        name = "Acme Phone"
        description = "Public desk"
        properties = {"phone": "555-0100"}
        notes = "SECRET PENDING"

    assert search_approved([E()], "SECRET") == []
    found = search_approved([E()], "555-0100")
    assert len(found) == 1
    assert "notes" not in found[0]
    assert "SECRET" not in json.dumps(found[0])


def test_off_is_404(tmp_path, monkeypatch):
    db = _session(tmp_path)
    site = _seed(db, enabled=False)
    client = _client(db, monkeypatch)
    base = f"/sites/{site.id}"
    assert client.get(f"{base}/.well-known/agent-card.json").status_code == 404
    assert client.post(f"{base}/ask", json={"query": "Acme"}, headers=_auth()).status_code == 404
    assert client.post(f"{base}/mcp", json={"jsonrpc": "2.0", "id": 1, "method": "tools/list"}, headers=_auth()).status_code == 404
    assert client.post(f"{base}/a2a", json={"jsonrpc": "2.0", "id": 1, "method": "message/send"}, headers=_auth()).status_code == 404


def test_on_answers_approved_only(tmp_path, monkeypatch):
    db = _session(tmp_path)
    site = _seed(db, enabled=True)
    client = _client(db, monkeypatch)
    base = f"/sites/{site.id}"

    card = client.get(f"{base}/.well-known/agent-card.json")
    assert card.status_code == 200
    body = card.json()
    assert list(body) == CONTRACT["card_keys"]
    assert body["url"].endswith(f"/sites/{site.id}/a2a")
    assert "SECRET" not in card.text

    noauth = client.post(f"{base}/ask", json={"query": "Acme"})
    assert noauth.status_code == 401

    secret = client.post(f"{base}/ask", json={"query": "SECRET"}, headers=_auth())
    assert secret.status_code == 200
    assert secret.json()["results"] == []

    hit = client.post(f"{base}/ask", json={"query": "555-0100"}, headers=_auth())
    assert hit.status_code == 200
    dumped = hit.text
    assert "555-0100" in dumped
    assert "SECRET" not in dumped
    assert hit.json()["results"][0]["url"] == "https://acme.example"

    missing = client.post(f"{base}/ask", json={}, headers=_auth())
    assert missing.status_code == 400

    mcp = client.post(
        f"{base}/mcp",
        json={"jsonrpc": "2.0", "id": 7, "method": "tools/list"},
        headers=_auth(),
    )
    names = [t["name"] for t in mcp.json()["result"]["tools"]]
    assert names == CONTRACT["mcp_tools"]

    called = client.post(
        f"{base}/mcp",
        json={
            "jsonrpc": "2.0",
            "id": 8,
            "method": "tools/call",
            "params": {"name": "search_approved", "arguments": {"query": "SECRET"}},
        },
        headers=_auth(),
    )
    payload = json.loads(called.json()["result"]["content"][0]["text"])
    assert payload["results"] == []

    a2a = client.post(
        f"{base}/a2a",
        json={
            "jsonrpc": "2.0",
            "id": 9,
            "method": "message/send",
            "params": {
                "message": {
                    "role": "user",
                    "messageId": "m1",
                    "parts": [{"kind": "text", "text": "phone"}],
                }
            },
        },
        headers=_auth(),
    )
    result = a2a.json()["result"]
    assert result["status"]["state"] == "completed"
    parts = result["artifacts"][0]["parts"]
    assert "555-0100" in json.dumps(parts[1]["data"])
    assert "SECRET" not in parts[0]["text"]


def test_publish_paths_do_not_write_agent_card():
    needle = CONTRACT["publish_must_not_contain"]
    for rel in (
        "app/services/publish_live.py",
        "php/src/Publisher.php",
        "wordpress-plugin/manifest-bkbs-converter/includes/class-mbkbs-publisher.php",
    ):
        text = (ROOT / rel).read_text(encoding="utf-8")
        assert needle not in text, rel
