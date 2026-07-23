"""Site lifecycle helpers (delete, cleanup)."""

from __future__ import annotations

import logging
import shutil
from pathlib import Path

from sqlalchemy.orm import Session

from app.config import settings
from app.models import Site

logger = logging.getLogger(__name__)


def delete_site_and_data(db: Session, site: Site) -> str:
    """
    Delete a site and all related DB rows (cascade) plus on-disk export packages.
    Returns the deleted site name for flash messages.
    """
    site_id = site.id
    name = site.name
    export_dir = settings.exports_dir / site_id

    db.delete(site)
    db.commit()

    if export_dir.exists():
        try:
            shutil.rmtree(export_dir)
        except OSError as exc:
            logger.warning("Could not remove export dir %s: %s", export_dir, exc)

    # Also remove any zip files sitting next to timestamp folders if layout differs
    parent = settings.exports_dir
    if parent.exists():
        for leftover in parent.glob(f"{site_id}*"):
            try:
                if leftover.is_dir():
                    shutil.rmtree(leftover)
                else:
                    leftover.unlink(missing_ok=True)
            except OSError:
                pass

    return name
