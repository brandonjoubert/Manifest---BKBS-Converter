import pytest
from pydantic import ValidationError

from app.schemas import EntityCreate, SiteCreate


def test_site_create_normalizes_url():
    s = SiteCreate(name="Test", base_url="example.com")
    assert s.base_url == "https://example.com"


def test_entity_create_rejects_bad_type():
    with pytest.raises(ValidationError):
        EntityCreate(entity_type="not_real", name="X")


def test_entity_create_ok():
    e = EntityCreate(entity_type="capability", name="Install CCTV")
    assert e.entity_type == "capability"
