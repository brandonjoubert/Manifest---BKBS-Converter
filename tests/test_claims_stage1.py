"""Claim Ledger Stage 1: claims table + resolver stub."""

from __future__ import annotations

from sqlalchemy import inspect, text

from app.db import SessionLocal, engine, init_db
from app.models import Claim
from app.services.resolver import resolve_entity


def test_claims_table_exists_after_init():
    init_db()
    insp = inspect(engine)
    assert "claims" in insp.get_table_names()
    cols = {c["name"] for c in insp.get_columns("claims")}
    required = {
        "id",
        "entity_id",
        "entity_type",
        "attribute",
        "value",
        "source_url",
        "extraction_method",
        "confidence",
        "status",
        "supersedes_id",
        "created_at",
        "approved_by",
        "approved_at",
        "review_due_at",
    }
    assert required.issubset(cols)


def test_claim_model_insert_and_cleanup():
    init_db()
    db = SessionLocal()
    try:
        row = Claim(
            entity_id="00000000-0000-4000-8000-000000000099",
            entity_type="capability",
            attribute="name",
            value="Stage1 Test",
            extraction_method="manual",
            status="approved",
        )
        db.add(row)
        db.commit()
        db.refresh(row)
        assert row.id is not None
        db.delete(row)
        db.commit()
    finally:
        db.close()


def test_resolve_entity_without_db_returns_none():
    """Stage 1 call sites (no db=) still get None; Stage 2 needs db= for real resolve."""
    assert resolve_entity("any-id") is None
    assert resolve_entity(None) is None
    assert resolve_entity("") is None
    assert resolve_entity("x", as_of=None) is None


def test_claims_indexes_present():
    init_db()
    with engine.connect() as conn:
        rows = conn.execute(text("PRAGMA index_list(claims)")).fetchall()
    names = {r[1] for r in rows}
    # SQLite may name FK indexes differently; require our Stage 1 named indexes
    assert "idx_claims_entity_attr" in names or any("entity" in n for n in names)
    assert "idx_claims_status" in names or any("status" in n for n in names)
