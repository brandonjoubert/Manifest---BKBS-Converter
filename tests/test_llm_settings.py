from app.db import SessionLocal, init_db
from app.services.llm_settings import (
    resolve_llm_config,
    save_llm_settings,
)


def test_save_and_resolve_llm_settings():
    init_db()
    db = SessionLocal()
    try:
        save_llm_settings(
            db,
            provider="openai",
            api_key="sk-test-key-1234567890",
            base_url="https://api.openai.com/v1",
            model="gpt-4o-mini",
            enabled=True,
        )
        cfg = resolve_llm_config(db)
        assert cfg.is_configured
        assert cfg.provider == "openai"
        assert cfg.model == "gpt-4o-mini"
        assert cfg.api_key.endswith("7890")
        assert cfg.source == "database"

        # Empty key keeps previous
        save_llm_settings(
            db,
            provider="openai",
            api_key=None,
            base_url="https://api.openai.com/v1",
            model="gpt-4o",
            enabled=True,
        )
        cfg2 = resolve_llm_config(db)
        assert cfg2.model == "gpt-4o"
        assert cfg2.api_key.endswith("7890")

        # Clear key
        save_llm_settings(
            db,
            provider="openai",
            api_key=None,
            base_url="https://api.openai.com/v1",
            model="gpt-4o",
            enabled=True,
            clear_key=True,
        )
        cfg3 = resolve_llm_config(db)
        assert cfg3.api_key == ""
    finally:
        db.close()


def test_ollama_local_without_key():
    init_db()
    db = SessionLocal()
    try:
        save_llm_settings(
            db,
            provider="ollama",
            api_key="",
            base_url="http://127.0.0.1:11434/v1",
            model="llama3.2",
            enabled=True,
            clear_key=True,
        )
        cfg = resolve_llm_config(db)
        assert cfg.is_configured  # localhost allows empty key
    finally:
        db.close()
