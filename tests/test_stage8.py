"""Claim Ledger Stage 8: origin findings catalog, extract-before-strip, scan UI."""

from __future__ import annotations

import inspect
import json
from pathlib import Path
from bs4 import BeautifulSoup
from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker

from app.models import Base, ScanJob, Site
from app.services.crawler import (
    CrawledPage,
    CrawlResult,
    crawl_site,
    extract_json_ld,
    html_to_text,
)
from app.services.scan_audit import (
    FINDING_IDS,
    LLMS_MARKER,
    AuditInput,
    OriginProbes,
    Probe,
    evaluate_findings,
    has_high_severity_fail,
    probe_origin,
    unknown_findings,
)
from app.services.scan_runner import run_scan_job

ROOT = Path(__file__).resolve().parents[1]
T31_HTML = (
    '<html><head>'
    '<script type="application/ld+json">{"@type":"Organization","name":"Acme"}</script>'
    "<script>var x=1;</script>"
    "</head><body><p>Hi</p></body></html>"
)


def _probe(url: str, status: int | None, body: str | None = "", error: str | None = None) -> Probe:
    return Probe(url=url, status_code=status, body=body, error=error)


def _inp(**kwargs) -> AuditInput:
    data = dict(
        base_url="https://ex.com",
        site_id="site-1",
        pages_json_ld=[],
        html_ok_count=0,
        robots=None,
        llms_txt=None,
        agent_json=None,
        tdmrep=None,
    )
    data.update(kwargs)
    return AuditInput(**data)


def _by_id(findings: list[dict]) -> dict[str, dict]:
    assert [f["id"] for f in findings] == list(FINDING_IDS)
    return {f["id"]: f for f in findings}


def test_t25_jsonld_on_page_pass_fail_unknown():
    pass_f = _by_id(
        evaluate_findings(
            _inp(
                pages_json_ld=[
                    ("https://ex.com/", [{"@type": "Organization", "name": "Acme"}])
                ],
                html_ok_count=1,
            )
        )
    )["jsonld-on-page"]
    assert pass_f["status"] == "pass"
    assert pass_f["severity"] == "high"
    assert pass_f["cta"] == ""
    assert pass_f["cta_href"] == ""
    assert "Organization" in pass_f["evidence"]

    fail_f = _by_id(
        evaluate_findings(
            _inp(
                pages_json_ld=[("https://ex.com/", [])],
                html_ok_count=1,
            )
        )
    )["jsonld-on-page"]
    assert fail_f["status"] == "fail"
    assert fail_f["severity"] == "high"
    assert fail_f["cta"] == "Copy JSON-LD snippet"
    assert fail_f["cta_href"] == "/sites/site-1#machine-layers"
    assert "Homepage https://ex.com/" in fail_f["evidence"]

    unk = _by_id(evaluate_findings(_inp(html_ok_count=0, pages_json_ld=[])))["jsonld-on-page"]
    assert unk["status"] == "unknown"
    assert unk["severity"] == "high"
    assert "0 HTML pages fetched" in unk["evidence"]


def test_t26_aipref_robots_detect_only():
    src = (ROOT / "app" / "services" / "scan_audit.py").read_text(encoding="utf-8")
    assert "merge_robots" not in src

    body = "User-agent: *\nContent-Usage: search=y, ai-input=n\n"
    pass_f = _by_id(
        evaluate_findings(
            _inp(robots=_probe("https://ex.com/robots.txt", 200, body))
        )
    )["aipref-robots"]
    assert pass_f["status"] == "pass"
    assert pass_f["severity"] == "info"
    assert "Content-Usage" in pass_f["evidence"]
    assert pass_f["cta_href"] == "/sites/site-1#machine-layers"

    fail_f = _by_id(
        evaluate_findings(
            _inp(robots=_probe("https://ex.com/robots.txt", 200, "User-agent: *\nAllow: /\n"))
        )
    )["aipref-robots"]
    assert fail_f["status"] == "fail"
    assert fail_f["severity"] == "info"
    assert fail_f["cta"] == "Leave AIPREF off unless intended"

    unk = _by_id(evaluate_findings(_inp(robots=None)))["aipref-robots"]
    assert unk["status"] == "unknown"
    assert unk["severity"] == "info"


