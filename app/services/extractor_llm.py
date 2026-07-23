"""LLM-based BKBS entity extraction via any OpenAI-compatible provider."""

from __future__ import annotations

import json
import logging
import re
from typing import Any

from openai import OpenAI
from sqlalchemy.orm import Session

from app.config import settings
from app.constants import ENTITY_TYPES
from app.db import SessionLocal
from app.schemas import ExtractedEntity
from app.services.crawler import CrawledPage
from app.services.llm_settings import LlmConfig, resolve_llm_config

logger = logging.getLogger(__name__)

SYSTEM_PROMPT = """You are a Business Knowledge Base (BKBS) extractor.
Convert website page content into structured business knowledge entities.

Return ONLY valid JSON with this shape:
{
  "entities": [
    {
      "entity_type": one of: business_identity | product_service | capability | expertise | facility_served | operational_problem | project | knowledge_article | policy | team | asset | relationship,
      "name": "short clear name",
      "description": "factual description without marketing fluff",
      "properties": { "key": "value" },
      "relationships": [ { "predicate": "supports|requires|implemented_by|using|complies_with|reduces|supported_by|related_to", "target_name": "other entity name" } ],
      "evidence": [ { "url": "source page url", "snippet": "short supporting quote", "kind": "text" } ],
      "trust_level": "low|medium|high"
    }
  ]
}

Rules:
- Prefer precise capabilities and services over vague marketing claims.
- Include business_identity when identifiable.
- Link related entities with relationships when clear.
- Every entity needs evidence pointing to a provided page URL.
- Do not invent certifications, prices, or phone numbers not present in the text.
- Deduplicate similar names; use consistent naming.
- If little info, return fewer high-quality entities rather than filler.
- Extract as many distinct, factual entities as the content supports (services, capabilities, facilities, policies, contact facts, brands).
"""


def _resolve(db: Session | None = None) -> LlmConfig:
    own_session = False
    if db is None:
        db = SessionLocal()
        own_session = True
    try:
        return resolve_llm_config(db)
    finally:
        if own_session:
            db.close()


def _get_client(cfg: LlmConfig | None = None, db: Session | None = None) -> tuple[OpenAI, LlmConfig]:
    cfg = cfg or _resolve(db)
    if not cfg.is_configured:
        raise RuntimeError(
            "LLM is not configured. Open Settings and add an API key for any "
            "OpenAI-compatible provider (xAI, OpenAI, OpenRouter, Ollama, etc.)."
        )
    client = OpenAI(
        api_key=cfg.api_key or "not-needed",
        base_url=cfg.base_url,
        timeout=120.0,
    )
    return client, cfg


def _page_digest(page: CrawledPage, max_chars: int = 3500) -> dict[str, Any]:
    text = page.content_text or ""
    if len(text) > max_chars:
        text = text[:max_chars] + "…"
    return {
        "url": page.url,
        "title": page.title,
        "meta": {
            k: page.meta.get(k)
            for k in ("description", "emails", "phones")
            if page.meta and page.meta.get(k)
        },
        "json_ld_types": [
            b.get("@type")
            for b in (page.json_ld or [])
            if isinstance(b, dict) and b.get("@type")
        ],
        "text": text,
    }


def _parse_json_payload(content: str) -> dict:
    content = content.strip()
    fence = re.search(r"```(?:json)?\s*([\s\S]*?)```", content)
    if fence:
        content = fence.group(1).strip()
    try:
        return json.loads(content)
    except json.JSONDecodeError:
        start = content.find("{")
        end = content.rfind("}")
        if start >= 0 and end > start:
            return json.loads(content[start : end + 1])
        raise


