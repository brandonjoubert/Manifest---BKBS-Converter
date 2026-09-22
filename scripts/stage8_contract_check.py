#!/usr/bin/env python3
"""Claim Ledger Stage 8 contract: five-finding catalog + severity rules.

Exit 0 on PASS, 1 on FAIL. PHP/WP editions are skipped until later PRs.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTRACT_PATH = ROOT / "test-fixtures" / "stage8_findings_contract.json"


def fail(msg: str) -> None:
    print(f"FAIL: {msg}", file=sys.stderr)
    raise SystemExit(1)


def ok(msg: str) -> None:
    print(f"OK: {msg}")


def load_contract() -> dict:
    if not CONTRACT_PATH.is_file():
        fail(f"missing contract file {CONTRACT_PATH}")
    return json.loads(CONTRACT_PATH.read_text(encoding="utf-8"))


def _by_id(findings: list[dict]) -> dict[str, dict]:
    return {f["id"]: f for f in findings}


def check_python(contract: dict) -> None:
    sys.path.insert(0, str(ROOT))
    from app.services.scan_audit import (
        FINDING_IDS,
        STATUSES,
        AuditInput,
        Probe,
        evaluate_findings,
        has_high_severity_fail,
        unknown_findings,
    )

    if list(FINDING_IDS) != contract["ids"]:
        fail(f"python FINDING_IDS {list(FINDING_IDS)} != {contract['ids']}")
    if list(STATUSES) != contract["statuses"]:
        fail(f"python STATUSES {list(STATUSES)} != {contract['statuses']}")

    module = ROOT / contract["audit_modules"]["python"]
    text = module.read_text(encoding="utf-8")
    for sym in contract["forbidden_writer_symbols"]:
        if sym in text:
            fail(f"python audit module mentions forbidden writer {sym}")

    pages = [("https://ex.com/", [{"@type": "Organization", "name": "Acme"}])]
    llms_ok = Probe("https://ex.com/llms.txt", 200, "# Title\n")
    honest = json.dumps({"name": "Acme", "url": "https://ex.com", "knowledge": {}})
    stub = json.dumps(
        {"name": "Acme", "url": "https://ex.com", "knowledge": {}, "protocol": "a2a"}
    )

    def inp(**kwargs) -> AuditInput:
        data = dict(
            base_url="https://ex.com",
            site_id="s1",
            pages_json_ld=pages,
            html_ok_count=1,
            robots=Probe("https://ex.com/robots.txt", 200, "User-agent: *\n"),
            llms_txt=llms_ok,
            agent_json=Probe("https://ex.com/.well-known/agent.json", 200, honest),
            tdmrep=Probe("https://ex.com/.well-known/tdmrep.json", 404, ""),
        )
        data.update(kwargs)
        return AuditInput(**data)

    rules = contract["severity_rules"]
    catalog = evaluate_findings(inp())
    if [f["id"] for f in catalog] != contract["ids"]:
        fail(f"evaluate_findings order {[f['id'] for f in catalog]}")
    by = _by_id(catalog)
    for fid, rule in rules.items():
        if "always" in rule and by[fid]["severity"] != rule["always"]:
            fail(f"{fid} severity {by[fid]['severity']} != {rule['always']}")
    if by["jsonld-on-page"]["status"] != "pass":
        fail("jsonld-on-page should pass on valid blocks")
    if by["agent-json"]["severity"] != rules["agent-json"]["pass"]:
        fail("agent-json pass severity")

    missing = _by_id(
        evaluate_findings(
            inp(agent_json=Probe("https://ex.com/.well-known/agent.json", 404, ""))
        )
    )["agent-json"]
    if missing["status"] != "fail" or missing["severity"] != rules["agent-json"]["fail_missing"]:
        fail("agent-json 404 must be fail medium")
    stub_f = _by_id(
        evaluate_findings(
            inp(agent_json=Probe("https://ex.com/.well-known/agent.json", 200, stub))
        )
    )["agent-json"]
    if stub_f["status"] != "fail" or stub_f["severity"] != rules["agent-json"]["fail_stub"]:
        fail("agent-json stub must be fail high")
    if not has_high_severity_fail([stub_f]):
        fail("has_high_severity_fail false for stub")

    unk = unknown_findings("boom")
    if [f["id"] for f in unk] != contract["ids"]:
        fail("unknown_findings id order")
    if any(f["status"] != "unknown" for f in unk):
        fail("unknown_findings status")
    if _by_id(unk)["agent-json"]["severity"] != rules["agent-json"]["unknown"]:
        fail("agent-json unknown severity")
    ok("python catalog IDs, severity rules, no origin writers")


def check_php(_contract: dict) -> None:
    print("SKIP: php edition (later PR)")


def check_wp(_contract: dict) -> None:
    print("SKIP: wordpress edition (later PR)")


def main() -> int:
    contract = load_contract()
    check_python(contract)
    check_php(contract)
    check_wp(contract)
    print("Stage 8 contract: PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
