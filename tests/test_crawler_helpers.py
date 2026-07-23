from app.services.crawler import is_skippable, normalize_url, same_origin


def test_normalize_url_strips_fragment_and_query():
    assert normalize_url("https://ex.com/path/?q=1#frag") == "https://ex.com/path"


def test_same_origin():
    assert same_origin("https://a.com/x", "https://a.com/y")
    assert not same_origin("https://a.com", "https://b.com")


def test_skippable_media():
    assert is_skippable("https://a.com/img.png")
    assert not is_skippable("https://a.com/about")
