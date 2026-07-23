"""Application configuration."""

from __future__ import annotations

from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict

ROOT_DIR = Path(__file__).resolve().parent.parent
DEFAULT_DATA_DIR = ROOT_DIR / "data"


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=str(ROOT_DIR / ".env"),
        env_file_encoding="utf-8",
        extra="ignore",
    )

    # Env fallbacks (UI/database settings take priority when set)
    xai_api_key: str = ""
    openai_api_key: str = ""
    llm_api_key: str = ""
    llm_base_url: str = ""
    llm_provider: str = "xai"
    bkbs_model: str = "grok-4.5"
    bkbs_allow_private_urls: bool = True
    bkbs_host: str = "127.0.0.1"
    bkbs_port: int = 8765
    bkbs_data_dir: str = str(DEFAULT_DATA_DIR)
    # Default public web root when site.publish_root is empty (shared hosting)
    # e.g. /home/username/public_html or ../public_html
    default_publish_root: str = ""

    # Crawl defaults
    default_max_pages: int = 40
    default_crawl_delay_ms: int = 300
    max_page_bytes: int = 2_000_000
    page_text_limit: int = 12_000
    llm_batch_pages: int = 6

    @property
    def data_dir(self) -> Path:
        path = Path(self.bkbs_data_dir)
        if not path.is_absolute():
            path = ROOT_DIR / path
        return path

    @property
    def db_path(self) -> Path:
        return self.data_dir / "bkbs.db"

    @property
    def exports_dir(self) -> Path:
        return self.data_dir / "exports"

    @property
    def has_llm(self) -> bool:
        """Env-only check. Prefer resolve_llm_config(db).is_configured for runtime."""
        return bool(
            self.xai_api_key.strip()
            or self.llm_api_key.strip()
            or self.openai_api_key.strip()
        )


settings = Settings()
