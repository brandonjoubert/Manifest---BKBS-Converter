#!/usr/bin/env python3
"""Claim Ledger Stage 4b contract: adapters exist in all three editions; 4c honest agent.json.

Exit 0 on PASS, 1 on FAIL. WordPress is checked statically (no WP bootstrap).
"""

from __future__ import annotations

import importlib
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTRACT_PATH = ROOT / "test-fixtures" / "stage4b_adapters_contract.json"


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
    mod = importlib.import_module(mod_name)
    fn = getattr(mod, attr, None)
    if fn is None:
        fail(f"python: missing {dotted}")
    return fn


def check_python(contract: dict) -> None:
    sys.path.insert(0, str(ROOT))
    py = contract["editions"]["python"]
    stub = contract["agent_json_must_contain"]
    for key, dotted in py["functions"].items():
        _import_attr(dotted)
    resolved = _import_attr(py["resolved_entity"])
    from app.exports.base import ResolvedEntity as ReExport

    if resolved is not ReExport:
        fail("python: app.exports.base.ResolvedEntity must be the same type")
    agent_src = (ROOT / "app/exports/agent_json.py").read_text(encoding="utf-8")
    if stub not in agent_src:
        fail(f"python: agent.json adapter lost {stub!r}")
    banned = contract.get("agent_json_must_not_contain")
    if banned and banned in agent_src:
        fail(f"python: agent.json adapter still contains {banned!r}")
    for rel in py["production_callers"]:
        src = (ROOT / rel).read_text(encoding="utf-8")
        if "from app.exports import" not in src and "from app.exports " not in src:
            fail(f"python: {rel} must import app.exports adapters")
    ok("python app.exports adapters + ResolvedEntity + production callers")


def check_php(contract: dict) -> None:
    php = contract["editions"]["php"]
    stub = contract["agent_json_must_contain"]
    for rel in php["source_files"]:
        p = ROOT / rel
        if not p.is_file():
            fail(f"php: missing {rel}")
    agent = (ROOT / "php/src/Exports/AgentJson.php").read_text(encoding="utf-8")
    if stub not in agent:
        fail(f"php: AgentJson lost {stub!r}")
    banned = contract.get("agent_json_must_not_contain")
    if banned and banned in agent:
        fail(f"php: AgentJson still contains {banned!r}")
    pub = (ROOT / php["production_caller"]).read_text(encoding="utf-8")
    for cls in ("LlmsTxt", "GraphJson", "SchemaOrg", "AgentJson", "Robots"):
        if f"Exports\\{cls}" not in pub:
            fail(f"php: Publisher must call Exports\\{cls}")
    boot = (ROOT / "php/src/bootstrap.php").read_text(encoding="utf-8")
    if "/Exports/" not in boot:
        fail("php: bootstrap must autoload Bkbs\\Exports\\ from php/src/Exports/")
    ok("php Exports adapters + Publisher call path")


def check_wordpress(contract: dict) -> None:
    wp = contract["editions"]["wordpress"]
    stub = contract["agent_json_must_contain"]
    for rel in wp["source_files"]:
        p = ROOT / rel
        if not p.is_file():
            fail(f"wordpress: missing {rel}")
        src = p.read_text(encoding="utf-8")
        class_name = None
        for name in wp["classes"].values():
            if f"class {name}" in src:
                class_name = name
                break
        if class_name is None and "class MBKBS_Export" not in src:
            fail(f"wordpress: {rel} missing export class")
    agent = (ROOT / "wordpress-plugin/manifest-bkbs-converter/includes/exports/class-mbkbs-export-agent.php").read_text(
        encoding="utf-8"
    )
    if stub not in agent:
        fail(f"wordpress: agent adapter lost {stub!r}")
    banned = contract.get("agent_json_must_not_contain")
    if banned and banned in agent:
        fail(f"wordpress: agent adapter still contains {banned!r}")
    pub = (ROOT / wp["production_caller"]).read_text(encoding="utf-8")
    for cls in wp["classes"].values():
        if cls not in pub:
            fail(f"wordpress: publisher must call {cls}")
    main = (ROOT / "wordpress-plugin/manifest-bkbs-converter/manifest-bkbs-converter.php").read_text(
        encoding="utf-8"
    )
    for rel in wp["source_files"]:
        leaf = Path(rel).name
        if leaf not in main:
            fail(f"wordpress: plugin bootstrap must require {leaf}")
    ok("wordpress export adapters + publisher call path")


def main() -> int:
    contract = load_contract()
    check_python(contract)
    check_php(contract)
    check_wordpress(contract)
    print("RESULT: PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
