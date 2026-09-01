"""Claim Ledger Stage 5 — claim-level diff (old = last approved, new = pending)."""

from __future__ import annotations

import json
from typing import Any

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.models import Claim
from app.services.claim_codec import decode_claim_value, is_prop_attribute, prop_key

SCALAR_ORDER = ("name", "description", "trust_level", "source")
JSON_ATTRS = frozenset({"relationships", "evidence"})


def attribute_label(attr: str) -> str:
    if is_prop_attribute(attr):
        return prop_key(attr)
    return attr.replace("_", " ").title()


def display_value(raw: str | None) -> str:
    if raw is None or raw == "":
        return ""
    decoded = decode_claim_value(raw)
    if isinstance(decoded, (dict, list)):
        return json.dumps(decoded, ensure_ascii=False, indent=2)
    return str(decoded)


def encode_submitted(attr: str, text: str) -> str:
    text = text if text is not None else ""
    if attr in JSON_ATTRS or (is_prop_attribute(attr) and text.lstrip().startswith(("{", "["))):
        try:
            return json.dumps(json.loads(text), ensure_ascii=False, separators=(",", ":"))
        except (json.JSONDecodeError, TypeError):
            return text
    return text


def _sort_attrs(attrs: list[str]) -> list[str]:
    rank = {a: i for i, a in enumerate(SCALAR_ORDER)}
    def key(a: str) -> tuple[int, str]:
        if a in rank:
            return (0, f"{rank[a]:02d}")
        if is_prop_attribute(a):
            return (1, prop_key(a).lower())
        if a in JSON_ATTRS:
            return (2, a)
        return (3, a)
    return sorted(attrs, key=key)


def _latest_maps(
    db: Session, entity_ids: list[str], status: str
) -> dict[str, dict[str, Claim]]:
    if not entity_ids:
        return {}
    rows = list(
        db.scalars(
            select(Claim).where(
                Claim.entity_id.in_(entity_ids),
                Claim.status == status,
            )
        ).all()
    )
    out: dict[str, dict[str, Claim]] = {}
    for c in rows:
        by = out.setdefault(c.entity_id, {})
        prev = by.get(c.attribute)
        if prev is None or (c.id or 0) > (prev.id or 0):
            by[c.attribute] = c
    return out


def has_approved_claims(db: Session, entity_id: str) -> bool:
    row = db.scalars(
        select(Claim.id).where(
            Claim.entity_id == entity_id,
            Claim.status == "approved",
        ).limit(1)
    ).first()
    return row is not None


def can_bulk_approve(db: Session, entity_id: str) -> bool:
    """New entities only: no approved claims yet (cannot launder needs_edit diffs)."""
    return not has_approved_claims(db, entity_id)


def claim_diff_for_entity(db: Session, entity_id: str) -> dict[str, Any]:
    diffs = claim_diffs_for_entities(db, [entity_id])
    return diffs.get(entity_id) or {
        "entity_id": entity_id,
        "is_new_entity": True,
        "has_pending": False,
        "can_bulk_approve": True,
        "summary": "No claims",
        "display_name": "",
        "changes": [],
        "unchanged": [],
    }


def claim_diffs_for_entities(db: Session, entity_ids: list[str]) -> dict[str, dict[str, Any]]:
    """old = latest approved claim (else not published); new = latest pending.

    Never uses entity attribute columns as the review baseline.
    """
    approved = _latest_maps(db, entity_ids, "approved")
    pending = _latest_maps(db, entity_ids, "pending")
    out: dict[str, dict[str, Any]] = {}
    for eid in entity_ids:
        ap = approved.get(eid, {})
        pe = pending.get(eid, {})
        attrs = _sort_attrs(list(set(ap) | set(pe)))
        changes: list[dict[str, Any]] = []
        unchanged: list[dict[str, Any]] = []
        for attr in attrs:
            old_c = ap.get(attr)
            new_c = pe.get(attr)
            old_raw = old_c.value if old_c is not None else None
            new_raw = new_c.value if new_c is not None else None
            if new_c is None:
                unchanged.append(
                    {
                        "attribute": attr,
                        "label": attribute_label(attr),
                        "value": old_raw or "",
                        "display": display_value(old_raw),
                    }
                )
                continue
            kind = "new" if old_c is None else "changed"
            if old_c is not None and old_raw == new_raw:
                unchanged.append(
                    {
                        "attribute": attr,
                        "label": attribute_label(attr),
                        "value": old_raw or "",
                        "display": display_value(old_raw),
                    }
                )
                continue
            changes.append(
                {
                    "attribute": attr,
                    "label": attribute_label(attr),
                    "kind": kind,
                    "old": old_raw,
                    "new": new_raw or "",
                    "old_display": display_value(old_raw) if old_raw is not None else None,
                    "new_display": display_value(new_raw),
                    "extraction_method": (new_c.extraction_method or "scan"),
                }
            )
        is_new = not bool(ap)
        n = len(changes)
        if is_new and n:
            summary = f"New entity · {n} fact{'s' if n != 1 else ''}"
        elif n:
            labels = ", ".join(c["label"] for c in changes[:3])
            extra = f" +{n - 3}" if n > 3 else ""
            summary = f"{n} change{'s' if n != 1 else ''}: {labels}{extra}"
        else:
            summary = "No pending changes"
        name = None
        if "name" in pe:
            name = pe["name"].value
        elif "name" in ap:
            name = ap["name"].value
        out[eid] = {
            "entity_id": eid,
            "is_new_entity": is_new,
            "has_pending": bool(pe),
            "can_bulk_approve": is_new,
            "summary": summary,
            "display_name": name or "",
            "changes": changes,
            "unchanged": unchanged,
        }
    return out
