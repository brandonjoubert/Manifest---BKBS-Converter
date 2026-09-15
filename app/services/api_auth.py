"""Stage 7: API token auth for /api/* (query + operator JSON)."""

from __future__ import annotations

import hmac
import os
import secrets

from fastapi import HTTPException, Request

from app.config import settings

WWW_AUTHENTICATE = {"WWW-Authenticate": "Bearer"}


def resolve_api_token() -> str:
    """Env BKBS_API_TOKEN, then settings, then persist a generated token."""
    env = (os.environ.get("BKBS_API_TOKEN") or "").strip()
    if env:
        return env
    cfg = (getattr(settings, "bkbs_api_token", "") or "").strip()
    if cfg:
        return cfg
    path = settings.data_dir / ".api_token"
    try:
        if path.is_file():
            existing = path.read_text(encoding="utf-8").strip()
            if existing:
                return existing
    except OSError:
        pass
    token = secrets.token_urlsafe(32)
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(token, encoding="utf-8")
        path.chmod(0o600)
    except OSError:
        pass
    return token


def extract_request_token(request: Request) -> str:
    auth = request.headers.get("authorization") or ""
    if auth.lower().startswith("bearer "):
        return auth[7:].strip()
    return (request.headers.get("x-api-key") or "").strip()


def tokens_match(provided: str, expected: str) -> bool:
    if not provided or not expected:
        return False
    a = provided.encode("utf-8")
    b = expected.encode("utf-8")
    if len(a) != len(b):
        return False
    return hmac.compare_digest(a, b)


def require_api_token(request: Request) -> str:
    expected = resolve_api_token()
    provided = extract_request_token(request)
    if not tokens_match(provided, expected):
        raise HTTPException(
            status_code=401,
            detail="Unauthorized",
            headers=WWW_AUTHENTICATE,
        )
    return provided
