"""Claim Ledger Stage 5: GET /diff, save rule, bulk cannot launder needs_edit."""

from __future__ import annotations

from pathlib import Path

from sqlalchemy import create_engine, select
from sqlalchemy.orm import sessionmaker

from app.models import Base, Claim, Entity
from app.schemas import ExtractedEntity
from app.services.claim_diff import can_bulk_approve, claim_diff_for_entity
from app.services.claim_writer import apply_human_decision, apply_review_from_form
from app.services.merger import apply_extracted
from app.services.resolver import resolve_site


def _session(tmp_path):
    eng = create_engine(f"sqlite:///{tmp_path / 's5.db'}", future=True)
    Base.metadata.create_all(bind=eng)
    return sessionmaker(bind=eng, future=True)()


def _scan_cap(db, site_id, name, desc, job):
    apply_extracted(
        db,
        site_id,
        [ExtractedEntity(entity_type="capability", name=name, description=desc, source="scan")],
        job,
    )
    db.commit()
    return db.query(Entity).filter(Entity.name == name).one()


def test_diff_uses_claims_not_columns(tmp_path):
    db = _session(tmp_path)
    ent = _scan_cap(db, "s5", "Gate Motors", "OLD_APPROVED", "j1")
    apply_human_decision(db, ent, "approve", approved_by="t")
    db.commit()
    apply_extracted(
        db,
        "s5",
        [ExtractedEntity(entity_type="capability", name="Gate Motors", description="NEW_PENDING", source="scan")],
        "j2",
        is_rescan=True,
    )
    db.commit()
    ent.description = "COLUMN_LEAK"
    db.commit()

    diff = claim_diff_for_entity(db, ent.id)
    desc = next(c for c in diff["changes"] if c["attribute"] == "description")
    assert desc["kind"] == "changed"
    assert desc["old"] == "OLD_APPROVED"
    assert desc["new"] == "NEW_PENDING"
    assert "COLUMN_LEAK" not in (desc["old"] or "")
    assert "COLUMN_LEAK" not in (desc["new"] or "")
    assert diff["can_bulk_approve"] is False


def test_diff_new_entity_has_no_old(tmp_path):
    db = _session(tmp_path)
    ent = _scan_cap(db, "s5", "Brand New", "First look", "j1")
    diff = claim_diff_for_entity(db, ent.id)
    assert diff["is_new_entity"] is True
    assert diff["can_bulk_approve"] is True
    name = next(c for c in diff["changes"] if c["attribute"] == "name")
    assert name["kind"] == "new"
    assert name["old"] is None
    assert name["new"] == "Brand New"


def test_approve_no_edit_promotes_extract(tmp_path):
    db = _session(tmp_path)
    ent = _scan_cap(db, "s5", "CCTV", "Cameras", "j1")
    apply_review_from_form(
        db,
        ent,
        intent="save_approve",
        submitted={"description": "Cameras"},
        extract={"description": "Cameras"},
        approved_by="ui",
    )
    db.commit()
    db.refresh(ent)
    assert ent.status == "approved"
    approved = list(
        db.scalars(
            select(Claim).where(
                Claim.entity_id == ent.id,
                Claim.attribute == "description",
                Claim.status == "approved",
            )
        ).all()
    )
    assert any(c.value == "Cameras" for c in approved)


def test_save_without_approve_manual_stays_pending(tmp_path):
    db = _session(tmp_path)
    ent = _scan_cap(db, "s5", "CCTV", "Cameras", "j1")
    apply_review_from_form(
        db,
        ent,
        intent="save",
        submitted={"description": "Cameras and NVR"},
        extract={"description": "Cameras"},
        approved_by="ui",
    )
    db.commit()
    db.refresh(ent)
    assert ent.status == "pending"
    pending = list(
        db.scalars(
            select(Claim).where(
                Claim.entity_id == ent.id,
                Claim.attribute == "description",
                Claim.status == "pending",
            )
        ).all()
    )
    assert any(c.value == "Cameras and NVR" and c.extraction_method == "manual" for c in pending)


def test_reject_needs_edit_keeps_last_approved_live(tmp_path):
    db = _session(tmp_path)
    from app.models import Site

    site = Site(id="s5r", name="R", base_url="https://r.example", max_pages=5, auto_publish=False)
    db.add(site)
    db.commit()
    ent = _scan_cap(db, "s5r", "Keep Live", "OLD_LIVE", "j1")
    apply_human_decision(db, ent, "approve", approved_by="t")
    db.commit()
    apply_extracted(
        db,
        "s5r",
        [ExtractedEntity(entity_type="capability", name="Keep Live", description="PENDING_GONE", source="scan")],
        "j2",
        is_rescan=True,
    )
    db.commit()
    db.refresh(ent)
    assert ent.status == "needs_edit"
    apply_human_decision(db, ent, "reject", approved_by="ui")
    db.commit()
    db.refresh(ent)
    assert ent.status == "approved"
    public = resolve_site(db, "s5r")
    assert any(r.name == "Keep Live" for r in public)
    assert all("PENDING_GONE" not in (r.description or "") for r in public)
    assert any((r.description or "") == "OLD_LIVE" for r in public)


def test_bulk_approve_skips_needs_edit(tmp_path):
    db = _session(tmp_path)
    new_ent = _scan_cap(db, "s5b", "New Cap", "n", "j1")
    old = _scan_cap(db, "s5b", "Old Cap", "old", "j1b")
    apply_human_decision(db, old, "approve", approved_by="t")
    db.commit()
    apply_extracted(
        db,
        "s5b",
        [ExtractedEntity(entity_type="capability", name="Old Cap", description="changed", source="scan")],
        "j2",
        is_rescan=True,
    )
    db.commit()
    db.refresh(old)
    assert old.status == "needs_edit"
    assert can_bulk_approve(db, new_ent.id) is True
    assert can_bulk_approve(db, old.id) is False


def test_php_wp_stage5_hooks_present():
    root = Path(__file__).resolve().parents[1]
    php = (root / "php/src/Resolver.php").read_text(encoding="utf-8")
    assert "function claimDiff" in php
    assert "function applyReviewFromForm" in php
    assert "function canBulkApprove" in php
    router = (root / "php/src/Router.php").read_text(encoding="utf-8")
    assert "entityReview" in router
    assert "bulk-review" in router
    assert "confirm_diffs" in router
    wp_diff = (root / "wordpress-plugin/manifest-bkbs-converter/includes/class-mbkbs-diff.php").read_text(
        encoding="utf-8"
    )
    assert "function for_entity" in wp_diff
    admin = (root / "wordpress-plugin/manifest-bkbs-converter/admin/class-mbkbs-admin.php").read_text(
        encoding="utf-8"
    )
    assert "review_entity" in admin
    assert "confirm_diffs" in admin
    assert "can_bulk_approve" in admin
    views = (root / "wordpress-plugin/manifest-bkbs-converter/admin/views/entity-edit.php").read_text(
        encoding="utf-8"
    )
    assert "Live facts are still published" in views
    assert "claim:" in views