def test_t27_llms_txt_ordered_eval():
    url = "https://ex.com/llms.txt"
    unk = _by_id(
        evaluate_findings(_inp(llms_txt=_probe(url, None, None, error="ConnectTimeout")))
    )["llms-txt"]
    assert unk["status"] == "unknown"
    assert unk["severity"] == "high"

    missing = _by_id(evaluate_findings(_inp(llms_txt=_probe(url, 404, ""))))["llms-txt"]
    assert missing["status"] == "fail"
    assert missing["cta"] == "Publish live"
    assert missing["cta_href"] == "/sites/site-1"

    empty = _by_id(evaluate_findings(_inp(llms_txt=_probe(url, 200, ""))))["llms-txt"]
    assert empty["status"] == "fail"

    hashed = _by_id(evaluate_findings(_inp(llms_txt=_probe(url, 200, "# Title\nHello\n"))))[
        "llms-txt"
    ]
    assert hashed["status"] == "pass"

    marked = _by_id(
        evaluate_findings(_inp(llms_txt=_probe(url, 200, f"Hello\n{LLMS_MARKER}\n")))
    )["llms-txt"]
    assert marked["status"] == "pass"

    html_with_llms = (
        "<!DOCTYPE html><html><body>See our llms.txt guide in the footer</body></html>"
    )
    html_fail = _by_id(
        evaluate_findings(_inp(llms_txt=_probe(url, 200, html_with_llms)))
    )["llms-txt"]
    assert html_fail["status"] == "fail"
    assert html_fail["severity"] == "high"

    other = _by_id(
        evaluate_findings(_inp(llms_txt=_probe(url, 200, "plain text without a hash")))
    )["llms-txt"]
    assert other["status"] == "fail"


def test_t28_agent_json_stub_high_404_medium():
    pages = [("https://ex.com/", [{"@type": "Organization", "name": "Acme"}])]
    llms_ok = _probe("https://ex.com/llms.txt", 200, "# Title\n")
    url = "https://ex.com/.well-known/agent.json"
    honest = json.dumps({"name": "Acme", "url": "https://ex.com", "knowledge": {}})
    stub = json.dumps(
        {
            "name": "Acme",
            "url": "https://ex.com",
            "knowledge": {},
            "protocol": "agent-web-protocol-stub",
            "endpoint": "https://ex.com/a2a",
        }
    )

    pass_all = evaluate_findings(
        _inp(
            pages_json_ld=pages,
            html_ok_count=1,
            llms_txt=llms_ok,
            agent_json=_probe(url, 200, honest),
        )
    )
    agent_pass = _by_id(pass_all)["agent-json"]
    assert agent_pass["status"] == "pass"
    assert agent_pass["severity"] == "info"

    missing_all = evaluate_findings(
        _inp(
            pages_json_ld=pages,
            html_ok_count=1,
            llms_txt=llms_ok,
            agent_json=_probe(url, 404, ""),
        )
    )
    agent_404 = _by_id(missing_all)["agent-json"]
    assert agent_404["status"] == "fail"
    assert agent_404["severity"] == "medium"
    assert not has_high_severity_fail(missing_all)
    assert not has_high_severity_fail([agent_404])

    stub_all = evaluate_findings(
        _inp(
            pages_json_ld=pages,
            html_ok_count=1,
            llms_txt=llms_ok,
            agent_json=_probe(url, 200, stub),
        )
    )
    agent_stub = _by_id(stub_all)["agent-json"]
    assert agent_stub["status"] == "fail"
    assert agent_stub["severity"] == "high"
    assert "protocol" in agent_stub["evidence"]
    assert has_high_severity_fail(stub_all)
    assert has_high_severity_fail([agent_stub])


def test_t29_tdmrep_never_fail():
    src = (ROOT / "app" / "services" / "scan_audit.py").read_text(encoding="utf-8")
    assert "merge_robots" not in src
    assert "tdmrep.json" in src
    assert "TDM-Reservation" not in src

    missing = _by_id(
        evaluate_findings(
            _inp(tdmrep=_probe("https://ex.com/.well-known/tdmrep.json", 404, ""))
        )
    )["tdmrep"]
    assert missing["status"] == "unknown"
    assert missing["severity"] == "info"
    assert missing["cta_href"] == ""
    assert missing["status"] != "fail"

    present = _by_id(
        evaluate_findings(
            _inp(tdmrep=_probe("https://ex.com/.well-known/tdmrep.json", 200, '{"tdm":1}'))
        )
    )["tdmrep"]
    assert present["status"] == "pass"
    assert present["cta"]
    assert present["cta_href"] == ""


