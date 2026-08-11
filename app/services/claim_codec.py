"""Canonical encode/decode for claim ledger attribute values (Stage 2)."""

from __future__ import annotations

import json
from typing import Any

PROP_PREFIX = "prop:"

# Scalar claim attributes (not properties bag keys)
SCALAR_ATTRS = frozenset(
    {
        "name",
        "description",
        "trust_level",
        "source",
        "status",
    }
)
JSON_ATTRS = frozenset({"relationships", "evidence"})


def encode_claim_value(value: Any) -> str:
    """Encode a claim value for storage in claims.value."""
    if value is None:
        return ""
    if isinstance(value, str):
        return value
    # Preserve key order (no sort_keys) so round-trip matches entity JSON for
    # text exports that stringify lists/dicts (llms-full.txt).
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"))


def decode_claim_value(raw: str | None) -> Any:
    """Decode a claim value from storage."""
    if raw is None or raw == "":
        return ""
    try:
        return json.loads(raw)
    except (json.JSONDecodeError, TypeError):
        return raw


def prop_attribute(key: str) -> str:
    return f"{PROP_PREFIX}{key}"


def is_prop_attribute(attr: str) -> bool:
    return attr.startswith(PROP_PREFIX)


def prop_key(attr: str) -> str:
    return attr[len(PROP_PREFIX) :]


def entity_attribute_pairs(entity: Any) -> list[tuple[str, str]]:
    """Yield (attribute, encoded_value) pairs for backfill from an entity-like object."""
    pairs: list[tuple[str, str]] = []
    name = getattr(entity, "name", None) or ""
    pairs.append(("name", encode_claim_value(name)))

    desc = getattr(entity, "description", None)
    if desc is not None and str(desc).strip() != "":
        pairs.append(("description", encode_claim_value(desc)))

    props = getattr(entity, "properties", None) or {}
    if isinstance(props, dict):
        for k, v in props.items():
            pairs.append((prop_attribute(str(k)), encode_claim_value(v)))

    rel = getattr(entity, "relationships", None)
    if rel is None:
        rel = []
    pairs.append(("relationships", encode_claim_value(rel)))

    ev = getattr(entity, "evidence", None)
    if ev is None:
        ev = []
    pairs.append(("evidence", encode_claim_value(ev)))

    for attr in ("trust_level", "source", "status"):
        val = getattr(entity, attr, None)
        if val is not None and str(val) != "":
            pairs.append((attr, encode_claim_value(val)))

    return pairs
