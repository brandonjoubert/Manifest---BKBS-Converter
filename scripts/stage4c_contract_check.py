#!/usr/bin/env python3
"""Claim Ledger Stage 4c contract: snippet, honest agent.json, AIPREF default off.

Exit 0 on PASS, 1 on FAIL. WordPress is checked statically (no WP bootstrap).
"""

from __future__ import annotations

import importlib
import json
import sys
from pathlib import Path
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parents[1]
CONTRACT_PATH = ROOT / "test-fixtures" / "stage4c_artifacts_contract.json"


def fail(msg: str) -> None:
    print(f"FAIL: {msg}", file=sys.stderr)
    raise SystemExit(1)


def ok(msg: str) -> None:
    print(f"OK: {msg}")


def load_contract() -> dict:
    if not CONTRACT_PATH.is_file():
        fail(f"missing contract file {CONTRACT_PATH}")
    return json.loads(CONTRACT_PATH.read_text(encoding="utf-8"))


def _import_attr(dotted: str):
    mod_name, _, attr = dotted.rpartition(".")
    sys.path.insert(0, str(ROOT))
    mod = importlib.import_module(mod_name)
    fn = getattr(mod, attr, None)
    if fn is None:
        fail(f"python: missing {dotted}")
    return fn


def check_python(contract: dict) -> None:
    spec = contract["agent_json"]
    site = SimpleNamespace(
        name="Acme",
        base_url="https://acme.example",
        aipref_search=False,
        aipref_ai_input=False,
        aipref_train_ai=False,
    )
    build_agent = _import_attr(contract["editions"]["python"]["agent"])
    data = build_agent(site, [])
    for k in spec["required_keys"]:
        if k not in data:
            fail(f"python agent.json missing {k}")
    for k in spec["forbidden_keys"]:
        if k in data:
            fail(f"python agent.json still has forbidden {k}")
    knowledge = data.get("knowledge") or {}
    for k in spec["knowledge_required"]:
        if k not in knowledge:
            fail(f"python agent.json knowledge missing {k}")

    snippet_fn = _import_attr(contract["editions"]["python"]["snippet"])
    cap = SimpleNamespace(
        id="e1",
        entity_type="capability",
        name="X",
        description="</script>",
        properties={},
        relationships=[],
        evidence=[],
        status="approved",
    )
    snippet = snippet_fn(site, [cap])
    if "</script>" in snippet[snippet.find(">") + 1 : snippet.rfind("</script>")]:
        fail("python snippet contains raw </script> inside JSON")
    if "\\u003c" not in snippet:
        fail("python snippet did not unicode-escape '<'")

    block_fn = _import_attr(contract["editions"]["python"]["robots_block"])
    block = block_fn(site)
    if "Content-Usage:" in block:
        fail("python AIPREF default is not off")
    if "# END BKBS" not in block:
        fail("python robots block missing # END BKBS")
    ok("python honest agent.json + snippet escape + AIPREF off")


def check_sources(contract: dict) -> None:
    php = contract["editions"]["php"]
    wp = contract["editions"]["wordpress"]
    for rel in php["source_files"] + wp["source_files"]:
        p = ROOT / rel
        if not p.is_file():
            fail(f"missing {rel}")
        text = p.read_text(encoding="utf-8")
        if "AgentJson" in rel or "export-agent" in rel:
            if "agent-web-protocol-stub" in text:
                fail(f"{rel} still emits stub protocol")
            if "'endpoint'" in text or '"endpoint"' in text:
                fail(f"{rel} still has A2A endpoint")
        if "JsonLd" in rel or "jsonld" in rel:
            if "u003c" not in text:
                fail(f"{rel} must escape '<' as \\u003c")
        if "Robots" in rel or "robots" in rel:
            if "END BKBS" not in text:
                fail(f"{rel} must write # END BKBS")
            if "Content-Usage" not in text:
                fail(f"{rel} must support AIPREF Content-Usage")
    plugin = (ROOT / "wordpress-plugin/manifest-bkbs-converter/includes/class-mbkbs-plugin.php").read_text(
        encoding="utf-8"
    )
    if "maybe_print_jsonld" not in plugin:
        fail("wordpress: wp_head inject hook missing")
    if "filter_robots_txt" not in plugin:
        fail("wordpress: robots_txt filter missing")
    admin = (ROOT / "wordpress-plugin/manifest-bkbs-converter/admin/class-mbkbs-admin.php").read_text(
        encoding="utf-8"
    )
    if "jsonld.wp_head" not in admin:
        fail("wordpress: jsonld.wp_head setting not saved")
    if "jsonld.wp_head', '0'" not in (
        ROOT / "wordpress-plugin/manifest-bkbs-converter/includes/class-mbkbs-publisher.php"
    ).read_text(encoding="utf-8"):
        fail("wordpress: jsonld inject default is not off")
    ok("php + wordpress 4c sources + WP inject default off")


def main() -> int:
    contract = load_contract()
    check_python(contract)
    check_sources(contract)
    print("Stage 4c contract: PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
