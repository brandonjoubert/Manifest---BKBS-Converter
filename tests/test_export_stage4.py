"""Claim Ledger Stage 4a: claim writers + resolve_site publication rule (T1–T4, T7)."""

from __future__ import annotations

from pathlib import Path

from sqlalchemy import create_engine, select
from sqlalchemy.orm import sessionmaker

from app.config import settings
from app.models import Base, Claim, Entity, Site
from app.schemas import ExtractedEntity
from app.services.claim_codec import entity_attribute_pairs
from app.services.claim_writer import apply_human_decision
from app.services.export_llms import render_llms_txt
from app.services.export_package import create_export_package
from app.services.merger import apply_extracted
from app.services.publish_live import publish_site_live
from app.services.resolver import resolve_entity, resolve_site


def _session(tmp_path):
    eng = create_engine(f"sqlite:///{tmp_path / 's4.db'}", future=True)
    Base.metadata.create_all(bind=eng)
    return sessionmaker(bind=eng, future=True)()


def _site(db, tmp_path, *, suffix: str = "a") -> Site:
    root = tmp_path / f"public_html_{suffix}"
    root.mkdir()
    site = Site(
        name="Stage4 Co",
        base_url="https://s4.example",
        max_pages=5,
        crawl_delay_ms=0,
        auto_publish=False,
        publish_root=str(root),
    )
    db.add(site)
    db.commit()
    db.refresh(site)
    return site


def _live_text(root: Path) -> str:
    return (root / "llms.txt").read_text(encoding="utf-8") if (root / "llms.txt").exists() else ""


def test_t1_pending_only_never_in_live_files(tmp_path):
    """T1: never-approved pending entity is absent from origin files."""
    db = _session(tmp_path)
    site = _site(db, tmp_path, suffix="t1")
    item = ExtractedEntity(
        entity_type="capability",
        name="SECRET_PENDING_CAPABILITY",
        description="should not leak",
        source="scan",
    )
    apply_extracted(db, site.id, [item], scan_job_id="job1")
    db.commit()

    result = publish_site_live(db, site)
    assert result.ok, result.error
    live = _live_text(Path(site.publish_root))
    assert "SECRET_PENDING_CAPABILITY" not in live
    public = resolve_site(db, site.id, include_pending=False)
    assert all("SECRET_PENDING" not in (r.name or "") for r in public)


def test_t2_pending_description_does_not_replace_approved(tmp_path):
    """T2: approved + pending description → public file keeps old approved value."""
    db = _session(tmp_path)
    site = _site(db, tmp_path, suffix="t2")
    item = ExtractedEntity(
        entity_type="capability",
        name="Install CCTV",
        description="OLD_APPROVED_DESC",
        source="scan",
    )
    apply_extracted(db, site.id, [item], "job1")
    ent = db.query(Entity).one()
    apply_human_decision(db, ent, "approve", approved_by="test")
    db.commit()

    item2 = ExtractedEntity(
        entity_type="capability",
        name="Install CCTV",
        description="NEW_PENDING_LEAK",
        source="scan",
    )
    apply_extracted(db, site.id, [item2], "job2", is_rescan=True)
    db.commit()
    db.refresh(ent)
    assert ent.status == "needs_edit"

    result = publish_site_live(db, site)
    assert result.ok, result.error
    live = _live_text(Path(site.publish_root))
    assert "Install CCTV" in live
    assert "OLD_APPROVED_DESC" in live
    assert "NEW_PENDING_LEAK" not in live


def test_t3_ui_approve_writes_claims_then_resolve_publishes(tmp_path):
    """T3: approve promotes pending claims; export-via-resolve includes the entity."""
    db = _session(tmp_path)
    site = _site(db, tmp_path, suffix="t3")
    item = ExtractedEntity(
        entity_type="capability",
        name="APPROVED_AFTER_REVIEW",
        description="Cameras",
        source="scan",
    )
    apply_extracted(db, site.id, [item], "job1")
    ent = db.query(Entity).one()
    assert ent.status == "pending"
    pending_before = list(
        db.scalars(select(Claim).where(Claim.entity_id == ent.id, Claim.status == "pending")).all()
    )
    assert pending_before

    apply_human_decision(db, ent, "approve", approved_by="ui")
    db.commit()
    db.refresh(ent)
    assert ent.status == "approved"

    approved = list(
        db.scalars(select(Claim).where(Claim.entity_id == ent.id, Claim.status == "approved")).all()
    )
    assert approved
    assert any(c.attribute == "name" and c.value == "APPROVED_AFTER_REVIEW" for c in approved)

    resolved = resolve_entity(ent.id, db=db)
    assert resolved is not None
    assert resolved.name == "APPROVED_AFTER_REVIEW"

    public = resolve_site(db, site.id)
    assert any(r.name == "APPROVED_AFTER_REVIEW" for r in public)

    result = publish_site_live(db, site)
    assert result.ok, result.error
    assert "APPROVED_AFTER_REVIEW" in _live_text(Path(site.publish_root))


