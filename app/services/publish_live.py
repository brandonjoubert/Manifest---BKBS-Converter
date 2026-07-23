"""Publish approved BKBS files into a website document root (shared host public_html)."""

from __future__ import annotations

import json
import logging
import os
import shutil
from dataclasses import dataclass, field
from pathlib import Path

from sqlalchemy.orm import Session

from app.config import settings
from app.models import Entity, Site
from app.services.export_graph import build_graph
from app.services.export_jsonld import (
    build_agent_json,
    build_organization_jsonld,
    build_services_jsonld,
)
from app.services.export_llms import render_llms_full, render_llms_txt

logger = logging.getLogger(__name__)

# Paths relative to the public document root (what agents fetch on the domain)
LIVE_FILES = (
    "llms.txt",
    "llms-full.txt",
    "graph.json",
    "schema/organization.jsonld",
    "schema/services.jsonld",
    ".well-known/agent.json",
    "bkbs/README.txt",
)


@dataclass
class PublishResult:
    ok: bool
    root: str
    files_written: list[str] = field(default_factory=list)
    error: str | None = None
    entity_count: int = 0


PLACEHOLDER_MARKERS = (
    "/home/user/",
    "/home/username/",
    "/home/youruser/",
    "/home/YOURUSER/",
    "/home/USERNAME/",
    "yourdomain",
    "YOURDOMAIN",
)


def suggested_local_publish_root() -> Path:
    """Writable folder for local PC testing (not a real shared-host path)."""
    from app.config import ROOT_DIR

    return (settings.data_dir / "live-public").resolve()


def looks_like_placeholder_path(raw: str) -> bool:
    s = raw.strip().replace("\\", "/")
    lower = s.lower()
    if any(m.lower() in lower for m in PLACEHOLDER_MARKERS):
        return True
    # Exact common doc examples
    if lower in {
        "/home/user/public_html",
        "/home/username/public_html",
        "public_html",  # alone is ok as relative — don't flag
    }:
        return lower.startswith("/home/")
    return False


def resolve_publish_root(site: Site) -> Path | None:
    """
    Resolve the filesystem directory that is the public web root for this site.
    Supports absolute paths and paths relative to the app data dir or project root.
    """
    raw = (getattr(site, "publish_root", None) or "").strip()
    if not raw:
        # Global default from env / settings
        raw = (settings.default_publish_root or "").strip()
    if not raw:
        return None

    path = Path(raw).expanduser()
    if not path.is_absolute():
        # Try relative to project root, then data dir
        from app.config import ROOT_DIR

        candidate = (ROOT_DIR / path).resolve()
        if candidate.exists() or path.parts[0] in (".", ".."):
            path = candidate
        else:
            path = (settings.data_dir / path).resolve()
    return path


def _write_text(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding="utf-8")


def _write_json(path: Path, data) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def _merge_robots(root: Path, site: Site) -> str | None:
    """
    Append BKBS allow rules to robots.txt if missing.
    Never deletes existing rules. Returns relative path written or None.
    """
    robots = root / "robots.txt"
    marker = "# BEGIN BKBS"
    end_marker = "# END BKBS"
    block = f"""{marker}
# Machine layers for AI agents (managed by BKBS Converter)
User-agent: *
Allow: /llms.txt
Allow: /llms-full.txt
Allow: /graph.json
Allow: /schema/
Allow: /.well-known/agent.json

Sitemap: {site.base_url.rstrip('/')}/sitemap.xml
{end_marker}
"""
    existing = robots.read_text(encoding="utf-8") if robots.exists() else ""
    if marker in existing:
        # Replace managed block
        start = existing.find(marker)
        end = existing.find(end_marker)
        if end >= 0:
            end += len(end_marker)
            new = existing[:start].rstrip() + "\n\n" + block
            if end < len(existing):
                new += "\n" + existing[end:].lstrip()
            _write_text(robots, new.rstrip() + "\n")
        else:
            _write_text(robots, existing.rstrip() + "\n\n" + block)
    else:
        _write_text(robots, (existing.rstrip() + "\n\n" + block).lstrip() + "\n")
    return "robots.txt"


