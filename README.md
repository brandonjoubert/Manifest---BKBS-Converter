# Manifest BKBS Converter

[![License](https://img.shields.io/badge/License-Apache_2.0-blue.svg)](./LICENSE)
[![CI](https://github.com/brandonjoubert/Manifest---BKBS-Converter/actions/workflows/ci.yml/badge.svg)](https://github.com/brandonjoubert/Manifest---BKBS-Converter/actions/workflows/ci.yml)
[![Python 3.10+](https://img.shields.io/badge/python-3.10+-blue.svg)](https://www.python.org/downloads/)
[![PHP 8+](https://img.shields.io/badge/php-8.0+-purple.svg)](https://www.php.net/)
[![GitHub release](https://img.shields.io/github/v/release/brandonjoubert/Manifest---BKBS-Converter?include_prereleases)](https://github.com/brandonjoubert/Manifest---BKBS-Converter/releases)

**Open-source** web application that scans a business website and converts it into a **Business Knowledge Base Standard (BKBS)** package: agent-readable `llms.txt`, schema.org JSON-LD, full `graph.json`, and related machine layers — with **human verification** and **manual entry**.

Humans keep browsing your HTML site. AI agents get structured, approved facts instead of scraping noise.

> **Status:** v0.1 — usable for real sites; see [ROADMAP.md](./ROADMAP.md).  
> **Screenshots:** add images under [`docs/screenshots/`](./docs/screenshots/) (placeholder until captured).

## Who it is for

- Businesses that want AI agents to read **accurate** services, capabilities, and policies  
- Agencies packaging “agent-ready” / BKBS deliverables  
- Operators on **local PC**, **Python hosts**, or **PHP-only shared hosting**

## Installation (start here)

**Complete step-by-step guide for all environments:**

### → [INSTALL.md](./INSTALL.md) ←

Also: plain-text quick card **[INSTALL.txt](./INSTALL.txt)**

| Deploy on… | Path | Command / action |
|------------|------|------------------|
| **Your PC** | A | `./installers/local/install.sh` or `installers\local\install.bat` |
| **Python host** | B | `./installers/python-host/install.sh` or cPanel + `passenger_wsgi.py` |
| **Non-Python host** | C | Upload `installers/php-host/bkbs-php-edition.zip` → extract → `install.php` |

```bash
./installers/choose-install.sh   # interactive menu
```

- **Python edition** (full): project root / `app/`  
- **PHP edition** (no Python): `php/` · **ready zip:** `installers/php-host/bkbs-php-edition.zip`

**Using the product after install:** [USER_MANUAL.md](./USER_MANUAL.md)  
**Python cPanel/VPS publish detail:** [deploy/SHARED_HOSTING.md](./deploy/SHARED_HOSTING.md)

## Features

- **Automated scan** of a website (robots.txt, sitemap, BFS crawl)
- **Heuristic + SpaceXAI (Grok)** extraction into 12 BKBS entity types
- **Rescan / merge** without blindly overwriting approved facts
- **Human verification** (approve / edit / reject / bulk actions)
- **Manual entry** via forms and free-text → AI conversion
- **Export ZIP** ready to deploy (`llms.txt`, JSON-LD, `graph.json`, `agent.json` stub, robots/sitemap suggestions)

## Quick start (Python, local)

```bash
git clone https://github.com/brandonjoubert/Manifest---BKBS-Converter.git
cd Manifest---BKBS-Converter
./installers/local/install.sh
source .venv/bin/activate
./run.sh
```

Open **http://127.0.0.1:8765**

Configure an LLM under **Settings** (any OpenAI-compatible API), or set keys in `.env` (see `.env.example`).

### LLM API (any OpenAI-compatible provider)

**Preferred:** open **Settings** in the UI (`/settings`) and save:

- Provider preset (xAI, OpenAI, OpenRouter, Groq, Ollama, Custom, …)
- API base URL
- Model name
- API key

Then **Test connection** and re-scan sites for richer extraction.

**Optional env fallbacks** (used only if no UI settings are saved):

| Variable | Purpose |
|----------|---------|
| `LLM_API_KEY` / `XAI_API_KEY` / `OPENAI_API_KEY` | API key |
| `LLM_BASE_URL` | e.g. `https://api.openai.com/v1` |
| `LLM_PROVIDER` | Preset id (`xai`, `openai`, …) |
| `BKBS_MODEL` | Model name |
| `BKBS_ALLOW_PRIVATE_URLS` | `1` to allow localhost/LAN crawl targets |
| `BKBS_DATA_DIR` | SQLite + exports directory (default `./data`) |

## Workflow

1. **Add site** — name + base URL  
2. **Scan** — crawl pages → heuristic + Grok map to entities (status `pending`)  
3. **Review** — approve / edit / reject each entity  
4. **Manual fill** — forms or paste notes → AI convert  
5. **Rescan** when the site changes — new/changed items re-enter review; approved material changes become `needs_edit`  
6. **Export** — download ZIP and deploy files to the origin site  

## Export layout

```
llms.txt
llms-full.txt
graph.json
schema/organization.jsonld
schema/services.jsonld
.well-known/agent.json
robots.txt.suggestion
sitemap.xml.suggestion
README.md
```

## API (selected)

- `POST /api/sites` — create site  
- `POST /api/sites/{id}/scan` — queue scan  
- `GET /api/sites/{id}/entities` — list entities  
- `POST /api/entities/{id}/verify` — `{ "action": "approve" }`  
- `POST /api/sites/{id}/entities/from-text` — free text → entities  
- `POST /api/sites/{id}/export` — build package  
- `GET /api/exports/{id}/download` — ZIP  

Interactive docs: `/docs`

## Local demo (any static site)

```bash
# Terminal 1 — serve a local website folder
cd /path/to/your-static-site && python3 -m http.server 8090

# Terminal 2 — BKBS app with BKBS_ALLOW_PRIVATE_URLS=1 in .env
# Add site URL: http://127.0.0.1:8090 → Scan → Approve → Publish
```

## Tests

```bash
source .venv/bin/activate
pytest -q
```

## Stack

| Edition | Stack |
|---------|--------|
| Python | FastAPI · SQLAlchemy · SQLite · httpx · BeautifulSoup · OpenAI-compatible LLM clients · Jinja2 |
| PHP | PDO SQLite · cURL · plain PHP 8 templates |

## Project layout

```text
app/                 Python edition
php/                 PHP edition (shared hosting)
installers/          Local / Python-host / PHP-host installers + PHP zip
deploy/              Extra hosting notes
tests/               Python tests
INSTALL.md           Full install guide
USER_MANUAL.md       Product user guide
```

## Documentation map

| Doc | Purpose |
|-----|---------|
| [INSTALL.md](./INSTALL.md) | Deploy on PC / Python host / PHP host |
| [USER_MANUAL.md](./USER_MANUAL.md) | Day-to-day product use |
| [ROADMAP.md](./ROADMAP.md) | What’s next |
| [docs/ARCHITECTURE.md](./docs/ARCHITECTURE.md) | How the system is built |
| [deploy/SHARED_HOSTING.md](./deploy/SHARED_HOSTING.md) | Python cPanel / VPS publish |
| [CONTRIBUTING.md](./CONTRIBUTING.md) | How to contribute |
| [SECURITY.md](./SECURITY.md) | Vulnerability reporting |

## Contributing

See [CONTRIBUTING.md](./CONTRIBUTING.md). Please read [CODE_OF_CONDUCT.md](./CODE_OF_CONDUCT.md) and [SECURITY.md](./SECURITY.md).

Bug reports and features: [open an issue](https://github.com/brandonjoubert/Manifest---BKBS-Converter/issues/new/choose).

## License

Licensed under the **Apache License 2.0** — see [LICENSE](./LICENSE).

## Notes

- Production publish/export should use **approved** entities only.  
- Never commit `.env`, API keys, or `php/config.php`.  
- Emerging agent layers (WebMCP, commerce protocols) are stubbed via `agent.json` for future extension.
