from app.db import SessionLocal, init_db
from app.models import Entity, Site
from app.services.publish_live import publish_site_live


def test_publish_writes_live_files(tmp_path):
    init_db()
    db = SessionLocal()
    try:
        root = tmp_path / "public_html"
        root.mkdir()
        site = Site(
            name="Live Co",
            base_url="https://live.example",
            max_pages=5,
            publish_root=str(root),
            auto_publish=True,
        )
        db.add(site)
        db.commit()
        db.refresh(site)
        db.add(
            Entity(
                site_id=site.id,
                external_key="pub1",
                entity_type="business_identity",
                name="Live Co",
                description="Test firm",
                status="approved",
                source="manual",
                properties={"email": "a@b.c"},
            )
        )
        db.add(
            Entity(
                site_id=site.id,
                external_key="pub2",
                entity_type="capability",
                name="Install CCTV",
                description="We install CCTV",
                status="approved",
                source="manual",
            )
        )
        db.commit()

        result = publish_site_live(db, site)
        assert result.ok, result.error
        assert (root / "llms.txt").exists()
        assert "Live Co" in (root / "llms.txt").read_text(encoding="utf-8")
        assert (root / "graph.json").exists()
        assert (root / "schema" / "organization.jsonld").exists()
        assert (root / ".well-known" / "agent.json").exists()
        assert (root / "robots.txt").exists()
        assert "BEGIN BKBS" in (root / "robots.txt").read_text(encoding="utf-8")
    finally:
        db.close()