def _ensure_writable(root: Path, raw_input: str = "") -> str | None:
    """
    Ensure publish directory exists and is writable.
    Returns an error message, or None on success.
    """
    local_hint = str(suggested_local_publish_root())

    if raw_input and looks_like_placeholder_path(raw_input):
        return (
            f"“{raw_input}” looks like a documentation placeholder, not a real folder on this machine. "
            f"On a shared host, use YOUR account path (cPanel File Manager shows it), e.g. "
            f"/home/brandon/public_html — replace brandon with your real username. "
            f"On this PC for testing, use: {local_hint}"
        )

    # If an absolute path's parent tree cannot exist (e.g. /home/user on a machine without that user)
    if root.is_absolute():
        # Find first existing ancestor
        probe = root
        while probe != probe.parent and not probe.exists():
            probe = probe.parent
        if probe == root.parent or not root.exists():
            # Will try mkdir; if parent of root doesn't exist and is outside home/project, warn early
            parent = root.parent
            if not parent.exists():
                # Try creating only if we can write the first missing segment reasonably
                try:
                    root.mkdir(parents=True, exist_ok=True)
                except OSError as exc:
                    return (
                        f"Cannot create publish root: {root} ({exc}). "
                        f"That folder does not exist and could not be created (wrong path or no permission). "
                        f"Use the real website document root on this server, or for local testing: {local_hint}"
                    )

    try:
        root.mkdir(parents=True, exist_ok=True)
        test = root / ".bkbs_write_test"
        test.write_text("ok", encoding="utf-8")
        test.unlink(missing_ok=True)
        return None
    except OSError as exc:
        return (
            f"Publish root not writable: {root} ({exc}). "
            f"Check the path is correct and the app user can write there. "
            f"Local testing path: {local_hint}"
        )


def publish_site_live(
    db: Session,
    site: Site,
    *,
    include_pending: bool = False,
    merge_robots: bool = True,
) -> PublishResult:
    """
    Write approved (or draft) BKBS artifacts into the site's public document root
    so they are live at https://domain/llms.txt etc. without manual FTP.
    """
    raw = (getattr(site, "publish_root", None) or "").strip() or (
        settings.default_publish_root or ""
    ).strip()
    root = resolve_publish_root(site)
    local_hint = str(suggested_local_publish_root())
    if root is None:
        return PublishResult(
            ok=False,
            root="",
            error=(
                "No publish root configured. Set the site's Web root path to a real folder on this machine. "
                f"Examples: your host document root from File Manager, or for local testing: {local_hint}"
            ),
        )

    err = _ensure_writable(root, raw_input=raw)
    if err:
        return PublishResult(ok=False, root=str(root), error=err)

    query = db.query(Entity).filter(Entity.site_id == site.id)
    if include_pending:
        query = query.filter(Entity.status.in_(["approved", "pending", "needs_edit"]))
    else:
        query = query.filter(Entity.status == "approved")
    entities = query.order_by(Entity.entity_type, Entity.name).all()

    written: list[str] = []
    try:
        mapping = {
            "llms.txt": render_llms_txt(site, entities),
            "llms-full.txt": render_llms_full(site, entities),
        }
        for rel, text in mapping.items():
            _write_text(root / rel, text)
            written.append(rel)

        _write_json(root / "graph.json", build_graph(site, entities))
        written.append("graph.json")

        _write_json(root / "schema" / "organization.jsonld", build_organization_jsonld(site, entities))
        written.append("schema/organization.jsonld")

        _write_json(root / "schema" / "services.jsonld", build_services_jsonld(site, entities))
        written.append("schema/services.jsonld")

        _write_json(root / ".well-known" / "agent.json", build_agent_json(site, entities))
        written.append(".well-known/agent.json")

        readme = (
            f"BKBS machine layer for {site.name}\n"
            f"Base URL: {site.base_url}\n"
            f"Entities published: {len(entities)}\n"
            f"Generated by BKBS Converter on the server.\n"
            f"Do not edit these files by hand if auto-publish is enabled.\n"
        )
        _write_text(root / "bkbs" / "README.txt", readme)
        written.append("bkbs/README.txt")

        if merge_robots:
            r = _merge_robots(root, site)
            if r:
                written.append(r)

        # Mirror under data/live/{site_id} for backup / app-served public URLs
        mirror = settings.data_dir / "live" / site.id
        if mirror.exists():
            shutil.rmtree(mirror)
        for rel in LIVE_FILES:
            src = root / rel
            if src.exists():
                dest = mirror / rel
                dest.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(src, dest)

        logger.info("Published %s files to %s for site %s", len(written), root, site.id)
        return PublishResult(
            ok=True,
            root=str(root),
            files_written=written,
            entity_count=len(entities),
        )
    except OSError as exc:
        logger.exception("Publish failed")
        return PublishResult(ok=False, root=str(root), error=str(exc), files_written=written)
