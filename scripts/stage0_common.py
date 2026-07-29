"""Shared helpers for Claim Ledger Stage 0 golden export capture/verify."""

from __future__ import annotations

import hashlib
import json
import re
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
FIXTURES = ROOT / "test-fixtures"
FIXTURE_JSON = FIXTURES / "stage0_site.json"
GOLDEN_ZIP = FIXTURES / "golden-v0.zip"
GOLDEN_DIR = FIXTURES / "golden-v0"
ENTITY_COUNTS_JSON = FIXTURES / "entity-counts.json"
STAGE0_REPORT = FIXTURES / "stage0-baseline.json"

# Files compared in golden package (export package layout)
COMPARE_FILES = (
    "llms.txt",
    "llms-full.txt",
    "graph.json",
    "schema/organization.jsonld",
    "schema/services.jsonld",
    ".well-known/agent.json",
    "robots.txt.suggestion",
    "sitemap.xml.suggestion",
    "README.md",
)


def load_fixture() -> dict:
    return json.loads(FIXTURE_JSON.read_text(encoding="utf-8"))


def expected_entity_counts(fixture: dict | None = None) -> dict:
    fixture = fixture or load_fixture()
    counts: dict[str, int] = {}
    for e in fixture["entities"]:
        st = e["status"]
        counts[st] = counts.get(st, 0) + 1
    return {
        "site_id": fixture["site"]["id"],
        "site_name": fixture["site"]["name"],
        "by_status": counts,
        "total": len(fixture["entities"]),
        "approved": counts.get("approved", 0),
        "pending": counts.get("pending", 0),
    }


def normalize_text(name: str, content: str) -> str:
    """Strip volatile fields so golden compare is stable."""
    text = content.replace("\r\n", "\n")
    if name.endswith(".json") or name.endswith(".jsonld"):
        try:
            data = json.loads(text)
        except json.JSONDecodeError:
            return text
        data = _strip_volatile(data)
        return json.dumps(data, indent=2, sort_keys=True, ensure_ascii=False) + "\n"
    # Timestamps / generated banners in text files
    text = re.sub(
        r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?",
        "<TIMESTAMP>",
        text,
    )
    return text


def _strip_volatile(obj):
    if isinstance(obj, dict):
        out = {}
        for k, v in obj.items():
            if k in ("generated_at", "last_updated", "created_at", "fetched_at", "changed_at"):
                continue
            out[k] = _strip_volatile(v)
        return out
    if isinstance(obj, list):
        return [_strip_volatile(x) for x in obj]
    return obj


def zip_to_normalized_map(zip_path: Path) -> dict[str, str]:
    result: dict[str, str] = {}
    with zipfile.ZipFile(zip_path, "r") as zf:
        names = set(zf.namelist())
        for rel in COMPARE_FILES:
            # zip may store without leading ./
            candidates = [rel, rel.lstrip("./")]
            found = None
            for c in candidates:
                if c in names:
                    found = c
                    break
            if not found:
                # try suffix match
                for n in names:
                    if n.endswith(rel) or n.endswith(rel.replace("/", "\\")):
                        found = n
                        break
            if not found:
                result[rel] = ""
                continue
            raw = zf.read(found).decode("utf-8", errors="replace")
            result[rel] = normalize_text(rel, raw)
    return result


def dir_to_normalized_map(dir_path: Path) -> dict[str, str]:
    result: dict[str, str] = {}
    for rel in COMPARE_FILES:
        p = dir_path / rel
        if not p.exists():
            result[rel] = ""
            continue
        raw = p.read_text(encoding="utf-8")
        result[rel] = normalize_text(rel, raw)
    return result


def maps_equal(a: dict[str, str], b: dict[str, str]) -> tuple[bool, list[str]]:
    diffs: list[str] = []
    keys = sorted(set(a) | set(b))
    for k in keys:
        if a.get(k, "") != b.get(k, ""):
            diffs.append(k)
    return (len(diffs) == 0, diffs)


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    h.update(path.read_bytes())
    return h.hexdigest()
