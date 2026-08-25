"""schema.org JSON-LD file adapters (Stage 4b). HTML inject snippet is Stage 4c."""

from __future__ import annotations

from typing import Any, Sequence

from app.constants import SCHEMA_TYPE_MAP


def _identity(entities: Sequence[Any]) -> Any | None:
    for e in entities:
        if e.entity_type == "business_identity":
            return e
    return None


def build_organization_jsonld(site: Any, entities: Sequence[Any]) -> dict[str, Any]:
    ident = _identity(entities)
    name = ident.name if ident else site.name
    desc = (ident.description if ident else None) or f"Business knowledge for {site.name}"
    props = (ident.properties if ident else {}) or {}

    org: dict[str, Any] = {
        "@context": "https://schema.org",
        "@type": "LocalBusiness",
        "@id": f"{site.base_url}/#organization",
        "name": name,
        "url": site.base_url,
        "description": desc,
    }
    if props.get("telephone"):
        org["telephone"] = props["telephone"]
    if props.get("email"):
        org["email"] = props["email"]
    if props.get("address"):
        org["address"] = props["address"]
    if props.get("openingHours"):
        org["openingHours"] = props["openingHours"]
    if props.get("areaServed"):
        org["areaServed"] = props["areaServed"]
    if props.get("sameAs"):
        org["sameAs"] = props["sameAs"]

    services = [e for e in entities if e.entity_type in ("product_service", "capability")]
    if services:
        org["hasOfferCatalog"] = {
            "@type": "OfferCatalog",
            "name": f"{name} services",
            "itemListElement": [
                {
                    "@type": "Offer",
                    "itemOffered": {
                        "@type": "Service",
                        "@id": f"{site.base_url}/#service-{s.id}",
                        "name": s.name,
                        "description": s.description or s.name,
                    },
                }
                for s in services
            ],
        }
    return org


def build_services_jsonld(site: Any, entities: Sequence[Any]) -> list[dict[str, Any]]:
    out = []
    for e in entities:
        if e.entity_type not in ("product_service", "capability"):
            continue
        schema_type = SCHEMA_TYPE_MAP.get(e.entity_type, "Service")
        item: dict[str, Any] = {
            "@context": "https://schema.org",
            "@type": schema_type,
            "@id": f"{site.base_url}/#entity-{e.id}",
            "name": e.name,
            "description": e.description or e.name,
            "url": site.base_url,
        }
        if e.properties:
            for k, v in e.properties.items():
                if k not in item and v is not None:
                    item[k] = v
        out.append(item)
    return out
