"""Stage 9 capability layer: approved-claim search, A2A card, MCP, NLWeb /ask.

Off by default. This module does not read the database and does not publish files.
Callers pass the public resolve set (include_pending=False) only.
"""

from __future__ import annotations

import json
import uuid
from typing import Any

MAX_FACTS = 50

CARD_KEYS = (
    "protocolVersion",
    "name",
    "description",
    "url",
    "version",
    "capabilities",
    "defaultInputModes",
    "defaultOutputModes",
    "skills",
    "securitySchemes",
    "security",
)

SKILL_ID = "search-approved-facts"
PROTOCOL_VERSION = "0.3.0"
MCP_PROTOCOL = "2025-06-18"


def capability_enabled(site: Any) -> bool:
    return bool(getattr(site, "capability_enabled", False))


def public_fact(entity: Any) -> dict[str, Any]:
    """Approved surface only. Drops notes and other operator fields."""
    if isinstance(entity, dict):
        props = entity.get("properties") or {}
        name = entity.get("name") or ""
        description = entity.get("description") or ""
        entity_type = entity.get("entity_type") or ""
        entity_id = entity.get("id") or ""
    else:
        props = getattr(entity, "properties", None) or {}
        name = getattr(entity, "name", "") or ""
        description = getattr(entity, "description", "") or ""
        entity_type = getattr(entity, "entity_type", "") or ""
        entity_id = getattr(entity, "id", "") or ""
    if not isinstance(props, dict):
        props = {}
    return {
        "id": str(entity_id),
        "entity_type": str(entity_type),
        "name": str(name),
        "description": str(description),
        "properties": props,
    }


def _haystack(fact: dict[str, Any]) -> str:
    blob = json.dumps(fact["properties"], ensure_ascii=False, default=str)
    return " ".join(
        [fact["name"], fact["description"], fact["entity_type"], blob]
    ).lower()


def search_approved(entities: list[Any], query: str) -> list[dict[str, Any]]:
    """Every whitespace token must appear. Empty query lists the public set."""
    facts = [public_fact(e) for e in entities]
    tokens = [t for t in (query or "").strip().lower().split() if t]
    if not tokens:
        return facts[:MAX_FACTS]
    out: list[dict[str, Any]] = []
    for fact in facts:
        hay = _haystack(fact)
        if all(t in hay for t in tokens):
            out.append(fact)
            if len(out) >= MAX_FACTS:
                break
    return out


def build_agent_card(name: str, endpoint_url: str) -> dict[str, Any]:
    """A2A Agent Card for a real message/send endpoint. Not a knowledge index."""
    return {
        "protocolVersion": PROTOCOL_VERSION,
        "name": name,
        "description": (
            "Answers from approved BKBS claims only. "
            "Does not invent facts and does not read pending review items."
        ),
        "url": endpoint_url,
        "version": "1.0.0",
        "capabilities": {
            "streaming": False,
            "pushNotifications": False,
            "stateTransitionHistory": False,
        },
        "defaultInputModes": ["text/plain"],
        "defaultOutputModes": ["text/plain", "application/json"],
        "skills": [
            {
                "id": SKILL_ID,
                "name": "Search approved facts",
                "description": "Return approved resolved claims that match the question.",
                "tags": ["bkbs", "knowledge"],
                "examples": ["What is the published phone number?"],
            }
        ],
        "securitySchemes": {
            "bearer": {"type": "http", "scheme": "bearer"},
        },
        "security": [{"bearer": []}],
    }


def _new_id() -> str:
    return str(uuid.uuid4())


def _summary(facts: list[dict[str, Any]]) -> str:
    if not facts:
        return "No approved fact matched. Nothing was invented."
    lines = [f"{len(facts)} approved fact(s)."]
    for fact in facts:
        line = fact["name"]
        if fact["description"]:
            line = f"{line}: {fact['description']}"
        lines.append(line)
    return "\n".join(lines)


def _rpc_result(req_id: Any, result: Any) -> dict[str, Any]:
    return {"jsonrpc": "2.0", "id": req_id, "result": result}


def _rpc_error(req_id: Any, code: int, message: str) -> dict[str, Any]:
    return {
        "jsonrpc": "2.0",
        "id": req_id,
        "error": {"code": code, "message": message},
    }


