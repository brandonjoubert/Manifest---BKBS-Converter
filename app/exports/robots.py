"""robots.txt suggestion + live merge block (Stage 4b). AIPREF toggles are Stage 4c."""

from __future__ import annotations

from pathlib import Path
from typing import Any


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
    marker = "# BEGIN BKBS"
    end_marker = "# END BKBS"
    return f"""{marker}
# Machine layers for AI agents (managed by Manifest BKBS Converter)
User-agent: *
Allow: /llms.txt
Allow: /llms-full.txt
Allow: /graph.json
Allow: /schema/
Allow: /.well-known/agent.json

Sitemap: {site.base_url.rstrip('/')}/sitemap.xml
{end_marker}
"""


def merge_robots(root: Path, site: Any) -> str | None:
    """
    Append BKBS allow rules to robots.txt if missing.
    Never deletes existing rules. Returns relative path written or None.
    """
    robots = root / "robots.txt"
    marker = "# BEGIN BKBS"
    end_marker = "# END BKBS"
    block = managed_robots_block(site)
    existing = robots.read_text(encoding="utf-8") if robots.exists() else ""
    robots.parent.mkdir(parents=True, exist_ok=True)
    if marker in existing:
        start = existing.find(marker)
        end = existing.find(end_marker)
        if end >= 0:
            end += len(end_marker)
            new = existing[:start].rstrip() + "\n\n" + block
            if end < len(existing):
                new += "\n" + existing[end:].lstrip()
            robots.write_text(new.rstrip() + "\n", encoding="utf-8")
        else:
            robots.write_text(existing.rstrip() + "\n\n" + block, encoding="utf-8")
    else:
        robots.write_text((existing.rstrip() + "\n\n" + block).lstrip() + "\n", encoding="utf-8")
    return "robots.txt"