def test_t31_extract_json_ld_before_script_strip():
    soup = BeautifulSoup(T31_HTML, "lxml")
    blocks = extract_json_ld(soup)
    assert len(blocks) == 1
    assert blocks[0]["@type"] == "Organization"
    assert blocks[0]["name"] == "Acme"
    html_to_text(soup, 12_000)
    assert extract_json_ld(soup) == []

    src = inspect.getsource(crawl_site)
    assert src.index("extract_json_ld") < src.index("html_to_text")


def test_unknown_findings_five_catalog_rows():
    rows = unknown_findings("RuntimeError")
    assert [r["id"] for r in rows] == list(FINDING_IDS)
    assert all(r["status"] == "unknown" for r in rows)
    assert all("audit error:" in r["evidence"] for r in rows)
    by = _by_id(rows)
    assert by["jsonld-on-page"]["severity"] == "high"
    assert by["llms-txt"]["severity"] == "high"
    assert by["agent-json"]["severity"] == "info"


def test_probe_origin_urls_and_robots_reuse(monkeypatch):
    seen: list[str] = []

    def fake_probe(url: str) -> Probe:
        seen.append(url)
        return Probe(url=url, status_code=404, body="", error=None)

    monkeypatch.setattr("app.services.scan_audit.probe_url", fake_probe)
    probes = probe_origin("https://ex.com/shop/")
    assert "https://ex.com/shop/llms.txt" in seen
    assert "https://ex.com/shop/.well-known/agent.json" in seen
    assert "https://ex.com/shop/robots.txt" in seen
    assert "https://ex.com/shop/.well-known/tdmrep.json" in seen
    assert probes.as_stats()["origin_files_ok"] == 0

    seen.clear()
    reused = probe_origin("https://ex.com/shop/", robots_raw="User-agent: *\n")
    assert reused.robots is not None and reused.robots.status_code == 200
    assert "https://ex.com/shop/robots.txt" not in seen


def test_scan_status_template_findings_above_stats():
    src = (ROOT / "app" / "templates" / "scan_status.html").read_text(encoding="utf-8")
    assert 'id="findings"' in src
    assert src.index('id="findings"') < src.index("Stats")
    assert "tojson(indent=2)" in src
    assert "Scan completed with warnings" in src
    assert "cta_href" in src
    assert "completed-with-warnings" in src
    css = (ROOT / "app" / "static" / "styles.css").read_text(encoding="utf-8")
    assert ".pill-completed-with-warnings" in css
    assert ".pill-pass" in css
    assert ".pill-fail" in css
    assert ".pill-unknown" in css


def _session(tmp_path):
    eng = create_engine(f"sqlite:///{tmp_path / 's8.db'}", future=True)
    Base.metadata.create_all(bind=eng)
    return sessionmaker(bind=eng, future=True)


def _patch_scan(monkeypatch, Session, crawl, probes=None, probe_exc=None):
    monkeypatch.setattr("app.services.scan_runner.SessionLocal", Session)
    monkeypatch.setattr("app.services.scan_runner.crawl_site", crawl)
    monkeypatch.setattr("app.services.scan_runner.extract_heuristic", lambda pages: [])
    monkeypatch.setattr(
        "app.services.scan_runner.extract_with_llm", lambda *a, **k: ([], "")
    )
    if probe_exc is not None:

        def _boom(*_a, **_k):
            raise probe_exc

        monkeypatch.setattr("app.services.scan_runner.probe_origin", _boom)
    elif probes is not None:
        monkeypatch.setattr("app.services.scan_runner.probe_origin", lambda *a, **k: probes)


def _empty_probes() -> OriginProbes:
    return OriginProbes(
        robots=_probe("https://ex.com/robots.txt", 200, "User-agent: *\n"),
        llms_txt=_probe("https://ex.com/llms.txt", 404, ""),
        agent_json=_probe("https://ex.com/.well-known/agent.json", 404, ""),
        tdmrep=_probe("https://ex.com/.well-known/tdmrep.json", 404, ""),
    )


def _page(**kwargs) -> CrawledPage:
    data = dict(
        url="https://ex.com/",
        status_code=200,
        title="Home",
        content_text="Hello",
        json_ld=[],
        meta={},
    )
    data.update(kwargs)
    return CrawledPage(**data)


def _fake_crawl(pages, robots_raw=None):
    result = CrawlResult(
        pages=list(pages),
        robots_raw=robots_raw,
        stats={
            "requested": len(pages),
            "ok": len(pages),
            "errors": 0,
            "sitemap_count": 0,
            "max_pages": 40,
        },
    )

    async def _run(**_kwargs):
        return result

    return _run


