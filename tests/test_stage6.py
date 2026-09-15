"""Claim Ledger Stage 6: attributes live on claims; entity row is envelope-only."""

from __future__ import annotations

from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker

from app.models import Base, Claim, Entity, Site
from app.schemas import ExtractedEntity
from app.services.claim_writer import apply_human_decision, write_surface_claims
from app.services.merger import apply_extracted
from app.services.publish_live import publish_site_live
from app.services.resolver import resolve_entity, resolve_site
from app.services.site_ops import delete_site_and_data
from app.services.stage6 import null_attribute_columns, restore_attribute_columns_from_claims


def _session(tmp_path):
    eng = create_engine(f"sqlite:///{tmp_path / 's6.db'}", future=True)
    Base.metadata.create_all(bind=eng)
    return sessionmaker(bind=eng, future=True)()


def test_public_files_after_columns_nulled(tmp_path):
    db = _session(tmp_path)
    root = tmp_path / "public"
    root.mkdir()
    site = Site(
        name="S6 Co",
        base_url="https://s6.example",
        max_pages=5,
        auto_publish=True,
        publish_root=str(root),
    )
    db.add(site)
    db.commit()
    apply_extracted(
        db,
        site.id,
        [
            ExtractedEntity(
                entity_type="business_identity",
                name="S6 Co",
                description="Ledger firm",
                source="scan",
            )
        ],
        "job1",
    )
    ent = db.query(Entity).one()
    apply_human_decision(db, ent, "approve", approved_by="test")
    db.commit()
    n = null_attribute_columns(db)
    db.refresh(ent)
    assert (ent.name or "") == ""
    assert ent.description is None
    resolved = resolve_entity(ent.id, db=db)
    assert resolved is not None
    assert resolved.name == "S6 Co"
    result = publish_site_live(db, site)
    assert result.ok, result.error
    live = (root / "llms.txt").read_text(encoding="utf-8")
    assert "S6 Co" in live
    assert "Ledger firm" in live
    assert n >= 0


def test_manual_entity_resolves_without_columns(tmp_path):
    db = _session(tmp_path)
    site = Site(name="M", base_url="https://m.example", max_pages=3)
    db.add(site)
    db.commit()
    ent = Entity(
        site_id=site.id,
        external_key="man1",
        entity_type="capability",
        name="Manual Cap",
        description="From operator",
        source="manual",
        status="approved",
    )
    db.add(ent)
    db.flush()
    write_surface_claims(
        db, ent, claim_status="approved", extraction_method="manual", approved_by="test", surface=ent
    )
    ent.name = ""
    ent.description = None
    db.commit()
    resolved = resolve_entity(ent.id, db=db)
    assert resolved is not None
    assert resolved.name == "Manual Cap"
    assert resolved.description == "From operator"
    public = resolve_site(db, site.id)
    assert any(r.name == "Manual Cap" for r in public)


def test_delete_site_removes_claims(tmp_path):
    db = _session(tmp_path)
    site = Site(name="Del", base_url="https://del.example", max_pages=2)
    db.add(site)
    db.commit()
    apply_extracted(
        db,
        site.id,
        [ExtractedEntity(entity_type="capability", name="X", source="scan")],
        "j1",
    )
    assert db.query(Claim).count() >= 1
    delete_site_and_data(db, site)
    assert db.query(Entity).count() == 0
    assert db.query(Claim).count() == 0


def test_restore_drill_from_claims(tmp_path):
    db = _session(tmp_path)
    site = Site(name="R", base_url="https://r.example", max_pages=2)
    db.add(site)
    db.commit()
    apply_extracted(
        db,
        site.id,
        [
            ExtractedEntity(
                entity_type="capability",
                name="Restore Cap",
                description="Keep me",
                source="scan",
            )
        ],
        "j1",
    )
    ent = db.query(Entity).one()
    apply_human_decision(db, ent, "approve", approved_by="test")
    null_attribute_columns(db)
    db.refresh(ent)
    assert (ent.name or "") == ""
    restore_attribute_columns_from_claims(db)
    db.refresh(ent)
    assert ent.name == "Restore Cap"
    assert ent.description == "Keep me"