def _message_text(params: Any) -> str:
    if not isinstance(params, dict):
        return ""
    message = params.get("message")
    if isinstance(message, str):
        return message
    if not isinstance(message, dict):
        return ""
    parts = message.get("parts") or []
    texts: list[str] = []
    if isinstance(parts, list):
        for part in parts:
            if isinstance(part, str):
                texts.append(part)
            elif isinstance(part, dict) and isinstance(part.get("text"), str):
                texts.append(part["text"])
    if texts:
        return "\n".join(texts)
    if isinstance(message.get("text"), str):
        return message["text"]
    return ""


def answer_a2a(entities: list[Any], body: dict[str, Any] | None) -> tuple[int, dict[str, Any]]:
    if body is None:
        return 200, _rpc_error(None, -32700, "Parse error")
    req_id = body.get("id")
    method = body.get("method")
    if not isinstance(method, str) or not method:
        return 200, _rpc_error(req_id, -32600, "Invalid Request")
    if method != "message/send":
        return 200, _rpc_error(req_id, -32601, "Method not found")
    query = _message_text(body.get("params"))
    facts = search_approved(entities, query)
    task = {
        "id": _new_id(),
        "contextId": _new_id(),
        "status": {"state": "completed"},
        "artifacts": [
            {
                "artifactId": _new_id(),
                "name": "approved-facts",
                "parts": [
                    {"kind": "text", "text": _summary(facts)},
                    {
                        "kind": "data",
                        "data": {"query": query, "results": facts},
                    },
                ],
            }
        ],
    }
    return 200, _rpc_result(req_id, task)


def _mcp_tools() -> list[dict[str, Any]]:
    return [
        {
            "name": "search_approved",
            "description": "Search approved resolved claims. Does not invent facts.",
            "inputSchema": {
                "type": "object",
                "properties": {"query": {"type": "string"}},
                "required": ["query"],
            },
        },
        {
            "name": "list_approved",
            "description": "List the approved public set for this site.",
            "inputSchema": {"type": "object", "properties": {}},
        },
    ]


def answer_mcp(entities: list[Any], body: dict[str, Any] | None) -> tuple[int, dict[str, Any]]:
    if body is None:
        return 200, _rpc_error(None, -32700, "Parse error")
    req_id = body.get("id")
    method = body.get("method")
    if not isinstance(method, str) or not method:
        return 200, _rpc_error(req_id, -32600, "Invalid Request")
    params = body.get("params") if isinstance(body.get("params"), dict) else {}
    if method == "initialize":
        version = params.get("protocolVersion")
        if not isinstance(version, str) or not version:
            version = MCP_PROTOCOL
        return 200, _rpc_result(
            req_id,
            {
                "protocolVersion": version,
                "capabilities": {"tools": {"listChanged": False}},
                "serverInfo": {"name": "bkbs-capability", "version": "1.0.0"},
            },
        )
    if method == "notifications/initialized":
        return 200, _rpc_result(req_id, {})
    if method == "tools/list":
        return 200, _rpc_result(req_id, {"tools": _mcp_tools()})
    if method == "tools/call":
        name = params.get("name")
        arguments = params.get("arguments") if isinstance(params.get("arguments"), dict) else {}
        if name == "search_approved":
            facts = search_approved(entities, str(arguments.get("query") or ""))
        elif name == "list_approved":
            facts = search_approved(entities, "")
        else:
            return 200, _rpc_error(req_id, -32602, "Unknown tool")
        payload = json.dumps({"results": facts}, ensure_ascii=False)
        return 200, _rpc_result(
            req_id,
            {"content": [{"type": "text", "text": payload}], "isError": False},
        )
    return 200, _rpc_error(req_id, -32601, "Method not found")


def answer_ask(
    entities: list[Any], query: str | None, base_url: str
) -> tuple[int, dict[str, Any]]:
    if query is None:
        return 400, {"error": "query required"}
    facts = search_approved(entities, query)
    results = []
    for fact in facts:
        results.append(
            {
                "name": fact["name"],
                "url": base_url,
                "description": fact["description"],
                "schema_object": {
                    "@type": "Thing",
                    "name": fact["name"],
                    "description": fact["description"],
                    "identifier": fact["id"],
                },
            }
        )
    return 200, {"query": query, "results": results}
