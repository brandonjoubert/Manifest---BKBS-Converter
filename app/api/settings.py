"""LLM / app settings API."""

from __future__ import annotations

from fastapi import APIRouter, Depends
from pydantic import BaseModel, Field
from sqlalchemy.orm import Session

from app.db import get_db
from app.services.llm_settings import (
    public_llm_status,
    save_llm_settings,
    test_llm_connection,
)

router = APIRouter(prefix="/api/settings", tags=["settings"])


class LlmSettingsUpdate(BaseModel):
    provider: str = "custom"
    api_key: str | None = Field(default=None, description="Omit or empty to keep existing key")
    base_url: str = ""
    model: str = ""
    enabled: bool = True
    clear_key: bool = False


@router.get("/llm")
def get_llm_settings(db: Session = Depends(get_db)):
    return public_llm_status(db)


@router.put("/llm")
def put_llm_settings(body: LlmSettingsUpdate, db: Session = Depends(get_db)):
    save_llm_settings(
        db,
        provider=body.provider,
        api_key=body.api_key,
        base_url=body.base_url,
        model=body.model,
        enabled=body.enabled,
        clear_key=body.clear_key,
    )
    return public_llm_status(db)


@router.post("/llm/test")
def post_test_llm(db: Session = Depends(get_db)):
    return test_llm_connection(db)
