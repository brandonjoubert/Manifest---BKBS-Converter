"""Claim Ledger Stage 3: claim-only scan merge."""

from __future__ import annotations

from sqlalchemy import create_engine, select
from sqlalchemy.orm import sessionmaker

from app.models import Base, Claim, Entity
from app.schemas import ExtractedEntity
from app.services.merger import apply_extracted, external_key


def _session(tmp_path):
    eng = create_engine(f"sqlite:///{tmp_path / 's3.db'}", future=True)
    Base.metadata.create_all(bind=eng)
    return sessionmaker(bind=eng, future=True)()


def test_new_entity_creates_pending_claims(tmp_path):
    db = _session(tmp_path)
    site_id = "site-s3"
    item = ExtractedEntity(
        entity_type="capability",
        name="Install CCTV",
        description="Cameras",
        properties={"hours": 24},
        relationships=[],
        evidence=[{"url": "https://ex/c", "snippet": "CCTV"}],
        source="scan",
    )
    stats = apply_extracted(db, site_id, [item], scan_job_id="job1", is_rescan=False)
    assert stats["created"] == 1
    assert stats["claims_created"] >= 4  # name, desc, prop, evid, rel, trust, source

    ent = db.query(Entity).one()
    assert ent.status == "pending"
    assert ent.name == "Install CCTV"
    claims = list(db.scalars(select(Claim).where(Claim.entity_id == ent.id)).all())
    assert all(c.status == "pending" for c in claims)
    assert any(c.attribute == "name" and c.value == "Install CCTV" for c in claims)
    assert any(c.attribute == "prop:hours" for c in claims)


def test_rescan_identical_no_new_claims(tmp_path):
    db = _session(tmp_path)
    site_id = "site-s3"
    item = ExtractedEntity(
        entity_type="capability",
        name="Install CCTV",
        description="Cameras",
        properties={"hours": 24},
        source="scan",
    )
    apply_extracted(db, site_id, [item], "job1")
    n1 = db.query(Claim).count()
    stats = apply_extracted(db, site_id, [item], "job2", is_rescan=True)
    assert stats["created"] == 0
    assert stats["claims_created"] == 0
    assert stats["unchanged"] == 1
    assert db.query(Claim).count() == n1


def test_rescan_change_inserts_pending_and_needs_edit(tmp_path):
    db = _session(tmp_path)
    site_id = "site-s3"
    item = ExtractedEntity(
        entity_type="capability",
        name="Install CCTV",
        description="Cameras",
        properties={"hours": 24},
        source="scan",
    )
    apply_extracted(db, site_id, [item], "job1")
    ent = db.query(Entity).one()
    ent.status = "approved"
    # Simulate Stage 2 backfill: promote name claim to approved
    for c in db.query(Claim).filter(Claim.entity_id == ent.id).all():
        c.status = "approved"
    db.commit()

    item2 = ExtractedEntity(
        entity_type="capability",
        name="Install CCTV",
        description="Cameras and NVR",
        properties={"hours": 24},
        source="scan",
    )
    old_desc = ent.description
    stats = apply_extracted(db, site_id, [item2], "job2", is_rescan=True)
    db.refresh(ent)
    assert stats["claims_created"] >= 1
    assert ent.status == "needs_edit"
    # Entity attribute columns frozen
    assert ent.description == old_desc
    pending = list(
        db.scalars(
            select(Claim).where(
                Claim.entity_id == ent.id,
                Claim.attribute == "description",
                Claim.status == "pending",
            )
        ).all()
    )
    assert len(pending) == 1
    assert pending[0].value == "Cameras and NVR"
    assert pending[0].supersedes_id is not None


def test_rescan_does_not_overwrite_entity_properties(tmp_path):
    db = _session(tmp_path)
    site_id = "site-s3"
    item = ExtractedEntity(
        entity_type="capability",
        name="X",
        properties={"a": 1},
        source="scan",
    )
    apply_extracted(db, site_id, [item], "job1")
    ent = db.query(Entity).one()
    ent.status = "approved"
    for c in db.query(Claim).filter(Claim.entity_id == ent.id).all():
        c.status = "approved"
    db.commit()

    item2 = ExtractedEntity(
        entity_type="capability",
        name="X",
        properties={"a": 2, "b": 9},
        source="scan",
    )
    apply_extracted(db, site_id, [item2], "job2", is_rescan=True)
    db.refresh(ent)
    assert ent.properties == {"a": 1}
    assert db.query(Claim).filter(Claim.status == "pending").count() >= 1


def test_external_key_stable(tmp_path):
    k = external_key("s", "capability", "Install CCTV")
    assert len(k) == 32
