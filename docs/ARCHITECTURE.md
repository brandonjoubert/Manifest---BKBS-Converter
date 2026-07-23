# Architecture — Manifest BKBS Converter

## Two editions, one goal

| Edition | Stack | Audience |
|---------|--------|----------|
| **Python** | FastAPI, SQLAlchemy, SQLite, httpx, Jinja2 | Local PC, VPS, cPanel Python App |
| **PHP** | PDO SQLite, cURL, templates | Shared hosting without Python |

Both produce the same **public machine layer** for a site:

```text
/llms.txt
/llms-full.txt
/graph.json
/schema/organization.jsonld
/schema/services.jsonld
/.well-known/agent.json
robots.txt  (merged BKBS block)
```

## High-level flow

```text
┌─────────────┐     crawl      ┌──────────────┐
│  Website    │ ─────────────► │  Extractors  │
│  (HTML)     │                │ heuristic ±  │
└─────────────┘                │ LLM (OpenAI  │
                               │ compatible)  │
                               └──────┬───────┘
                                      │ entities (pending)
                                      ▼
                               ┌──────────────┐
                               │  Review UI   │
                               │ approve/edit │
                               └──────┬───────┘
                                      │ approved graph
                          ┌───────────┴───────────┐
                          ▼                       ▼
                   Export ZIP              Publish live
                   (download)              (write web root)
```

## Python package layout

```text
app/
  main.py           UI + routes
  api/              JSON API routers
  services/         crawl, extract, merge, export, publish
  templates/        Jinja2
  models.py         SQLAlchemy
php/                Standalone PHP edition
installers/         OS-specific install helpers + PHP zip
tests/              pytest
```

## Data

- Default DB: SQLite under `data/` (Python) or `php/data/` (PHP)  
- LLM keys: UI settings DB and/or environment (never commit secrets)  
- Publish root: operator-configured absolute path to the public document root  

## LLM integration

Any provider implementing OpenAI-style `POST /chat/completions` (xAI, OpenAI, OpenRouter, Groq, Ollama, custom base URL).

## Related docs

- [INSTALL.md](../INSTALL.md) — deploy  
- [USER_MANUAL.md](../USER_MANUAL.md) — operate  
- [ROADMAP.md](../ROADMAP.md) — future work  
