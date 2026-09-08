"""/.well-known/agent.json — honest knowledge index (Stage 4c).

Not an A2A agent card: no stub protocol, no endpoint, no fake capabilities list.
"""

from __future__ import annotations

from typing import Any, Sequence


def build_agent_json(site: Any, entities: Sequence[Any]) -> dict[str, Any]:
    """Knowledge-index pointers for origin files. `entities` unused (signature stable)."""
    _ = entities
    base = str(site.base_url).rstrip("/")
    return {
        "name": site.name,
        "url": site.base_url,
        "knowledge": {
            "llms_txt": f"{base}/llms.txt",
            "llms_full": f"{base}/llms-full.txt",
            "graph": f"{base}/graph.json",
            "schema_organization": f"{base}/schema/organization.jsonld",
            "schema_services": f"{base}/schema/services.jsonld",
        },
    }