def test_t30_status_and_probe_exception(tmp_path, monkeypatch):
    Session = _session(tmp_path)

    db = Session()
    site = Site(name="S8 Co", base_url="https://ex.com", max_pages=3)
    db.add(site)
    db.commit()
    site_id = site.id

    def _new_job():
        job = ScanJob(site_id=site_id, status="queued")
        db.add(job)
        db.commit()
        return job.id

    # High-severity fail (no JSON-LD + llms 404) → completed-with-warnings, not failed
    job_id = _new_job()
    _patch_scan(
        monkeypatch,
        Session,
        _fake_crawl([_page()]),
        probes=_empty_probes(),
    )
    run_scan_job(job_id)
    job = db.get(ScanJob, job_id)
    db.refresh(job)
    assert job.status == "completed-with-warnings"
    assert job.status != "failed"
    findings = job.stats_json["findings"]
    assert [f["id"] for f in findings] == list(FINDING_IDS)
    assert job.stats_json["origin_probes"]["origin_files_ok"] == 0

    # JSON-LD fail alone does not set failed (still warnings)
    jsonld = _by_id(findings)["jsonld-on-page"]
    assert jsonld["status"] == "fail"
    assert jsonld["severity"] == "high"

    # Crawl exception still failed
    job_id = _new_job()

    async def _fail_crawl(**_kwargs):
        raise RuntimeError("crawl down")

    _patch_scan(monkeypatch, Session, _fail_crawl, probes=_empty_probes())
    run_scan_job(job_id)
    job = db.get(ScanJob, job_id)
    db.refresh(job)
    assert job.status == "failed"
    assert job.error and "Crawl failed" in job.error

    # Merge exception still failed
    job_id = _new_job()
    _patch_scan(
        monkeypatch,
        Session,
        _fake_crawl([_page()]),
        probes=_empty_probes(),
    )

    def _fail_merge(*_a, **_k):
        raise RuntimeError("merge broke")

    monkeypatch.setattr("app.services.scan_runner.apply_extracted", _fail_merge)
    run_scan_job(job_id)
    job = db.get(ScanJob, job_id)
    db.refresh(job)
    assert job.status == "failed"

    # Probe exception after merge → completed + unknown findings, not failed
    monkeypatch.setattr(
        "app.services.scan_runner.apply_extracted",
        lambda *a, **k: {"created": 0, "updated": 0},
    )
    job_id = _new_job()
    _patch_scan(
        monkeypatch,
        Session,
        _fake_crawl([_page()]),
        probe_exc=RuntimeError("dns timeout"),
    )
    run_scan_job(job_id)
    job = db.get(ScanJob, job_id)
    db.refresh(job)
    assert job.status == "completed"
    unk = job.stats_json["findings"]
    assert [f["id"] for f in unk] == list(FINDING_IDS)
    assert all(f["status"] == "unknown" for f in unk)
    assert job.stats_json["origin_probes"] == {}

    # Rescan filter counts completed-with-warnings as a prior success
    prior = (
        db.query(ScanJob)
        .filter(
            ScanJob.site_id == site_id,
            ScanJob.status.in_(["completed", "completed-with-warnings"]),
        )
        .count()
    )
    assert prior >= 1
    job_id = _new_job()
    _patch_scan(
        monkeypatch,
        Session,
        _fake_crawl([_page(json_ld=[{"@type": "Organization", "name": "Acme"}])]),
        probes=OriginProbes(
            robots=_probe("https://ex.com/robots.txt", 200, "User-agent: *\n"),
            llms_txt=_probe("https://ex.com/llms.txt", 200, "# Title\n"),
            agent_json=_probe(
                "https://ex.com/.well-known/agent.json",
                200,
                json.dumps({"name": "Acme", "url": "https://ex.com", "knowledge": {}}),
            ),
            tdmrep=_probe("https://ex.com/.well-known/tdmrep.json", 404, ""),
        ),
    )
    run_scan_job(job_id)
    job = db.get(ScanJob, job_id)
    db.refresh(job)
    assert job.stats_json["is_rescan"] is True
    db.close()


def test_t30_template_cta_button_rule():
    src = (ROOT / "app" / "templates" / "scan_status.html").read_text(encoding="utf-8")
    assert "f.cta and f.cta_href" in src
    assert "elif f.cta" in src
