"""JSON-LD HTML snippet (Stage 4c). Script-safe: '<' → \\u003c so </script> cannot break out."""

from __future__ import annotations

import json
from typing import Any, Sequence

from app.exports.schema_org import build_organization_jsonld


def script_safe_json(data: Any) -> str:
    text = json.dumps(data, ensure_ascii=False, separators=(",", ":"))
    return (
        text.replace("<", "\\u003c")
        .replace("\u2028", "\\u2028")
        .replace("\u2029", "\\u2029")
    )


def organization_jsonld_snippet(site: Any, entities: Sequence[Any]) -> str:
    payload = script_safe_json(build_organization_jsonld(site, entities))
    return f'<script type="application/ld+json">{payload}</script>\n'
