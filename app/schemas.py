"""Pydantic schemas for API and internal use."""

from __future__ import annotations

from datetime import datetime
from typing import Any

from pydantic import BaseModel, Field, HttpUrl, field_validator

from app.constants import ENTITY_STATUSES, ENTITY_TYPES


class SiteCreate(BaseModel):
    name: str = Field(min_length=1, max_length=255)
    base_url: str = Field(min_length=3, max_length=1024)
    max_pages: int = Field(default=40, ge=1, le=500)
    crawl_delay_ms: int = Field(default=300, ge=0, le=10_000)
    publish_root: str | None = None
    auto_publish: bool = True

    @field_validator("base_url")
    @classmethod
    def normalize_url(cls, v: str) -> str:
        v = v.strip()
        if not v.startswith(("http://", "https://")):
            v = "https://" + v
        return v.rstrip("/")


class SiteUpdate(BaseModel):
    name: str | None = None
    base_url: str | None = None
    max_pages: int | None = Field(default=None, ge=1, le=500)
    crawl_delay_ms: int | None = Field(default=None, ge=0, le=10_000)
    rescan_cron: str | None = None
    publish_root: str | None = None
    auto_publish: bool | None = None


class SiteOut(BaseModel):
    id: str
    name: str
    base_url: str
    max_pages: int
    crawl_delay_ms: int
    rescan_cron: str | None
    publish_root: str | None = None
    auto_publish: bool = True
    created_at: datetime
    updated_at: datetime
    pending_count: int = 0
    approved_count: int = 0
    entity_count: int = 0
    last_scan_status: str | None = None

    model_config = {"from_attributes": True}


class ScanJobOut(BaseModel):
    id: str
    site_id: str
    status: str
    started_at: datetime | None
    finished_at: datetime | None
    pages_fetched: int
    entities_found: int
    error: str | None
    stats_json: dict | None
    created_at: datetime

    model_config = {"from_attributes": True}


class EntityCreate(BaseModel):
    entity_type: str
    name: str = Field(min_length=1, max_length=512)
    description: str | None = None
    properties: dict[str, Any] = Field(default_factory=dict)
    relationships: list[dict[str, Any]] = Field(default_factory=list)
    evidence: list[dict[str, Any]] = Field(default_factory=list)
    trust_level: str = "medium"
    approve_immediately: bool = False

    @field_validator("entity_type")
    @classmethod
    def valid_type(cls, v: str) -> str:
        if v not in ENTITY_TYPES:
            raise ValueError(f"entity_type must be one of {ENTITY_TYPES}")
        return v


class EntityUpdate(BaseModel):
    entity_type: str | None = None
    name: str | None = None
    description: str | None = None
    properties: dict[str, Any] | None = None
    relationships: list[dict[str, Any]] | None = None
    evidence: list[dict[str, Any]] | None = None
    trust_level: str | None = None
    notes: str | None = None
    status: str | None = None

    @field_validator("entity_type")
    @classmethod
    def valid_type(cls, v: str | None) -> str | None:
        if v is not None and v not in ENTITY_TYPES:
            raise ValueError(f"entity_type must be one of {ENTITY_TYPES}")
        return v

    @field_validator("status")
    @classmethod
    def valid_status(cls, v: str | None) -> str | None:
        if v is not None and v not in ENTITY_STATUSES:
            raise ValueError(f"status must be one of {ENTITY_STATUSES}")
        return v


class EntityOut(BaseModel):
    id: str
    site_id: str
    external_key: str
    entity_type: str
    name: str
    description: str | None
    properties: dict[str, Any]
    relationships: list[dict[str, Any]]
    evidence: list[dict[str, Any]]
    version: int
    trust_level: str
    source: str
    status: str
    notes: str | None
    last_updated: datetime
    created_at: datetime

    model_config = {"from_attributes": True}


class VerifyAction(BaseModel):
    action: str  # approve | reject | needs_edit

    @field_validator("action")
    @classmethod
    def valid_action(cls, v: str) -> str:
        if v not in ("approve", "reject", "needs_edit"):
            raise ValueError("action must be approve, reject, or needs_edit")
        return v


class BulkVerify(BaseModel):
    entity_ids: list[str]
    action: str

    @field_validator("action")
    @classmethod
    def valid_action(cls, v: str) -> str:
        if v not in ("approve", "reject", "needs_edit"):
            raise ValueError("action must be approve, reject, or needs_edit")
        return v


class FreeTextConvert(BaseModel):
    text: str = Field(min_length=10, max_length=50_000)
    default_entity_type: str | None = None
    approve_immediately: bool = False


class ExportRequest(BaseModel):
    include_pending: bool = False


class ExportOut(BaseModel):
    id: str
    site_id: str
    path: str
    include_pending: bool
    entity_count: int
    created_at: datetime

    model_config = {"from_attributes": True}


class ExtractedEntity(BaseModel):
    """Normalized entity produced by heuristic or LLM extractors."""

    entity_type: str
    name: str
    description: str | None = None
    properties: dict[str, Any] = Field(default_factory=dict)
    relationships: list[dict[str, Any]] = Field(default_factory=list)
    evidence: list[dict[str, Any]] = Field(default_factory=list)
    trust_level: str = "medium"
    source: str = "scan"
