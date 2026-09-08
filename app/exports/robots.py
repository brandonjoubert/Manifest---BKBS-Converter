"""robots.txt suggestion + live merge block (Stage 4c AIPREF opt-in)."""

from __future__ import annotations

from pathlib import Path
from typing import Any

BEGIN = "# BEGIN BKBS"
END = "# END BKBS"


def _yn(flag: bool) -> str:
    return "y" if flag else "n"


def aipref_enabled(site: Any) -> bool:
    return bool(
        getattr(site, "aipref_search", False)
        or getattr(site, "aipref_ai_input", False)
        or getattr(site, "aipref_train_ai", False)
    )


def content_usage_line(site: Any) -> str | None:
    """IETF AIPREF Content-Usage. None when all toggles are off (default)."""
    if not aipref_enabled(site):
        return None
    return (
        "Content-Usage: search="
        + _yn(bool(getattr(site, "aipref_search", False)))
        + ", ai-input="
        + _yn(bool(getattr(site, "aipref_ai_input", False)))
        + ", train-ai="
        + _yn(bool(getattr(site, "aipref_train_ai", False)))
    )


def robots_suggestion(site: Any) -> str:
    return f"""# Suggested robots.txt additions for BKBS / AI agents
# Merge carefully with your existing robots.txt

User-agent: *
Allow: /

# Explicitly allow common AI crawlers if desired
User-agent: GPTBot
Allow: /

User-agent: ClaudeBot
Allow: /

User-agent: Google-Extended
Allow: /

Sitemap: {site.base_url}/sitemap.xml
"""


def managed_robots_block(site: Any) -> str:
    base = str(site.base_url).rstrip("/")
    lines = [
        BEGIN,
        "# Machine layers for AI agents (managed by Manifest BKBS Converter)",
        "User-agent: *",
        "Allow: /llms.txt",
        "Allow: /llms-full.txt",
        "Allow: /graph.json",
        "Allow: /schema/",
        "Allow: /.well-known/agent.json",
    ]
    usage = content_usage_line(site)
    if usage:
        lines.append(usage)
    lines.append(f"Sitemap: {base}/sitemap.xml")
    lines.append(END)
    return "\n".join(lines) + "\n"


def merge_robots(root: Path, site: Any) -> str | None:
    """
    Append or replace the BKBS allow block in robots.txt.
    Always writes # END BKBS. If # BEGIN BKBS exists without END, replace from
    BEGIN to EOF (do not duplicate). Returns relative path written.
    """
    robots = root / "robots.txt"
    block = managed_robots_block(site)
    existing = robots.read_text(encoding="utf-8") if robots.exists() else ""
    robots.parent.mkdir(parents=True, exist_ok=True)
    if BEGIN in existing:
        start = existing.find(BEGIN)
        end = existing.find(END)
        if end >= 0:
            end += len(END)
            after = existing[end:].lstrip("\n")
        else:
            after = ""
        new = existing[:start].rstrip() + "\n\n" + block
        if after:
            new += "\n" + after
        robots.write_text(new.rstrip() + "\n", encoding="utf-8")
    else:
        robots.write_text((existing.rstrip() + "\n\n" + block).lstrip() + "\n", encoding="utf-8")
    return "robots.txt"
