"""Website crawler: robots, sitemap, BFS fetch, text + JSON-LD extraction."""

from __future__ import annotations

import asyncio
import ipaddress
import json
import logging
import re
import socket
from dataclasses import dataclass, field
from urllib.parse import urljoin, urlparse, urldefrag
from urllib.robotparser import RobotFileParser
from xml.etree import ElementTree as ET

import httpx
from bs4 import BeautifulSoup

from app.config import settings

logger = logging.getLogger(__name__)

SKIP_EXTENSIONS = {
    ".pdf", ".jpg", ".jpeg", ".png", ".gif", ".webp", ".svg", ".ico",
    ".mp4", ".mp3", ".zip", ".gz", ".css", ".js", ".woff", ".woff2",
    ".ttf", ".eot", ".map", ".xml",  # skip generic xml except sitemap handled separately
}

USER_AGENT = "BKBSConverter/1.0 (+https://github.com/local/bkbs-converter; agent-friendly crawler)"


@dataclass
class CrawledPage:
    url: str
    status_code: int | None
    title: str | None
    content_text: str
    json_ld: list
    meta: dict
    links: list[str] = field(default_factory=list)
    error: str | None = None


@dataclass
class CrawlResult:
    pages: list[CrawledPage]
    robots_raw: str | None = None
    sitemap_urls: list[str] = field(default_factory=list)
    stats: dict = field(default_factory=dict)


def normalize_url(url: str) -> str:
    url, _ = urldefrag(url)
    parsed = urlparse(url)
    # Drop default ports, normalize trailing slash for root only
    path = parsed.path or "/"
    if path != "/" and path.endswith("/"):
        path = path.rstrip("/")
    cleaned = parsed._replace(path=path, query="", fragment="").geturl()
    return cleaned


def same_origin(a: str, b: str) -> bool:
    pa, pb = urlparse(a), urlparse(b)
    return pa.scheme == pb.scheme and pa.netloc.lower() == pb.netloc.lower()


def is_skippable(url: str) -> bool:
    path = urlparse(url).path.lower()
    for ext in SKIP_EXTENSIONS:
        if path.endswith(ext):
            # allow sitemap.xml
            if path.endswith("sitemap.xml") or path.endswith("sitemap_index.xml"):
                return False
            return True
    return False


def host_is_private(hostname: str) -> bool:
    if not hostname:
        return True
    host = hostname.lower()
    if host in ("localhost", "127.0.0.1", "::1"):
        return True
    try:
        infos = socket.getaddrinfo(host, None)
    except socket.gaierror:
        return False
    for info in infos:
        ip_str = info[4][0]
        try:
            ip = ipaddress.ip_address(ip_str)
            if (
                ip.is_private
                or ip.is_loopback
                or ip.is_link_local
                or ip.is_reserved
                or ip.is_multicast
            ):
                return True
        except ValueError:
            continue
    return False


def assert_url_allowed(url: str) -> None:
    parsed = urlparse(url)
    if parsed.scheme not in ("http", "https"):
        raise ValueError(f"Unsupported scheme: {parsed.scheme}")
    if not settings.bkbs_allow_private_urls and host_is_private(parsed.hostname or ""):
        raise ValueError(
            f"Refusing to crawl private/local host {parsed.hostname}. "
            "Set BKBS_ALLOW_PRIVATE_URLS=1 to allow."
        )


def extract_json_ld(soup: BeautifulSoup) -> list:
    blocks = []
    for tag in soup.find_all("script", type=lambda t: t and "ld+json" in t.lower()):
        raw = tag.string or tag.get_text() or ""
        raw = raw.strip()
        if not raw:
            continue
        try:
            data = json.loads(raw)
            if isinstance(data, list):
                blocks.extend(data)
            else:
                blocks.append(data)
        except json.JSONDecodeError:
            continue
    return blocks


def html_to_text(soup: BeautifulSoup, limit: int) -> str:
    for tag in soup(["script", "style", "noscript", "svg", "iframe"]):
        tag.decompose()
    # Prefer main/article if present
    root = soup.find("main") or soup.find("article") or soup.body or soup
    text = root.get_text(separator="\n", strip=True)
    # Collapse blank lines
    lines = [ln.strip() for ln in text.splitlines() if ln.strip()]
    text = "\n".join(lines)
    if len(text) > limit:
        text = text[:limit] + "\n…[truncated]"
    return text


def extract_meta(soup: BeautifulSoup) -> dict:
    meta: dict = {}
    title = soup.title.string.strip() if soup.title and soup.title.string else None
    meta["title"] = title
    desc = soup.find("meta", attrs={"name": re.compile("^description$", re.I)})
    if desc and desc.get("content"):
        meta["description"] = desc["content"].strip()
    og_title = soup.find("meta", property="og:title")
    if og_title and og_title.get("content"):
        meta["og_title"] = og_title["content"].strip()
    # Contact-ish patterns later handled by heuristic
    emails = set(re.findall(r"[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}", soup.get_text(" ")))
    phones = set(re.findall(r"\+?\d[\d\s\-()]{7,}\d", soup.get_text(" ")))
    if emails:
        meta["emails"] = sorted(emails)[:10]
    if phones:
        meta["phones"] = sorted(phones)[:10]
    return meta


def extract_links(soup: BeautifulSoup, base_url: str) -> list[str]:
    links = []
    for a in soup.find_all("a", href=True):
        href = a["href"].strip()
        if href.startswith(("mailto:", "tel:", "javascript:", "#")):
            continue
        absolute = normalize_url(urljoin(base_url, href))
        links.append(absolute)
    return links


