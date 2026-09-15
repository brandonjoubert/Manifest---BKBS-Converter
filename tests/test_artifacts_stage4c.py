"""Claim Ledger Stage 4c: snippet XSS, honest agent.json, AIPREF default off, robots END."""

from __future__ import annotations

from pathlib import Path
from types import SimpleNamespace

from app.exports.agent_json import build_agent_json
from app.exports.jsonld_snippet import organization_jsonld_snippet, script_safe_json
from app.exports.robots import managed_robots_block, merge_robots
from app.services.export_package import create_export_package


def _site(**kwargs):
    data = dict(
        name="Acme",
        base_url="https://acme.example",
        aipref_search=False,
        aipref_ai_input=False,
        aipref_train_ai=False,
    )
    data.update(kwargs)
    return SimpleNamespace(**data)


def _cap():
    return SimpleNamespace(
        id="e1",
        entity_type="capability",
        name="Install CCTV",
        description="Cameras <script>alert(1)</script>",
        properties={},
        relationships=[],
        evidence=[],
        status="approved",
    )


def test_t8_honest_agent_json_no_stub_protocol():
    data = build_agent_json(_site(), [_cap()])
    assert set(data.keys()) == {"name", "url", "knowledge"}
    assert "protocol" not in data
    assert "endpoint" not in data
    assert "capabilities" not in data
    k = data["knowledge"]
    for key in (
        "llms_txt",
        "llms_full",
        "graph",
        "schema_organization",
        "schema_services",
    ):
        assert key in k
        assert k[key].startswith("https://acme.example/")


def test_t9_jsonld_snippet_escapes_script():
    raw = script_safe_json({"x": "</script><script>alert(1)</script>"})
    assert "<" not in raw
    assert "\\u003c" in raw
    snippet = organization_jsonld_snippet(_site(), [_cap()])
    assert snippet.startswith('<script type="application/ld+json">')
    assert snippet.strip().endswith("</script>")
    inner = snippet[len('<script type="application/ld+json">') :].rsplit("</script>", 1)[0]
    assert "</script>" not in inner
    assert "<script>" not in inner
    assert "\\u003c" in inner


def test_t10_robots_merge_without_end_does_not_duplicate(tmp_path: Path):
    site = _site()
    root = tmp_path / "public"
    root.mkdir()
    (root / "robots.txt").write_text("# BEGIN BKBS\nUser-agent: *\nAllow: /old\n", encoding="utf-8")
    merge_robots(root, site)
    text = (root / "robots.txt").read_text(encoding="utf-8")
    assert text.count("# BEGIN BKBS") == 1
    assert text.count("# END BKBS") == 1
    assert "Allow: /llms.txt" in text
    assert "Allow: /old" not in text
    merge_robots(root, site)
    text2 = (root / "robots.txt").read_text(encoding="utf-8")
    assert text2.count("# BEGIN BKBS") == 1
    assert text2.count("# END BKBS") == 1


def test_t11_aipref_default_off():
    block = managed_robots_block(_site())
    assert "Content-Usage:" not in block
    assert "# END BKBS" in block
    on = managed_robots_block(_site(aipref_search=True))
    assert "Content-Usage: search=y, ai-input=n, train-ai=n" in on
    assert on.strip().endswith("# END BKBS")


def test_zip_includes_jsonld_snippet(tmp_path, monkeypatch):
    from sqlalchemy import create_engine
    from sqlalchemy.orm import sessionmaker

    from app.config import settings
    from app.models import Base, Entity, Site

    monkeypatch.setattr(settings, "bkbs_data_dir", str(tmp_path))
    settings.exports_dir.mkdir(parents=True, exist_ok=True)
    eng = create_engine(f"sqlite:///{tmp_path / 't.db'}", future=True)
    Base.metadata.create_all(bind=eng)
    db = sessionmaker(bind=eng, future=True)()
    site = Site(name="Acme", base_url="https://acme.example", auto_publish=False)
    db.add(site)
    db.flush()
    db.add(
        Entity(
            site_id=site.id,
            external_key="k1",
            entity_type="business_identity",
            name="Acme",
            description="Co",
            status="approved",
        )
    )
    db.commit()
    from app.services.stage6 import null_attribute_columns

    null_attribute_columns(db)
    export = create_export_package(db, site, include_pending=False)
    from zipfile import ZipFile

    from app.services.export_package import zip_path_for_export

    zpath = zip_path_for_export(export)
    with ZipFile(zpath) as zf:
        names = zf.namelist()
    assert "schema/jsonld-snippet.html" in names
    snippet = ZipFile(zpath).read("schema/jsonld-snippet.html").decode("utf-8")
    assert "application/ld+json" in snippet
