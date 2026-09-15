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
        if "aipref_search" not in cols:
            conn.execute(text("ALTER TABLE sites ADD COLUMN aipref_search BOOLEAN DEFAULT 0"))
        if "aipref_ai_input" not in cols:
            conn.execute(text("ALTER TABLE sites ADD COLUMN aipref_ai_input BOOLEAN DEFAULT 0"))
        if "aipref_train_ai" not in cols:
            conn.execute(text("ALTER TABLE sites ADD COLUMN aipref_train_ai BOOLEAN DEFAULT 0"))
        # Claim Ledger Stage 1 indexes — only if claims exists (create_all first).
        # Creating indexes on a missing table would raise OperationalError and, with
        # the outer try/except in init_db, could hide other migration work.
        tables = {
            r[0]
            for r in conn.execute(
                text("SELECT name FROM sqlite_master WHERE type='table'")
            ).fetchall()
        }
        if "claims" in tables:
            conn.execute(
                text(
                    "CREATE INDEX IF NOT EXISTS idx_claims_entity_attr "
                    "ON claims(entity_id, attribute)"
                )
            )
            conn.execute(
                text("CREATE INDEX IF NOT EXISTS idx_claims_status ON claims(status)")
            )
            conn.execute(
                text(
                    "CREATE INDEX IF NOT EXISTS idx_claims_supersedes "
                    "ON claims(supersedes_id)"
                )
            )
        conn.commit()


def init_db() -> None:
    # Import models so Claim (and others) register on Base.metadata before create_all.
    import app.models  # noqa: F401

    settings.data_dir.mkdir(parents=True, exist_ok=True)
    settings.exports_dir.mkdir(parents=True, exist_ok=True)
    (settings.data_dir / "live").mkdir(parents=True, exist_ok=True)
    Base.metadata.create_all(bind=engine)
    try:
        _migrate_sqlite()
    except Exception:
        # Fresh DB or non-sqlite: ignore
        pass
    try:
        from app.services.stage6 import null_attribute_columns

        db = SessionLocal()
        try:
            null_attribute_columns(db)
        finally:
            db.close()
    except Exception:
        pass


def get_db() -> Generator[Session, None, None]:
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()
