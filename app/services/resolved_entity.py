"""ResolvedEntity — entity-shaped object for export builders (Stage 2)."""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import datetime
from typing import Any


@dataclass
class ResolvedEntity:
    """Attribute surface matching Entity fields used by export_* builders."""

    id: str
    entity_type: str
    name: str
    description: str | None = None
    properties: dict[str, Any] = field(default_factory=dict)
    relationships: list[Any] = field(default_factory=list)
    evidence: list[Any] = field(default_factory=list)
    version: int = 1
    trust_level: str = "medium"
    source: str = "scan"
    status: str = "approved"
    last_updated: datetime | None = None
    external_key: str = ""
    notes: str | None = None
    site_id: str = ""
