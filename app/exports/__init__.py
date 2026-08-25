"""Stage 4b export adapters — one production call path (ResolvedEntity duck-type)."""

from app.exports.agent_json import build_agent_json
from app.exports.base import ResolvedEntity
from app.exports.graph_json import build_graph, entity_to_graph_node
from app.exports.llms_txt import render_llms_full, render_llms_txt
from app.exports.robots import merge_robots, robots_suggestion
from app.exports.schema_org import build_organization_jsonld, build_services_jsonld

__all__ = [
    "ResolvedEntity",
    "render_llms_txt",
    "render_llms_full",
    "build_graph",
    "entity_to_graph_node",
    "build_organization_jsonld",
    "build_services_jsonld",
    "build_agent_json",
    "robots_suggestion",
    "merge_robots",
]