def test_t4_needs_edit_keeps_last_approved_snapshot(tmp_path):
    """T4: rescan sets needs_edit; publish still includes last approved snapshot."""
    db = _session(tmp_path)
    site = _site(db, tmp_path, suffix="t4")
    item = ExtractedEntity(
        entity_type="capability",
        name="Gate Motors",
        description="OLD_LIVE",
        source="scan",
    )
    apply_extracted(db, site.id, [item], "job1")
    ent = db.query(Entity).one()
    apply_human_decision(db, ent, "approve", approved_by="test")
    db.commit()

    apply_extracted(
        db,
        site.id,
        [
            ExtractedEntity(
                entity_type="capability",
                name="Gate Motors",
                description="RESCAN_PENDING",
                source="scan",
            )
        ],
        "job2",
        is_rescan=True,
    )
    db.commit()
    db.refresh(ent)
    assert ent.status == "needs_edit"

    public = resolve_site(db, site.id, include_pending=False)
    assert any(r.name == "Gate Motors" for r in public)
    assert all("RESCAN_PENDING" not in (r.description or "") for r in public)
    assert any((r.description or "") == "OLD_LIVE" for r in public)

    result = publish_site_live(db, site)
    assert result.ok, result.error
    live = _live_text(Path(site.publish_root))
    assert "Gate Motors" in live
    assert "OLD_LIVE" in live
    assert "RESCAN_PENDING" not in live


def test_t7_include_pending_zip_never_writes_origin(tmp_path, monkeypatch):
    """T7: draft ZIP may include pending names; origin is untouched."""
    monkeypatch.setattr(settings, "bkbs_data_dir", str(tmp_path / "data"))
    (tmp_path / "data").mkdir()
    db = _session(tmp_path)
    site = _site(db, tmp_path, suffix="t7")
    origin = Path(site.publish_root)
    (origin / "sentinel.txt").write_text("keep", encoding="utf-8")

    apply_extracted(
        db,
        site.id,
        [
            ExtractedEntity(
                entity_type="capability",
                name="DRAFT_ONLY_PENDING",
                description="draft",
                source="scan",
            )
        ],
        "job1",
    )
    db.commit()

    export = create_export_package(db, site, include_pending=True)
    zip_text = Path(export.path, "llms.txt").read_text(encoding="utf-8")
    assert "DRAFT_ONLY_PENDING" in zip_text
    assert not (origin / "llms.txt").exists()
    assert (origin / "sentinel.txt").read_text(encoding="utf-8") == "keep"

    result = publish_site_live(db, site, include_pending=True)
    assert result.ok, result.error
    assert "DRAFT_ONLY_PENDING" not in _live_text(origin)


def test_backfill_pairs_omit_status():
    class E:
        name = "N"
        description = "D"
        properties = {}
        relationships = []
        evidence = []
        trust_level = "high"
        source = "fixture"
        status = "approved"

    pairs = dict(entity_attribute_pairs(E()))
    assert "status" not in pairs
    assert pairs["name"] == "N"


def test_reject_excluded_from_public_set(tmp_path):
    db = _session(tmp_path)
    site = _site(db, tmp_path, suffix="rej")
    apply_extracted(
        db,
        site.id,
        [ExtractedEntity(entity_type="capability", name="NOPE", source="scan")],
        "job1",
    )
    ent = db.query(Entity).one()
    apply_human_decision(db, ent, "reject", approved_by="test")
    db.commit()
    public = resolve_site(db, site.id)
    assert all(r.name != "NOPE" for r in public)


def test_php_and_wp_stage4a_hooks_present():
    root = Path(__file__).resolve().parents[1]
    php_res = (root / "php/src/Resolver.php").read_text(encoding="utf-8")
    assert "function resolveSite" in php_res
    assert "function applyHumanDecision" in php_res
    assert "function isPublicEnvelope" in php_res
    php_pub = (root / "php/src/Publisher.php").read_text(encoding="utf-8")
    assert "isPublicEnvelope" in php_pub
    php_router = (root / "php/src/Router.php").read_text(encoding="utf-8")
    assert "applyHumanDecision" in php_router
    assert "resolveSite" in php_router

    wp_res = (root / "wordpress-plugin/manifest-bkbs-converter/includes/class-mbkbs-resolver.php").read_text(
        encoding="utf-8"
    )
    assert "function resolve_site" in wp_res
    wp_bf = (root / "wordpress-plugin/manifest-bkbs-converter/includes/class-mbkbs-backfill.php").read_text(
        encoding="utf-8"
    )
    assert "function apply_human_decision" in wp_bf
    assert "['trust_level', 'source']" in wp_bf or '["trust_level", "source"]' in wp_bf
    wp_pub = (root / "wordpress-plugin/manifest-bkbs-converter/includes/class-mbkbs-publisher.php").read_text(
        encoding="utf-8"
    )
    assert "resolve_site" in wp_pub
    wp_admin = (root / "wordpress-plugin/manifest-bkbs-converter/admin/class-mbkbs-admin.php").read_text(
        encoding="utf-8"
    )
    assert "apply_human_decision" in wp_admin
    assert "apply_save_claims" in wp_admin
