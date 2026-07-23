"""Database engine and session helpers."""

from __future__ import annotations

from collections.abc import Generator

from sqlalchemy import create_engine, event
from sqlalchemy.orm import Session, sessionmaker

from app.config import settings
from app.models import Base


def _make_engine():
    settings.data_dir.mkdir(parents=True, exist_ok=True)
    settings.exports_dir.mkdir(parents=True, exist_ok=True)
    url = f"sqlite:///{settings.db_path}"
    engine = create_engine(
        url,
        connect_args={"check_same_thread": False},
        future=True,
    )

    @event.listens_for(engine, "connect")
    def _set_sqlite_pragma(dbapi_conn, _connection_record):
        cursor = dbapi_conn.cursor()
        cursor.execute("PRAGMA foreign_keys=ON")
        cursor.close()

    return engine


engine = _make_engine()
SessionLocal = sessionmaker(bind=engine, autoflush=False, autocommit=False, future=True)


def _migrate_sqlite() -> None:
    """Add columns introduced after first install (SQLite has no auto-alter)."""
    from sqlalchemy import text

    with engine.connect() as conn:
        rows = conn.execute(text("PRAGMA table_info(sites)")).fetchall()
        cols = {r[1] for r in rows}
        if "publish_root" not in cols:
            conn.execute(text("ALTER TABLE sites ADD COLUMN publish_root VARCHAR(1024)"))
        if "auto_publish" not in cols:
            conn.execute(
                text("ALTER TABLE sites ADD COLUMN auto_publish BOOLEAN DEFAULT 1")
            )
        conn.commit()


def init_db() -> None:
    settings.data_dir.mkdir(parents=True, exist_ok=True)
    settings.exports_dir.mkdir(parents=True, exist_ok=True)
    (settings.data_dir / "live").mkdir(parents=True, exist_ok=True)
    Base.metadata.create_all(bind=engine)
    try:
        _migrate_sqlite()
    except Exception:
        # Fresh DB or non-sqlite: ignore
        pass


def get_db() -> Generator[Session, None, None]:
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()
