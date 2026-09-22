#!/usr/bin/env python3
"""Claim Ledger Stage 8 contract: five-finding catalog + severity rules.

Exit 0 on PASS, 1 on FAIL. PHP edition is skipped until a later PR.
"""

from __future__ import annotations

import json
import subprocess
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


def check_wp(contract: dict) -> None:
    module = ROOT / contract["audit_modules"]["wordpress"]
    if not module.is_file():
        fail(f"missing wordpress audit module {module}")
    text = module.read_text(encoding="utf-8")
    for fid in contract["ids"]:
        if f"'{fid}'" not in text:
            fail(f"wordpress FINDING_IDS missing {fid}")
    for status in contract["statuses"]:
        if f"'{status}'" not in text:
            fail(f"wordpress STATUSES missing {status}")
    for sym in contract["forbidden_writer_symbols"]:
        if sym in text:
            fail(f"wordpress audit module mentions forbidden writer {sym}")

    dash = ROOT / "wordpress-plugin/manifest-bkbs-converter/admin/views/dashboard.php"
    dash_text = dash.read_text(encoding="utf-8")
    if 'id="jsonld_wp_head"' not in dash_text:
        fail("dashboard checkbox missing id=jsonld_wp_head")
    pub = ROOT / "wordpress-plugin/manifest-bkbs-converter/includes/class-mbkbs-publisher.php"
    pub_text = pub.read_text(encoding="utf-8")
    if "jsonld.wp_head" not in pub_text:
        fail("wordpress jsonld.wp_head missing from publisher")
    if "maybe_print_jsonld" not in pub_text:
        fail("maybe_print_jsonld must remain on publisher (not reimplemented in audit)")
    admin = ROOT / "wordpress-plugin/manifest-bkbs-converter/admin/class-mbkbs-admin.php"
    admin_text = admin.read_text(encoding="utf-8")
    if "jsonld.wp_head" not in admin_text:
        fail("wordpress jsonld.wp_head missing from admin settings")

    php = [
        "php",
        "-r",
        f"""
        define('ABSPATH', sys_get_temp_dir() . '/');
        require {json.dumps(str(module))};
        $ids = MBKBS_Scan_Audit::FINDING_IDS;
        if ($ids !== {json.dumps(contract["ids"])}) {{ fwrite(STDERR, 'ids'); exit(1); }}
        $honest = json_encode(array('name'=>'Acme','url'=>'https://ex.com','knowledge'=>new stdClass()));
        $stub = json_encode(array('name'=>'Acme','url'=>'https://ex.com','knowledge'=>new stdClass(),'protocol'=>'a2a'));
        $base = array(
            'base_url'=>'https://ex.com','site_id'=>'s1',
            'pages_json_ld'=>array(array('https://ex.com/', array(array('@type'=>'Organization','name'=>'Acme')))),
            'html_ok_count'=>1,
            'robots'=>array('url'=>'https://ex.com/robots.txt','status'=>200,'body'=>"User-agent: *\\n",'error'=>null),
            'llms_txt'=>array('url'=>'https://ex.com/llms.txt','status'=>200,'body'=>"# Title\\n",'error'=>null),
            'agent_json'=>array('url'=>'https://ex.com/.well-known/agent.json','status'=>200,'body'=>$honest,'error'=>null),
            'tdmrep'=>array('url'=>'https://ex.com/.well-known/tdmrep.json','status'=>404,'body'=>'','error'=>null),
        );
        $by = array();
        foreach (MBKBS_Scan_Audit::evaluate_findings($base) as $f) {{ $by[$f['id']] = $f; }}
        $rules = json_decode({json.dumps(json.dumps(contract["severity_rules"]))}, true);
        foreach ($rules as $fid => $rule) {{
            if (isset($rule['always']) && $by[$fid]['severity'] !== $rule['always']) {{ fwrite(STDERR, $fid); exit(1); }}
        }}
        if ($by['agent-json']['severity'] !== $rules['agent-json']['pass']) {{ fwrite(STDERR, 'agent pass'); exit(1); }}
        $base['agent_json']['status'] = 404; $base['agent_json']['body'] = '';
        $m = null;
        foreach (MBKBS_Scan_Audit::evaluate_findings($base) as $f) {{ if ($f['id']==='agent-json') $m = $f; }}
        if ($m['status'] !== 'fail' || $m['severity'] !== $rules['agent-json']['fail_missing']) {{ fwrite(STDERR, 'agent 404'); exit(1); }}
        $base['agent_json']['status'] = 200; $base['agent_json']['body'] = $stub;
        $s = null;
        foreach (MBKBS_Scan_Audit::evaluate_findings($base) as $f) {{ if ($f['id']==='agent-json') $s = $f; }}
        if ($s['status'] !== 'fail' || $s['severity'] !== $rules['agent-json']['fail_stub']) {{ fwrite(STDERR, 'agent stub'); exit(1); }}
        if (!MBKBS_Scan_Audit::has_high_severity_fail(array($s))) {{ fwrite(STDERR, 'high fail'); exit(1); }}
        $unk = MBKBS_Scan_Audit::unknown_findings('boom');
        $u = null;
        foreach ($unk as $f) {{ if ($f['id']==='agent-json') $u = $f; }}
        if ($u['severity'] !== $rules['agent-json']['unknown'] || $u['status'] !== 'unknown') {{ fwrite(STDERR, 'agent unk'); exit(1); }}
        echo 'ok';
        """,
    ]
    proc = subprocess.run(php, cwd=str(ROOT), capture_output=True, text=True)
    if proc.returncode != 0 or "ok" not in (proc.stdout or ""):
        fail(
            "wordpress evaluate_findings severity rules: "
            + ((proc.stderr or proc.stdout or "").strip() or f"exit {proc.returncode}")
        )
    ok("wordpress catalog IDs, severity rules, jsonld.wp_head present")


def main() -> int:
    contract = load_contract()
    check_python(contract)
    check_php(contract)
    check_wp(contract)
    print("Stage 8 contract: PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
