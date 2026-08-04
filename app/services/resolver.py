"""Claim Ledger resolver (Stage 1 stub).

Stage 1: always returns None (no claim resolution yet).
Stage 2+ will reconstruct current entity truth from approved claims.
"""

from __future__ import annotations

from datetime import datetime
from typing import Any


def resolve_entity(
    entity_id: str | None,
    as_of: datetime | None = None,
) -> dict[str, Any] | None:
    """Resolve an entity's current attributes from the claims ledger.

    Stage 1 stub: returns None for any input (including empty id / as_of).
    Does not raise. Does not read the database yet.
    """
    _ = entity_id, as_of
    return None