def _normalize_entities(raw_entities: list, source: str = "llm") -> list[ExtractedEntity]:
    out: list[ExtractedEntity] = []
    for item in raw_entities:
        if not isinstance(item, dict):
            continue
        etype = str(item.get("entity_type") or "").strip()
        if etype not in ENTITY_TYPES:
            soft = {
                "product": "product_service",
                "service": "product_service",
                "products_and_services": "product_service",
                "organization": "business_identity",
                "business": "business_identity",
                "facility": "facility_served",
                "facilities": "facility_served",
                "problem": "operational_problem",
                "article": "knowledge_article",
                "person": "team",
            }
            etype = soft.get(etype.lower(), etype)
        if etype not in ENTITY_TYPES:
            continue
        name = str(item.get("name") or "").strip()
        if not name:
            continue
        props = item.get("properties") if isinstance(item.get("properties"), dict) else {}
        rels = item.get("relationships") if isinstance(item.get("relationships"), list) else []
        evidence = item.get("evidence") if isinstance(item.get("evidence"), list) else []
        trust = str(item.get("trust_level") or "medium")
        if trust not in ("low", "medium", "high"):
            trust = "medium"
        out.append(
            ExtractedEntity(
                entity_type=etype,
                name=name[:512],
                description=(str(item["description"])[:4000] if item.get("description") else None),
                properties=props,
                relationships=[r for r in rels if isinstance(r, dict)],
                evidence=[e for e in evidence if isinstance(e, dict)],
                trust_level=trust,
                source=source,
            )
        )
    return out


def extract_with_llm(
    pages: list[CrawledPage],
    base_url: str,
    batch_size: int | None = None,
    db: Session | None = None,
) -> tuple[list[ExtractedEntity], str]:
    """
    Extract BKBS entities from pages using the configured LLM provider.
    Returns (entities, raw_model_outputs_joined).
    """
    if not pages:
        return [], ""

    cfg = _resolve(db)
    if not cfg.is_configured:
        logger.warning("LLM not configured; skipping LLM extraction")
        return [], ""

    try:
        client, cfg = _get_client(cfg)
    except Exception as exc:
        logger.warning("LLM client unavailable: %s", exc)
        return [], str(exc)

    batch_size = batch_size or settings.llm_batch_pages
    all_entities: list[ExtractedEntity] = []
    raw_parts: list[str] = []

    sorted_pages = sorted(pages, key=lambda p: (len(p.url), p.url))

    for i in range(0, len(sorted_pages), batch_size):
        batch = sorted_pages[i : i + batch_size]
        digests = [_page_digest(p) for p in batch]
        user_payload = {
            "site_base_url": base_url,
            "pages": digests,
            "instruction": "Extract BKBS entities for this business from the pages.",
        }
        try:
            resp = client.chat.completions.create(
                model=cfg.model,
                messages=[
                    {"role": "system", "content": SYSTEM_PROMPT},
                    {"role": "user", "content": json.dumps(user_payload, ensure_ascii=False)},
                ],
                temperature=0.2,
            )
            content = resp.choices[0].message.content or ""
            raw_parts.append(content)
            data = _parse_json_payload(content)
            ents = data.get("entities") if isinstance(data, dict) else None
            if isinstance(ents, list):
                all_entities.extend(_normalize_entities(ents, source="llm"))
        except Exception as exc:
            logger.exception("LLM batch failed: %s", exc)
            raw_parts.append(json.dumps({"error": str(exc), "batch_start": i}))

    return all_entities, "\n\n---\n\n".join(raw_parts)


def convert_free_text(
    text: str,
    base_url: str | None = None,
    default_entity_type: str | None = None,
    db: Session | None = None,
) -> list[ExtractedEntity]:
    """Convert free-form business notes into BKBS entities."""
    cfg = _resolve(db)
    if not cfg.is_configured:
        etype = default_entity_type if default_entity_type in ENTITY_TYPES else "knowledge_article"
        name = text.strip().split("\n")[0][:120] or "Manual note"
        return [
            ExtractedEntity(
                entity_type=etype,
                name=name,
                description=text.strip()[:4000],
                properties={},
                evidence=[{"url": base_url or "", "snippet": name, "kind": "manual"}],
                trust_level="medium",
                source="manual",
            )
        ]

    client, cfg = _get_client(cfg)
    user = {
        "site_base_url": base_url or "",
        "default_entity_type": default_entity_type,
        "text": text,
        "instruction": "Convert this free-form business text into one or more BKBS entities.",
    }
    resp = client.chat.completions.create(
        model=cfg.model,
        messages=[
            {"role": "system", "content": SYSTEM_PROMPT},
            {"role": "user", "content": json.dumps(user, ensure_ascii=False)},
        ],
        temperature=0.2,
    )
    content = resp.choices[0].message.content or ""
    data = _parse_json_payload(content)
    ents = data.get("entities") if isinstance(data, dict) else []
    normalized = _normalize_entities(ents if isinstance(ents, list) else [], source="manual")
    for e in normalized:
        e.source = "manual"
    return normalized
