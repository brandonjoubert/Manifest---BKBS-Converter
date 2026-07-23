"""Heuristic BKBS entity extraction from crawled pages (no LLM)."""

from __future__ import annotations

import re
from typing import Any
from urllib.parse import urlparse

from app.schemas import ExtractedEntity
from app.services.crawler import CrawledPage


def _as_list(obj: Any) -> list:
    if obj is None:
        return []
    if isinstance(obj, list):
        return obj
    return [obj]


def _from_schema_org(blocks: list, page_url: str) -> list[ExtractedEntity]:
    entities: list[ExtractedEntity] = []
    for block in blocks:
        if not isinstance(block, dict):
            continue
        types = _as_list(block.get("@type"))
        types_lower = [str(t).lower() for t in types]

        if any(t in types_lower for t in ("organization", "localbusiness", "store", "corporation")):
            name = block.get("name") or block.get("legalName")
            if name:
                props = {
                    k: block.get(k)
                    for k in (
                        "telephone",
                        "email",
                        "url",
                        "address",
                        "openingHours",
                        "areaServed",
                        "sameAs",
                        "priceRange",
                    )
                    if block.get(k) is not None
                }
                entities.append(
                    ExtractedEntity(
                        entity_type="business_identity",
                        name=str(name),
                        description=block.get("description"),
                        properties=props,
                        evidence=[{"url": page_url, "snippet": str(name), "kind": "json_ld"}],
                        trust_level="high",
                        source="heuristic",
                    )
                )

        if any(t in types_lower for t in ("service", "product", "offer")):
            item = block
            if str(block.get("@type", "")).lower() == "offer" and isinstance(block.get("itemOffered"), dict):
                item = block["itemOffered"]
            name = item.get("name")
            if name:
                etype = "product_service"
                entities.append(
                    ExtractedEntity(
                        entity_type=etype,
                        name=str(name),
                        description=item.get("description"),
                        properties={
                            k: item.get(k)
                            for k in ("sku", "brand", "category", "offers")
                            if item.get(k) is not None
                        },
                        evidence=[{"url": page_url, "snippet": str(name), "kind": "json_ld"}],
                        trust_level="high",
                        source="heuristic",
                    )
                )

        if "faqpage" in types_lower:
            for main in _as_list(block.get("mainEntity")):
                if not isinstance(main, dict):
                    continue
                q = main.get("name")
                ans = ""
                accepted = main.get("acceptedAnswer")
                if isinstance(accepted, dict):
                    ans = accepted.get("text") or ""
                if q:
                    entities.append(
                        ExtractedEntity(
                            entity_type="knowledge_article",
                            name=str(q)[:200],
                            description=str(ans)[:2000] if ans else None,
                            properties={"kind": "faq"},
                            evidence=[{"url": page_url, "snippet": str(q)[:200], "kind": "json_ld"}],
                            trust_level="high",
                            source="heuristic",
                        )
                    )

        if "person" in types_lower:
            name = block.get("name")
            if name:
                entities.append(
                    ExtractedEntity(
                        entity_type="team",
                        name=str(name),
                        description=block.get("jobTitle") or block.get("description"),
                        properties={
                            k: block.get(k)
                            for k in ("jobTitle", "email", "telephone", "worksFor")
                            if block.get(k) is not None
                        },
                        evidence=[{"url": page_url, "snippet": str(name), "kind": "json_ld"}],
                        trust_level="high",
                        source="heuristic",
                    )
                )

    return entities


SERVICE_KEYWORDS = {
    "cctv": "CCTV Installation",
    "access control": "Access Control Systems",
    "intrusion": "Intrusion Detection",
    "alarm": "Alarm Systems",
    "electric fencing": "Electric Fencing",
    "gate automation": "Gate Automation",
    "network installation": "Network Installation",
    "fire detection": "Fire Detection",
    "maintenance": "Maintenance Services",
}


FACILITY_KEYWORDS = [
    "warehouse",
    "office",
    "retail",
    "hospital",
    "school",
    "factory",
    "industrial",
    "commercial",
    "residential",
]


POLICY_PATH_HINTS = ("privacy", "warranty", "terms", "policy", "returns", "refund")


def _from_page_content(page: CrawledPage) -> list[ExtractedEntity]:
    entities: list[ExtractedEntity] = []
    text_lower = (page.content_text or "").lower()
    path = urlparse(page.url).path.lower()
    title = page.title or path

    # Business identity from homepage-like meta
    if path in ("", "/", "/index.html", "/home", "/home.html"):
        name = page.meta.get("og_title") or page.title
        if name:
            props = {}
            if page.meta.get("emails"):
                props["email"] = page.meta["emails"][0]
            if page.meta.get("phones"):
                props["telephone"] = page.meta["phones"][0]
            entities.append(
                ExtractedEntity(
                    entity_type="business_identity",
                    name=name,
                    description=page.meta.get("description"),
                    properties=props,
                    evidence=[{"url": page.url, "snippet": (page.meta.get("description") or name)[:300], "kind": "meta"}],
                    trust_level="medium",
                    source="heuristic",
                )
            )

    # Capabilities / services from keywords
    for kw, label in SERVICE_KEYWORDS.items():
        if kw in text_lower or kw.replace(" ", "-") in path or kw.replace(" ", "") in path.replace("-", ""):
            entities.append(
                ExtractedEntity(
                    entity_type="capability",
                    name=label,
                    description=f"Mentioned on page: {title}",
                    properties={"keyword": kw},
                    evidence=[{"url": page.url, "snippet": f"Matched keyword '{kw}'", "kind": "keyword"}],
                    trust_level="medium",
                    source="heuristic",
                )
            )

    for fac in FACILITY_KEYWORDS:
        if re.search(rf"\b{re.escape(fac)}\b", text_lower):
            entities.append(
                ExtractedEntity(
                    entity_type="facility_served",
                    name=fac.title() + ("s" if not fac.endswith("s") else ""),
                    description=f"Facility type referenced on {title}",
                    properties={},
                    evidence=[{"url": page.url, "snippet": fac, "kind": "keyword"}],
                    trust_level="low",
                    source="heuristic",
                )
            )

    if any(h in path for h in POLICY_PATH_HINTS):
        entities.append(
            ExtractedEntity(
                entity_type="policy",
                name=title or path,
                description=(page.content_text or "")[:800],
                properties={"url": page.url},
                evidence=[{"url": page.url, "snippet": title or path, "kind": "page"}],
                trust_level="medium",
                source="heuristic",
            )
        )

    if "faq" in path or "faq" in text_lower[:500]:
        # Pull Q-like lines
        for line in (page.content_text or "").splitlines():
            if line.strip().endswith("?") and 10 < len(line) < 200:
                entities.append(
                    ExtractedEntity(
                        entity_type="knowledge_article",
                        name=line.strip(),
                        description=None,
                        properties={"kind": "faq"},
                        evidence=[{"url": page.url, "snippet": line.strip()[:200], "kind": "text"}],
                        trust_level="low",
                        source="heuristic",
                    )
                )
                if len([e for e in entities if e.entity_type == "knowledge_article"]) > 20:
                    break

    return entities


def extract_heuristic(pages: list[CrawledPage]) -> list[ExtractedEntity]:
    all_entities: list[ExtractedEntity] = []
    for page in pages:
        all_entities.extend(_from_schema_org(page.json_ld or [], page.url))
        all_entities.extend(_from_page_content(page))
    return all_entities
