"""Claim Ledger Stage 2: backfill + real resolve + property round-trip."""

from __future__ import annotations

from sqlalchemy import create_engine, select
from sqlalchemy.orm import sessionmaker

from app.models import Base, Claim, Entity, Site
from app.services.claim_codec import (
    decode_claim_value,
    encode_claim_value,
    entity_attribute_pairs,
    prop_attribute,
)
from app.services.resolver import resolve_entity
from scripts.backfill_claims import backfill_entity, backfill_site


def _session(tmp_path):
    eng = create_engine(f"sqlite:///{tmp_path / 's2.db'}", future=True)
    Base.metadata.create_all(bind=eng)
    return sessionmaker(bind=eng, future=True)()


def _seed(db):
    site = Site(
        id="site-s2",
        name="S2 Site",
        base_url="https://s2.example",
        max_pages=5,
        crawl_delay_ms=0,
        auto_publish=False,
    )
    db.add(site)
    ent = Entity(
        id="ent-s2-1",
        site_id="site-s2",
        external_key="s2-id",
        entity_type="business_identity",
        name="Stage2 Co",
        description="A test business",
        properties={"email": "a@b.c", "response_hours": 24},
        relationships=[{"predicate": "serves", "target_name": "Warehouses"}],
        evidence=[{"url": "https://s2.example/", "snippet": "Stage2", "kind": "test"}],
        version=1,
        trust_level="high",
        source="fixture",
        status="approved",
    )
    db.add(ent)
    pending = Entity(
        id="ent-s2-pending",
        site_id="site-s2",
        external_key="s2-pending",
        entity_type="capability",
        name="Pending Cap",
        description="pending only",
        properties={},
        relationships=[],
        evidence=[],
        status="pending",
        source="scan",
    )
    db.add(pending)
    db.commit()
    return ent


def test_encode_decode_roundtrip():
    assert encode_claim_value("hello") == "hello"
    assert decode_claim_value("hello") == "hello"
    assert decode_claim_value(encode_claim_value(24)) == 24
    assert decode_claim_value(encode_claim_value({"a": 1})) == {"a": 1}
    assert decode_claim_value(encode_claim_value([{"x": "y"}])) == [{"x": "y"}]


def test_entity_attribute_pairs_include_prop_keys():
    class E:
        name = "N"
        description = "D"
        properties = {"email": "x@y.z"}
        relationships = []
        evidence = []
        trust_level = "high"
        source = "fixture"
        status = "approved"

    pairs = dict(entity_attribute_pairs(E()))
    assert pairs["name"] == "N"
    assert pairs["description"] == "D"
    assert pairs[prop_attribute("email")] == "x@y.z"
    assert "relationships" in pairs
    assert "evidence" in pairs


def test_backfill_and_resolve_roundtrip(tmp_path):
    db = _session(tmp_path)
    ent = _seed(db)
    stats = backfill_entity(db, ent, dry_run=False, update=False)
    db.commit()
    assert stats["inserted"] >= 5  # name, desc, props, rel, evid, trust/source/status
    assert stats["skipped"] == 0

    # Idempotent second run
    stats2 = backfill_entity(db, ent, dry_run=False, update=False)
    db.commit()
    assert stats2["inserted"] == 0
    assert stats2["skipped"] >= stats["inserted"]

    resolved = resolve_entity(ent.id, db=db)
    assert resolved is not None
    assert resolved.name == ent.name
    assert resolved.description == ent.description
    assert resolved.properties == ent.properties
    assert resolved.relationships == ent.relationships
    assert resolved.evidence == ent.evidence
    assert resolved.trust_level == ent.trust_level
    assert resolved.source == ent.source
    assert resolved.status == ent.status
    assert resolved.entity_type == ent.entity_type
    assert resolved.external_key == ent.external_key
    assert resolved.version == ent.version
    assert resolved.id == ent.id


def test_backfill_approved_only_by_default(tmp_path):
    db = _session(tmp_path)
    _seed(db)
    totals = backfill_site(db, "site-s2", include_pending=False)
    assert totals["entities"] == 1
    # Pending entity should have no claims
    n = db.scalar(
        select(Claim).where(Claim.entity_id == "ent-s2-pending").limit(1)
    )
    # scalar with limit returns Claim or we use count
    count_pending = len(
        list(db.scalars(select(Claim).where(Claim.entity_id == "ent-s2-pending")).all())
    )
    assert count_pending == 0
    assert n is None or count_pending == 0


def test_resolve_without_db_returns_none():
    assert resolve_entity("any") is None
    assert resolve_entity(None) is None
    assert resolve_entity("") is None


def test_resolve_unknown_entity_returns_none(tmp_path):
    db = _session(tmp_path)
    assert resolve_entity("does-not-exist", db=db) is None


def test_resolve_entity_fallback_without_claims(tmp_path):
    db = _session(tmp_path)
    ent = _seed(db)
    # No backfill — still returns envelope from entity columns
    resolved = resolve_entity(ent.id, db=db)
    assert resolved is not None
    assert resolved.name == ent.name
    assert resolved.properties == ent.properties


def test_backfill_update_supersedes(tmp_path):
    db = _session(tmp_path)
    ent = _seed(db)
    backfill_entity(db, ent)
    db.commit()
    ent.name = "Renamed Co"
    db.commit()
    stats = backfill_entity(db, ent, update=True)
    db.commit()
    assert stats["superseded"] >= 1
    approved = list(
        db.scalars(
            select(Claim).where(
                Claim.entity_id == ent.id,
                Claim.attribute == "name",
                Claim.status == "approved",
            )
        ).all()
    )
    assert len(approved) == 1
    assert approved[0].value == "Renamed Co"
    superseded = list(
        db.scalars(
            select(Claim).where(
                Claim.entity_id == ent.id,
                Claim.attribute == "name",
                Claim.status == "superseded",
            )
        ).all()
    )
    assert len(superseded) == 1
    resolved = resolve_entity(ent.id, db=db)
    assert resolved is not None
    assert resolved.name == "Renamed Co"
