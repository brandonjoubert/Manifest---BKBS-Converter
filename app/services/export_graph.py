"""Shim — Stage 4b adapters live in app.exports.graph_json."""

from app.exports.graph_json import build_graph, entity_to_graph_node

__all__ = ["build_graph", "entity_to_graph_node"]
