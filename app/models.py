"""SQLAlchemy models for BKBS Converter."""

from __future__ import annotations

import uuid
from datetime import datetime, timezone

from sqlalchemy import (
    Boolean,
    DateTime,
    ForeignKey,
    Integer,
    String,
    Text,
    UniqueConstraint,
)
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column, relationship
from sqlalchemy.types import JSON


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


def new_id() -> str:
    return str(uuid.uuid4())


class Base(DeclarativeBase):
    pass


class AppSetting(Base):
    """Key-value application settings (e.g. LLM provider credentials)."""

    __tablename__ = "app_settings"

    key: Mapped[str] = mapped_column(String(128), primary_key=True)
    value: Mapped[str] = mapped_column(Text, default="")
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)


class Site(Base):
    __tablename__ = "sites"

    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=new_id)
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    base_url: Mapped[str] = mapped_column(String(1024), nullable=False)
    max_pages: Mapped[int] = mapped_column(Integer, default=40)
    crawl_delay_ms: Mapped[int] = mapped_column(Integer, default=300)
    rescan_cron: Mapped[str | None] = mapped_column(String(64), nullable=True)
    # Public web root on this host (e.g. /home/user/public_html or relative public_html)
    publish_root: Mapped[str | None] = mapped_column(String(1024), nullable=True)
    # When true, publish live files after export / publish actions
    auto_publish: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=utcnow, onupdate=utcnow
    )

    scan_jobs: Mapped[list[ScanJob]] = relationship(back_populates="site", cascade="all, delete-orphan")
    entities: Mapped[list[Entity]] = relationship(back_populates="site", cascade="all, delete-orphan")
    pages: Mapped[list[Page]] = relationship(back_populates="site", cascade="all, delete-orphan")
    exports: Mapped[list[Export]] = relationship(back_populates="site", cascade="all, delete-orphan")


class ScanJob(Base):
    __tablename__ = "scan_jobs"

    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=new_id)
    site_id: Mapped[str] = mapped_column(ForeignKey("sites.id", ondelete="CASCADE"), index=True)
    status: Mapped[str] = mapped_column(String(32), default="queued")  # queued|running|completed|failed
    started_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    finished_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    pages_fetched: Mapped[int] = mapped_column(Integer, default=0)
    entities_found: Mapped[int] = mapped_column(Integer, default=0)
    error: Mapped[str | None] = mapped_column(Text, nullable=True)
    stats_json: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    llm_raw: Mapped[str | None] = mapped_column(Text, nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)

    site: Mapped[Site] = relationship(back_populates="scan_jobs")
    pages: Mapped[list[Page]] = relationship(back_populates="scan_job", cascade="all, delete-orphan")


class Page(Base):
    __tablename__ = "pages"

    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=new_id)
    site_id: Mapped[str] = mapped_column(ForeignKey("sites.id", ondelete="CASCADE"), index=True)
    scan_job_id: Mapped[str] = mapped_column(ForeignKey("scan_jobs.id", ondelete="CASCADE"), index=True)
    url: Mapped[str] = mapped_column(String(2048), nullable=False)
    status_code: Mapped[int | None] = mapped_column(Integer, nullable=True)
    title: Mapped[str | None] = mapped_column(String(1024), nullable=True)
    content_text: Mapped[str | None] = mapped_column(Text, nullable=True)
    json_ld_raw: Mapped[list | dict | None] = mapped_column(JSON, nullable=True)
    meta_json: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    fetched_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)

    site: Mapped[Site] = relationship(back_populates="pages")
    scan_job: Mapped[ScanJob] = relationship(back_populates="pages")


class Entity(Base):
    __tablename__ = "entities"
    __table_args__ = (
        UniqueConstraint("site_id", "external_key", name="uq_site_external_key"),
    )

    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=new_id)
    site_id: Mapped[str] = mapped_column(ForeignKey("sites.id", ondelete="CASCADE"), index=True)
    external_key: Mapped[str] = mapped_column(String(64), nullable=False, index=True)
    entity_type: Mapped[str] = mapped_column(String(64), nullable=False, index=True)
    name: Mapped[str] = mapped_column(String(512), nullable=False)
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    properties: Mapped[dict] = mapped_column(JSON, default=dict)
    relationships: Mapped[list] = mapped_column(JSON, default=list)
    evidence: Mapped[list] = mapped_column(JSON, default=list)
    version: Mapped[int] = mapped_column(Integer, default=1)
    trust_level: Mapped[str] = mapped_column(String(32), default="medium")  # low|medium|high
    source: Mapped[str] = mapped_column(String(32), default="scan")
    status: Mapped[str] = mapped_column(String(32), default="pending", index=True)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)
    last_scan_job_id: Mapped[str | None] = mapped_column(String(36), nullable=True)
    last_updated: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)

    site: Mapped[Site] = relationship(back_populates="entities")
    versions: Mapped[list[EntityVersion]] = relationship(
        back_populates="entity", cascade="all, delete-orphan"
    )


class EntityVersion(Base):
    __tablename__ = "entity_versions"

    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=new_id)
    entity_id: Mapped[str] = mapped_column(ForeignKey("entities.id", ondelete="CASCADE"), index=True)
    version: Mapped[int] = mapped_column(Integer, nullable=False)
    snapshot_json: Mapped[dict] = mapped_column(JSON, nullable=False)
    change_source: Mapped[str] = mapped_column(String(64), default="scan")
    changed_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)

    entity: Mapped[Entity] = relationship(back_populates="versions")


class Export(Base):
    __tablename__ = "exports"

    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=new_id)
    site_id: Mapped[str] = mapped_column(ForeignKey("sites.id", ondelete="CASCADE"), index=True)
    path: Mapped[str] = mapped_column(String(1024), nullable=False)
    include_pending: Mapped[bool] = mapped_column(Boolean, default=False)
    entity_count: Mapped[int] = mapped_column(Integer, default=0)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)

    site: Mapped[Site] = relationship(back_populates="exports")
