"""Stage 4b/4c export adapters — one production call path (ResolvedEntity duck-type)."""

from app.exports.agent_json import build_agent_json
from app.exports.base import ResolvedEntity
from app.exports.graph_json import build_graph, entity_to_graph_node
from app.exports.jsonld_snippet import organization_jsonld_snippet, script_safe_json
from app.exports.llms_txt import render_llms_full, render_llms_txt
from app.exports.robots import (
    content_usage_line,
    managed_robots_block,
    merge_robots,
    robots_suggestion,
)
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
    "organization_jsonld_snippet",
    "script_safe_json",
    "robots_suggestion",
    "managed_robots_block",
    "content_usage_line",
    "merge_robots",
]
