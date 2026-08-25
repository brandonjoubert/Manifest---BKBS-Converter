"""/.well-known/agent.json adapter (Stage 4b).

Byte-stable: keep the current knowledge-index stub. Honest A2A card is Stage 4c/9.
"""

from __future__ import annotations

from typing import Any, Sequence


def build_agent_json(site: Any, entities: Sequence[Any]) -> dict[str, Any]:
    """Emerging agent.json capabilities manifest stub."""
    caps = [e for e in entities if e.entity_type == "capability"]
    return {
        "name": site.name,
        "description": f"Agent capabilities for {site.name}",
        "url": site.base_url,
        "version": "1.0",
        "protocol": "agent-web-protocol-stub",
        "capabilities": [
            {
                "id": c.id,
                "name": c.name,
                "description": c.description or c.name,
                "type": "service",
            }
            for c in caps
        ],
        "knowledge": {
            "llms_txt": f"{site.base_url}/llms.txt",
            "graph": f"{site.base_url}/graph.json",
            "schema_org": f"{site.base_url}/schema/organization.jsonld",
        },
    }
