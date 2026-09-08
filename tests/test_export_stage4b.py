"""Claim Ledger Stage 4b: adapters are the production builders; shims and goldens hold."""

from __future__ import annotations

from types import SimpleNamespace

from app.exports.agent_json import build_agent_json
from app.exports.base import ResolvedEntity
from app.exports.llms_txt import render_llms_txt
from app.services import export_jsonld, export_llms
from app.services.resolved_entity import ResolvedEntity as ServiceResolvedEntity


def test_resolved_entity_is_one_type():
    assert ResolvedEntity is ServiceResolvedEntity


def test_shims_reexport_adapters():
    assert export_llms.render_llms_txt is render_llms_txt
    assert export_jsonld.build_agent_json is build_agent_json


def test_agent_json_is_knowledge_index():
    site = SimpleNamespace(name="Acme", base_url="https://acme.example")
    cap = SimpleNamespace(
        id="c1",
        entity_type="capability",
        name="Install CCTV",
        description="Cameras",
    )
    data = build_agent_json(site, [cap])
    assert "protocol" not in data
    assert "endpoint" not in data
    assert "capabilities" not in data
    assert data["knowledge"]["llms_txt"] == "https://acme.example/llms.txt"
    assert data["knowledge"]["schema_organization"].endswith("/schema/organization.jsonld")


def test_adapter_accepts_resolved_entity():
    site = SimpleNamespace(name="Acme", base_url="https://acme.example", id="s1")
    ent = ResolvedEntity(
        id="e1",
        entity_type="capability",
        name="Gate Motors",
        description="Motors",
        evidence=[{"url": "https://acme.example/gates"}],
        status="approved",
    )
    text = render_llms_txt(site, [ent])
    assert "Gate Motors" in text
    assert "https://acme.example/gates" in text
