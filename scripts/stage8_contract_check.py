#!/usr/bin/env python3
"""Claim Ledger Stage 8 contract: five-finding catalog + severity rules.

Exit 0 on PASS, 1 on FAIL. WordPress is skipped until a later PR.
"""

from __future__ import annotations

import json
import shutil
import subprocess
import sys
import tempfile
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


def check_php(contract: dict) -> None:
    module = ROOT / contract["audit_modules"]["php"]
    if not module.is_file():
        fail(f"missing php audit module {module}")
    text = module.read_text(encoding="utf-8")
    for sym in contract["forbidden_writer_symbols"]:
        if sym in text:
            fail(f"php audit module mentions forbidden writer {sym}")

    php = shutil.which("php")
    if php is None:
        fail("php binary required for Stage 8 PHP contract")

    bootstrap = ROOT / "php" / "src" / "bootstrap.php"
    code = f"""<?php
declare(strict_types=1);
require {json.dumps(str(bootstrap))};
$ids = Bkbs\\ScanAudit::FINDING_IDS;
$statuses = Bkbs\\ScanAudit::STATUSES;
$pages = [['https://ex.com/', [['@type' => 'Organization', 'name' => 'Acme']]]];
$llmsOk = ['url' => 'https://ex.com/llms.txt', 'status' => 200, 'body' => "# Title\\n", 'error' => null];
$honest = json_encode(['name' => 'Acme', 'url' => 'https://ex.com', 'knowledge' => new stdClass()]);
$stub = json_encode(['name' => 'Acme', 'url' => 'https://ex.com', 'knowledge' => new stdClass(), 'protocol' => 'a2a']);
$base = [
  'base_url' => 'https://ex.com',
  'site_id' => 's1',
  'pages_json_ld' => $pages,
  'html_ok_count' => 1,
  'robots' => ['url' => 'https://ex.com/robots.txt', 'status' => 200, 'body' => "User-agent: *\\n", 'error' => null],
  'llms_txt' => $llmsOk,
  'agent_json' => ['url' => 'https://ex.com/.well-known/agent.json', 'status' => 200, 'body' => $honest, 'error' => null],
  'tdmrep' => ['url' => 'https://ex.com/.well-known/tdmrep.json', 'status' => 404, 'body' => '', 'error' => null],
];
$catalog = Bkbs\\ScanAudit::evaluateFindings($base);
$by = [];
$sevs = [];
foreach ($catalog as $f) {{ $by[$f['id']] = $f; $sevs[$f['id']] = $f['severity']; }}
$missingInp = $base;
$missingInp['agent_json'] = ['url' => 'https://ex.com/.well-known/agent.json', 'status' => 404, 'body' => '', 'error' => null];
$missing = null;
foreach (Bkbs\\ScanAudit::evaluateFindings($missingInp) as $f) {{
  if ($f['id'] === 'agent-json') {{ $missing = $f; }}
}}
$stubInp = $base;
$stubInp['agent_json'] = ['url' => 'https://ex.com/.well-known/agent.json', 'status' => 200, 'body' => $stub, 'error' => null];
$stubF = null;
foreach (Bkbs\\ScanAudit::evaluateFindings($stubInp) as $f) {{
  if ($f['id'] === 'agent-json') {{ $stubF = $f; }}
}}
$unk = Bkbs\\ScanAudit::unknownFindings('boom');
$unkBy = [];
foreach ($unk as $f) {{ $unkBy[$f['id']] = $f; }}
echo json_encode([
  'ids' => $ids,
  'statuses' => $statuses,
  'catalog_ids' => array_column($catalog, 'id'),
  'jsonld_status' => $by['jsonld-on-page']['status'] ?? null,
  'severities' => $sevs,
  'agent_pass_sev' => $by['agent-json']['severity'] ?? null,
  'agent_missing' => $missing,
  'agent_stub' => $stubF,
  'stub_high_fail' => Bkbs\\ScanAudit::hasHighSeverityFail([$stubF]),
  'unk_ids' => array_column($unk, 'id'),
  'unk_statuses' => array_column($unk, 'status'),
  'unk_agent_sev' => $unkBy['agent-json']['severity'] ?? null,
  'cta_href' => $by['aipref-robots']['cta_href'] ?? '',
], JSON_UNESCAPED_SLASHES);
"""
    with tempfile.NamedTemporaryFile("w", suffix=".php", encoding="utf-8", delete=False) as tmp:
        tmp.write(code)
        tmp_path = tmp.name
    try:
        proc = subprocess.run(
            [php, tmp_path],
            capture_output=True,
            text=True,
            cwd=str(ROOT),
        )
    finally:
        Path(tmp_path).unlink(missing_ok=True)
    if proc.returncode != 0:
        fail(f"php evaluate failed: {proc.stderr or proc.stdout}")
    try:
        data = json.loads(proc.stdout)
    except json.JSONDecodeError:
        fail(f"php evaluate did not return JSON: {proc.stdout!r} {proc.stderr!r}")

    if list(data.get("ids") or []) != contract["ids"]:
        fail(f"php FINDING_IDS {data.get('ids')} != {contract['ids']}")
    if list(data.get("statuses") or []) != contract["statuses"]:
        fail(f"php STATUSES {data.get('statuses')} != {contract['statuses']}")
    if list(data.get("catalog_ids") or []) != contract["ids"]:
        fail(f"php evaluate_findings order {data.get('catalog_ids')}")

    rules = contract["severity_rules"]
    sevs = data.get("severities") or {}
    for fid, rule in rules.items():
        if "always" in rule and sevs.get(fid) != rule["always"]:
            fail(f"php {fid} severity {sevs.get(fid)} != {rule['always']}")
    if data.get("jsonld_status") != "pass":
        fail("php jsonld-on-page should pass on valid blocks")
    if data.get("agent_pass_sev") != rules["agent-json"]["pass"]:
        fail("php agent-json pass severity")

    missing = data.get("agent_missing") or {}
    if missing.get("status") != "fail" or missing.get("severity") != rules["agent-json"]["fail_missing"]:
        fail("php agent-json 404 must be fail medium")
    stub_f = data.get("agent_stub") or {}
    if stub_f.get("status") != "fail" or stub_f.get("severity") != rules["agent-json"]["fail_stub"]:
        fail("php agent-json stub must be fail high")
    if not data.get("stub_high_fail"):
        fail("php has_high_severity_fail false for stub")

    if list(data.get("unk_ids") or []) != contract["ids"]:
        fail("php unknown_findings id order")
    if any(s != "unknown" for s in (data.get("unk_statuses") or [])):
        fail("php unknown_findings status")
    if data.get("unk_agent_sev") != rules["agent-json"]["unknown"]:
        fail("php agent-json unknown severity")
    href = str(data.get("cta_href") or "")
    if "index.php?r=" not in href or "machine-layers" not in href:
        fail(f"php cta_href should use index.php?r= and #machine-layers, got {href!r}")
    ok("php catalog IDs, severity rules, no origin writers")


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
