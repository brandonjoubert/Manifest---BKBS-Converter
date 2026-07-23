"""Full BKBS knowledge graph JSON export."""

from __future__ import annotations

from datetime import datetime, timezone
from typing import Any

from app.models import Entity, Site


def entity_to_graph_node(entity: Entity, base_url: str) -> dict[str, Any]:
    return {
        "id": entity.id,
        "global_id": f"{base_url}/#entity-{entity.id}",
        "entity_type": entity.entity_type,
        "name": entity.name,
        "description": entity.description,
        "properties": entity.properties or {},
        "relationships": entity.relationships or [],
        "evidence": entity.evidence or [],
        "version": entity.version,
        "last_updated": entity.last_updated.isoformat() if entity.last_updated else None,
        "trust_level": entity.trust_level,
        "source": entity.source,
        "status": entity.status,
    }


def build_graph(site: Site, entities: list[Entity]) -> dict[str, Any]:
    return {
        "@context": {
            "bkbs": "https://example.com/bkbs/v1#",
            "schema": "https://schema.org/",
        },
        "bkbs_version": "1.0",
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "site": {
            "id": site.id,
            "name": site.name,
            "base_url": site.base_url,
        },
        "entity_count": len(entities),
        "entities": [entity_to_graph_node(e, site.base_url) for e in entities],
    }
