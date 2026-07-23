from app.config import settings
from app.db import SessionLocal, init_db
from app.models import Entity, Site
from app.services.site_ops import delete_site_and_data


def test_delete_site_removes_db_and_export_dir():
    init_db()
    db = SessionLocal()
    try:
        site = Site(name="To Delete", base_url="https://delete.example", max_pages=5)
        db.add(site)
        db.commit()
        db.refresh(site)
        site_id = site.id

        db.add(
            Entity(
                site_id=site_id,
                external_key="abc-delete-test",
                entity_type="capability",
                name="Test Cap",
                status="pending",
                source="manual",
            )
        )
        db.commit()

        export_path = settings.exports_dir / site_id
        export_path.mkdir(parents=True, exist_ok=True)
        (export_path / "llms.txt").write_text("x", encoding="utf-8")

        name = delete_site_and_data(db, site)
        assert name == "To Delete"
        assert db.get(Site, site_id) is None
        assert db.query(Entity).filter(Entity.site_id == site_id).count() == 0
        assert not export_path.exists()
    finally:
        db.close()
