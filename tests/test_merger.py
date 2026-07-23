from app.services.merger import external_key, normalize_name, _merge_dicts, _merge_list_of_dicts


def test_normalize_name():
    assert normalize_name("  Install  CCTV! ") == "install cctv"


def test_external_key_stable():
    a = external_key("site1", "capability", "Install CCTV")
    b = external_key("site1", "capability", "install cctv")
    c = external_key("site1", "capability", "Other")
    assert a == b
    assert a != c


def test_merge_dicts_prefers_existing_nonempty():
    a = {"a": 1, "b": ""}
    b = {"a": 99, "b": "x", "c": 3}
    m = _merge_dicts(a, b)
    assert m["a"] == 1
    assert m["b"] == "x"
    assert m["c"] == 3


def test_merge_list_dedupes():
    a = [{"url": "http://x", "snippet": "a"}]
    b = [{"url": "http://x", "snippet": "a"}, {"url": "http://y", "snippet": "b"}]
    m = _merge_list_of_dicts(a, b, ("url", "snippet", "kind"))
    assert len(m) == 2
