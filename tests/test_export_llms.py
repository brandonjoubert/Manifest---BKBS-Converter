from types import SimpleNamespace

from app.services.export_llms import render_llms_txt


def test_render_llms_txt_basic():
    site = SimpleNamespace(name="GENSIX", base_url="https://example.com")
    identity = SimpleNamespace(
        entity_type="business_identity",
        name="GENSIX Security",
        description="Physical security integrator",
        properties={"email": "info@example.com", "telephone": "+27 31 000 0000"},
        evidence=[],
    )
    cap = SimpleNamespace(
        entity_type="capability",
        name="Install CCTV",
        description="Certified installers in Durban",
        properties={},
        evidence=[{"url": "https://example.com/cctv"}],
    )
    text = render_llms_txt(site, [identity, cap])
    assert "# GENSIX Security" in text
    assert "Install CCTV" in text
    assert "info@example.com" in text
    assert "https://example.com/cctv" in text
