"""LLM provider settings: multi-provider OpenAI-compatible config (DB + env)."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from sqlalchemy.orm import Session

from app.config import settings as env_settings
from app.models import AppSetting, utcnow

# Presets for common OpenAI-compatible chat Completions APIs.
# Users can always choose "custom" and set any base URL.
PROVIDER_PRESETS: dict[str, dict[str, str]] = {
    "xai": {
        "label": "xAI / SpaceXAI (Grok)",
        "base_url": "https://api.x.ai/v1",
        "default_model": "grok-4.5",
        "docs": "https://docs.x.ai",
        "key_hint": "XAI API key from console.x.ai",
    },
    "openai": {
        "label": "OpenAI",
        "base_url": "https://api.openai.com/v1",
        "default_model": "gpt-4o-mini",
        "docs": "https://platform.openai.com/docs",
        "key_hint": "OpenAI API key (sk-…)",
    },
    "openrouter": {
        "label": "OpenRouter",
        "base_url": "https://openrouter.ai/api/v1",
        "default_model": "openai/gpt-4o-mini",
        "docs": "https://openrouter.ai/docs",
        "key_hint": "OpenRouter API key",
    },
    "groq": {
        "label": "Groq",
        "base_url": "https://api.groq.com/openai/v1",
        "default_model": "llama-3.3-70b-versatile",
        "docs": "https://console.groq.com/docs",
        "key_hint": "Groq API key",
    },
    "together": {
        "label": "Together AI",
        "base_url": "https://api.together.xyz/v1",
        "default_model": "meta-llama/Meta-Llama-3.1-70B-Instruct-Turbo",
        "docs": "https://docs.together.ai",
        "key_hint": "Together API key",
    },
    "mistral": {
        "label": "Mistral AI",
        "base_url": "https://api.mistral.ai/v1",
        "default_model": "mistral-small-latest",
        "docs": "https://docs.mistral.ai",
        "key_hint": "Mistral API key",
    },
    "deepseek": {
        "label": "DeepSeek",
        "base_url": "https://api.deepseek.com/v1",
        "default_model": "deepseek-chat",
        "docs": "https://platform.deepseek.com",
        "key_hint": "DeepSeek API key",
    },
    "ollama": {
        "label": "Ollama (local)",
        "base_url": "http://127.0.0.1:11434/v1",
        "default_model": "llama3.2",
        "docs": "https://ollama.com",
        "key_hint": "Any value (e.g. ollama) if required by client",
    },
    "lmstudio": {
        "label": "LM Studio (local)",
        "base_url": "http://127.0.0.1:1234/v1",
        "default_model": "local-model",
        "docs": "https://lmstudio.ai",
        "key_hint": "Often lm-studio or any non-empty string",
    },
    "custom": {
        "label": "Custom (OpenAI-compatible)",
        "base_url": "",
        "default_model": "",
        "docs": "",
        "key_hint": "API key for your provider",
    },
}

KEYS = {
    "provider": "llm.provider",
    "api_key": "llm.api_key",
    "base_url": "llm.base_url",
    "model": "llm.model",
    "enabled": "llm.enabled",
}


@dataclass
class LlmConfig:
    provider: str
    api_key: str
    base_url: str
    model: str
    enabled: bool
    source: str  # "database" | "environment" | "none"

    @property
    def is_configured(self) -> bool:
        if not self.enabled:
            return False
        # Ollama-style: key can be placeholder
        if not self.base_url.strip() or not self.model.strip():
            return False
        if not self.api_key.strip():
            # Local servers sometimes accept empty keys; allow if localhost
            host = self.base_url.lower()
            return "127.0.0.1" in host or "localhost" in host
        return True

    def masked_key(self) -> str:
        key = self.api_key or ""
        if not key:
            return ""
        if len(key) <= 8:
            return "••••••••"
        return f"{key[:4]}…{key[-4:]}"


def _get_raw(db: Session, key: str) -> str | None:
    row = db.get(AppSetting, key)
    return row.value if row else None


def _set_raw(db: Session, key: str, value: str) -> None:
    row = db.get(AppSetting, key)
    if row is None:
        row = AppSetting(key=key, value=value, updated_at=utcnow())
        db.add(row)
    else:
        row.value = value
        row.updated_at = utcnow()


def get_db_llm_partial(db: Session) -> dict[str, str]:
    out: dict[str, str] = {}
    for field, key in KEYS.items():
        val = _get_raw(db, key)
        if val is not None:
            out[field] = val
    return out


def resolve_llm_config(db: Session | None = None) -> LlmConfig:
    """
    Resolve effective LLM config.
    Priority: database settings (if any api_key or base_url saved) → environment → empty.
    """
    partial: dict[str, str] = {}
    if db is not None:
        partial = get_db_llm_partial(db)

    has_db = bool(partial.get("api_key") or partial.get("base_url") or partial.get("model"))

    if has_db:
        provider = (partial.get("provider") or "custom").strip() or "custom"
        preset = PROVIDER_PRESETS.get(provider, PROVIDER_PRESETS["custom"])
        base_url = (partial.get("base_url") or preset.get("base_url") or "").strip().rstrip("/")
        model = (partial.get("model") or preset.get("default_model") or "").strip()
        api_key = (partial.get("api_key") or "").strip()
        enabled = (partial.get("enabled") or "1").strip() not in ("0", "false", "False", "no")
        return LlmConfig(
            provider=provider,
            api_key=api_key,
            base_url=base_url,
            model=model,
            enabled=enabled,
            source="database",
        )

    # Environment fallback (backward compatible)
    env_key = (
        env_settings.xai_api_key.strip()
        or env_settings.llm_api_key.strip()
        or env_settings.openai_api_key.strip()
    )
    if env_key or env_settings.llm_base_url.strip():
        base = env_settings.llm_base_url.strip().rstrip("/") or "https://api.x.ai/v1"
        model = env_settings.bkbs_model.strip() or "grok-4.5"
        provider = env_settings.llm_provider.strip() or "xai"
        return LlmConfig(
            provider=provider,
            api_key=env_key,
            base_url=base,
            model=model,
            enabled=True,
            source="environment",
        )

    return LlmConfig(
        provider="custom",
        api_key="",
        base_url="",
        model="",
        enabled=True,
        source="none",
    )


def save_llm_settings(
    db: Session,
    *,
    provider: str,
    api_key: str | None,
    base_url: str,
    model: str,
    enabled: bool = True,
    clear_key: bool = False,
) -> LlmConfig:
    """
    Persist LLM settings. If api_key is empty/None and not clear_key, keep existing key.
    """
    provider = (provider or "custom").strip()
    if provider not in PROVIDER_PRESETS:
        provider = "custom"

    preset = PROVIDER_PRESETS[provider]
    base_url = (base_url or "").strip().rstrip("/")
    if not base_url and provider != "custom":
        base_url = preset["base_url"]
    model = (model or "").strip()
    if not model and provider != "custom":
        model = preset["default_model"]

    _set_raw(db, KEYS["provider"], provider)
    _set_raw(db, KEYS["base_url"], base_url)
    _set_raw(db, KEYS["model"], model)
    _set_raw(db, KEYS["enabled"], "1" if enabled else "0")

    if clear_key:
        _set_raw(db, KEYS["api_key"], "")
    elif api_key is not None and api_key.strip():
        _set_raw(db, KEYS["api_key"], api_key.strip())
    # else leave existing key untouched

    db.commit()
    return resolve_llm_config(db)


def public_llm_status(db: Session) -> dict[str, Any]:
    cfg = resolve_llm_config(db)
    preset = PROVIDER_PRESETS.get(cfg.provider, PROVIDER_PRESETS["custom"])
    return {
        "configured": cfg.is_configured,
        "provider": cfg.provider,
        "provider_label": preset.get("label", cfg.provider),
        "base_url": cfg.base_url,
        "model": cfg.model,
        "api_key_masked": cfg.masked_key(),
        "api_key_set": bool(cfg.api_key),
        "enabled": cfg.enabled,
        "source": cfg.source,
        "presets": {
            k: {
                "label": v["label"],
                "base_url": v["base_url"],
                "default_model": v["default_model"],
                "key_hint": v["key_hint"],
            }
            for k, v in PROVIDER_PRESETS.items()
        },
    }


def test_llm_connection(db: Session) -> dict[str, Any]:
    """Send a tiny chat completion to verify credentials/endpoint."""
    from openai import OpenAI

    cfg = resolve_llm_config(db)
    if not cfg.is_configured:
        return {
            "ok": False,
            "error": "LLM is not configured. Set provider, base URL, model, and API key.",
        }
    try:
        client = OpenAI(
            api_key=cfg.api_key or "not-needed",
            base_url=cfg.base_url,
            timeout=30.0,
        )
        resp = client.chat.completions.create(
            model=cfg.model,
            messages=[
                {"role": "user", "content": 'Reply with exactly: {"ok":true}'},
            ],
            temperature=0,
            max_tokens=32,
        )
        content = (resp.choices[0].message.content or "").strip()
        return {
            "ok": True,
            "provider": cfg.provider,
            "model": cfg.model,
            "base_url": cfg.base_url,
            "response_preview": content[:200],
        }
    except Exception as exc:
        return {
            "ok": False,
            "error": str(exc),
            "provider": cfg.provider,
            "model": cfg.model,
            "base_url": cfg.base_url,
        }
