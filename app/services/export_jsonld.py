"""Shim — Stage 4b adapters live in app.exports.schema_org / agent_json."""

from app.exports.agent_json import build_agent_json
from app.exports.schema_org import build_organization_jsonld, build_services_jsonld

__all__ = [
    "build_organization_jsonld",
    "build_services_jsonld",
    "build_agent_json",
]
