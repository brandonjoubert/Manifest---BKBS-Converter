"""BKBS entity types and verification statuses."""

from __future__ import annotations

ENTITY_TYPES = [
    "business_identity",
    "product_service",
    "capability",
    "expertise",
    "facility_served",
    "operational_problem",
    "project",
    "knowledge_article",
    "policy",
    "team",
    "asset",
    "relationship",
]

ENTITY_TYPE_LABELS = {
    "business_identity": "Business Identity",
    "product_service": "Products & Services",
    "capability": "Capabilities",
    "expertise": "Expertise",
    "facility_served": "Facilities Served",
    "operational_problem": "Operational Problems",
    "project": "Projects",
    "knowledge_article": "Knowledge Articles",
    "policy": "Policies",
    "team": "Team",
    "asset": "Assets",
    "relationship": "Relationships",
}

ENTITY_STATUSES = ["pending", "approved", "rejected", "needs_edit", "stale"]

STATUS_LABELS = {
    "pending": "Pending",
    "approved": "Approved",
    "rejected": "Rejected",
    "needs_edit": "Needs Edit",
    "stale": "Stale",
}

SOURCES = ["scan", "manual", "rescan_merge", "heuristic", "llm"]

# schema.org mapping hints for export
SCHEMA_TYPE_MAP = {
    "business_identity": "LocalBusiness",
    "product_service": "Service",
    "capability": "Service",
    "knowledge_article": "Article",
    "policy": "WebPage",
    "team": "Person",
    "project": "CreativeWork",
    "asset": "Product",
}