async def fetch_text(client: httpx.AsyncClient, url: str) -> tuple[int | None, str | None, str | None]:
    try:
        resp = await client.get(url, follow_redirects=True)
        content_type = resp.headers.get("content-type", "")
        if resp.status_code >= 400:
            return resp.status_code, None, f"HTTP {resp.status_code}"
        # Cap body
        body = resp.content[: settings.max_page_bytes]
        if "html" not in content_type and not body.lstrip().startswith((b"<!DOCTYPE", b"<html", b"<HTML", b"<")):
            # still try if looks like html
            if b"<" not in body[:200]:
                return resp.status_code, None, f"Non-HTML content-type: {content_type}"
        try:
            text = body.decode(resp.encoding or "utf-8", errors="replace")
        except Exception:
            text = body.decode("utf-8", errors="replace")
        return resp.status_code, text, None
    except Exception as exc:
        return None, None, str(exc)


async def load_robots(client: httpx.AsyncClient, base_url: str) -> tuple[RobotFileParser | None, str | None]:
    robots_url = urljoin(base_url.rstrip("/") + "/", "robots.txt")
    try:
        resp = await client.get(robots_url, follow_redirects=True)
        if resp.status_code != 200:
            return None, None
        raw = resp.text
        rp = RobotFileParser()
        rp.parse(raw.splitlines())
        return rp, raw
    except Exception:
        return None, None


def parse_sitemap_xml(xml_text: str) -> list[str]:
    urls: list[str] = []
    try:
        root = ET.fromstring(xml_text)
    except ET.ParseError:
        return urls
    # Handle namespaces
    for elem in root.iter():
        tag = elem.tag.split("}")[-1] if "}" in elem.tag else elem.tag
        if tag == "loc" and elem.text:
            urls.append(elem.text.strip())
    return urls


async def load_sitemap_urls(client: httpx.AsyncClient, base_url: str) -> list[str]:
    candidates = [
        urljoin(base_url.rstrip("/") + "/", "sitemap.xml"),
        urljoin(base_url.rstrip("/") + "/", "sitemap_index.xml"),
    ]
    found: list[str] = []
    for sm_url in candidates:
        try:
            resp = await client.get(sm_url, follow_redirects=True)
            if resp.status_code != 200:
                continue
            locs = parse_sitemap_xml(resp.text)
            # If index of sitemaps, fetch first few child sitemaps
            child_sitemaps = [u for u in locs if "sitemap" in u.lower() and u.endswith(".xml")]
            page_urls = [u for u in locs if u not in child_sitemaps]
            found.extend(page_urls)
            for child in child_sitemaps[:5]:
                try:
                    cr = await client.get(child, follow_redirects=True)
                    if cr.status_code == 200:
                        found.extend(parse_sitemap_xml(cr.text))
                except Exception:
                    continue
            if found:
                break
        except Exception:
            continue
    # Dedup preserve order
    seen = set()
    out = []
    for u in found:
        nu = normalize_url(u)
        if nu not in seen:
            seen.add(nu)
            out.append(nu)
    return out


async def crawl_site(
    base_url: str,
    max_pages: int = 40,
    crawl_delay_ms: int = 300,
) -> CrawlResult:
    base_url = normalize_url(base_url)
    assert_url_allowed(base_url)

    headers = {"User-Agent": USER_AGENT, "Accept": "text/html,application/xhtml+xml"}
    timeout = httpx.Timeout(20.0, connect=10.0)

    async with httpx.AsyncClient(headers=headers, timeout=timeout) as client:
        robots, robots_raw = await load_robots(client, base_url)
        sitemap_urls = await load_sitemap_urls(client, base_url)

        # Seed queue: base, then sitemap (same origin only)
        queue: list[str] = [base_url]
        for u in sitemap_urls:
            if same_origin(base_url, u) and not is_skippable(u):
                queue.append(u)

        seen: set[str] = set()
        pages: list[CrawledPage] = []
        errors = 0

        while queue and len(pages) < max_pages:
            url = queue.pop(0)
            url = normalize_url(url)
            if url in seen:
                continue
            if not same_origin(base_url, url):
                continue
            if is_skippable(url):
                continue
            if robots and not robots.can_fetch(USER_AGENT, url):
                # Also try * 
                if not robots.can_fetch("*", url):
                    continue
            seen.add(url)

            status, html, err = await fetch_text(client, url)
            if err or not html:
                errors += 1
                pages.append(
                    CrawledPage(
                        url=url,
                        status_code=status,
                        title=None,
                        content_text="",
                        json_ld=[],
                        meta={},
                        error=err,
                    )
                )
            else:
                soup = BeautifulSoup(html, "lxml")
                meta = extract_meta(soup)
                title = meta.get("title")
                text = html_to_text(soup, settings.page_text_limit)
                json_ld = extract_json_ld(soup)
                links = extract_links(soup, url)
                pages.append(
                    CrawledPage(
                        url=url,
                        status_code=status,
                        title=title,
                        content_text=text,
                        json_ld=json_ld,
                        meta=meta,
                        links=links,
                    )
                )
                for link in links:
                    if link not in seen and same_origin(base_url, link) and not is_skippable(link):
                        queue.append(link)

            if crawl_delay_ms > 0:
                await asyncio.sleep(crawl_delay_ms / 1000.0)

        # Prefer successful HTML pages only for downstream extraction
        ok_pages = [p for p in pages if p.content_text and not p.error]

        return CrawlResult(
            pages=ok_pages,
            robots_raw=robots_raw,
            sitemap_urls=sitemap_urls,
            stats={
                "requested": len(seen),
                "ok": len(ok_pages),
                "errors": errors,
                "sitemap_count": len(sitemap_urls),
                "max_pages": max_pages,
            },
        )
