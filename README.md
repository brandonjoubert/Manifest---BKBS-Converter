# Manifest BKBS

[![License](https://img.shields.io/badge/License-Apache_2.0-blue.svg)](./LICENSE)
[![CI](https://github.com/brandonjoubert/Manifest---BKBS-Converter/actions/workflows/ci.yml/badge.svg)](https://github.com/brandonjoubert/Manifest---BKBS-Converter/actions/workflows/ci.yml)
[![GitHub release](https://img.shields.io/github/v/release/brandonjoubert/Manifest---BKBS-Converter?include_prereleases)](https://github.com/brandonjoubert/Manifest---BKBS-Converter/releases)

**Open-source ecosystem** for the **agent-ready business web**.

Businesses already have websites for people.  
AI agents need something more: **structured, human-approved knowledge** they can trust.

Manifest BKBS is a monorepo of tools that help you:

1. **Extract** business facts from a site  
2. **Verify** them with humans  
3. **Publish** machine layers (`llms.txt`, `graph.json`, schema.org, …) on the live domain  

> Humans keep the normal web. Agents get a queryable knowledge layer.  
> Full ecosystem map: **[ECOSYSTEM.md](./ECOSYSTEM.md)**

---

## After you clone — pick **one** product

These are **independent runtimes**. Use **one** path for production (you do not need all three).

| Product | Best for | Prerequisites | Start here |
|---------|----------|---------------|------------|
| **Python Converter** | Local PC, VPS, cPanel Python App | Python **3.10+**, pip, network | [§ Python](#1-python-converter-local-or-python-host) |
| **PHP Host Converter** | Shared hosting **without** Python | PHP **8.0+** with **`pdo_sqlite`**, `curl`, `json` | [§ PHP](#2-php-host-converter-shared-hosting) |
| **WordPress Plugin** | Existing WordPress sites | WordPress **6.0+**, PHP **8.0+** | [§ WordPress](#3-wordpress-plugin) |

**Full deploy guide (all paths, screenshots-level detail):** **[INSTALL.md](./INSTALL.md)**  
**Day-to-day product use (Python/PHP apps):** **[USER_MANUAL.md](./USER_MANUAL.md)**  
**WordPress-only product docs:** **[wordpress-plugin/README.md](./wordpress-plugin/README.md)**

```text
git clone https://github.com/brandonjoubert/Manifest---BKBS-Converter.git
cd Manifest---BKBS-Converter
```

Interactive chooser (Linux/macOS):

```bash
chmod +x installers/*.sh installers/*/*.sh run.sh 2>/dev/null || true
./installers/choose-install.sh
```

---

### 1) Python Converter (local or Python host)

```bash
# Local PC
./installers/local/install.sh
source .venv/bin/activate
./run.sh
# → http://127.0.0.1:8765
```

```bash
# VPS / Python host (after upload or on the clone)
./installers/python-host/install.sh
# then uvicorn or cPanel Setup Python App → passenger_wsgi.py / application
```

The installers **must finish with “Smoke checks passed”**. They run:

1. `pytest -q`  
2. Stage 0 export golden check  
3. Stage 1 claim-ledger contract (all three editions, static where needed)  
4. Stage 2 export-via-resolve golden check  

If any step fails, **do not** treat the install as ready — fix the error (or open an issue) before scanning real sites.

**Workflow:** Settings (optional LLM key) → Add site → Scan → **Edit before approve** → Approve → Publish live.

---

### 2) PHP Host Converter (shared hosting)

**Use the pre-built zip** (already in the clone — do not invent a new package unless you changed PHP source):

```text
installers/php-host/bkbs-php-edition.zip
```

1. Create `public_html/bkbs/` (or a subdomain docroot).  
2. Upload and extract the zip so **`install.php`** is in that folder.  
3. Browser: `https://YOURDOMAIN/bkbs/install.php`  
4. Set **default web root** to the **real** site path (e.g. `/home/YOURUSER/public_html` — not a placeholder).  
5. Install → Settings (optional LLM) → Add site → Scan → Approve → Publish live.  

**Required PHP extensions:** `pdo_sqlite`, `curl`, `json`.  
Enable them in cPanel “Select PHP Version → Extensions” if install reports PDO SQLite missing.

Details: **[installers/php-host/README.md](./installers/php-host/README.md)** · INSTALL.md Path C.

---

### 3) WordPress plugin

**Use the pre-built zip** from the clone:

```text
wordpress-plugin/manifest-bkbs-converter.zip
```

1. wp-admin → **Plugins → Add New → Upload Plugin** → choose that zip.  
2. **Activate** “Manifest BKBS Converter”.  
3. Admin menu **Manifest BKBS** → Scan → **Edit before approve** → Approve → **Publish live**.  

Public after publish: `/llms.txt`, `/graph.json`, `/schema/organization.jsonld`, etc.

**Upgrade / claim ledger (optional):** **Manifest BKBS → Tools → Run backfill** after you already have approved entities. Production publish still uses entity rows until a later stage.

Details: **[wordpress-plugin/README.md](./wordpress-plugin/README.md)**

---

## Verify the clone (developers / CI parity)

From the **monorepo root** (Python venv recommended):

```bash
python -m venv .venv && source .venv/bin/activate   # Windows: .venv\Scripts\activate
pip install -r requirements.txt

pytest -q
python scripts/verify_exports.py --edition all          # Stage 0 — entity-path exports
python scripts/stage1_contract_check.py                 # Stage 1 — claims schema + stubs (all editions)
python scripts/verify_exports_via_resolve.py --edition all  # Stage 2 — backfill + resolve vs goldens
```

| Gate | What it proves |
|------|----------------|
| **Stage 0** | Published file shapes still match frozen goldens |
| **Stage 1** | `claims` table / DDL / resolver modules exist in Python, PHP, and WordPress |
| **Stage 2** | Backfill + real resolve reconstruct the same exports (dual-path; production still reads entities) |

PHP golden checks need the `php` CLI; Stage 2 PHP via-resolve needs **`pdo_sqlite`**. Without them, those steps skip or fail clearly — install the extension for full PHP verification.

More: **[test-fixtures/README.md](./test-fixtures/README.md)** · **[CLAIM_LEDGER_IMPLEMENTATION_PLAN.md](./CLAIM_LEDGER_IMPLEMENTATION_PLAN.md)**

---

## Products (layout)

```text
                    ┌─────────────────────────────────────┐
                    │     Manifest BKBS Ecosystem         │
                    └──────────────┬──────────────────────┘
           ┌───────────────────────┼───────────────────────┐
           ▼                       ▼                       ▼
    Python Converter        PHP Host Converter      WordPress Plugin
         app/                     php/            wordpress-plugin/
```

| Concern | Approach in this repo |
|---------|------------------------|
| Different hosts | Different **products** (Python / PHP Host / WordPress) |
| Same standard | Shared BKBS goals and publish surface |
| Quality | CI + Stage 0/1/2 gates + installer smoke |
| Community | One monorepo, product labels on issues |

See **[ECOSYSTEM.md](./ECOSYSTEM.md)** for release naming and how to present the WordPress plugin.

---

## Documentation map

| Doc | Role |
|-----|------|
| **[INSTALL.md](./INSTALL.md)** | Complete install for PC / Python host / PHP host / WordPress |
| **[INSTALL.txt](./INSTALL.txt)** | Short plain-text install card |
| **[USER_MANUAL.md](./USER_MANUAL.md)** | Operating Python / PHP Host converters |
| **[wordpress-plugin/README.md](./wordpress-plugin/README.md)** | WordPress product only |
| **[installers/php-host/README.md](./installers/php-host/README.md)** | PHP upload package |
| **[test-fixtures/README.md](./test-fixtures/README.md)** | Quality gates (Stage 0/1/2) |
| **[ROADMAP.md](./ROADMAP.md)** | Future work |
| **[docs/ARCHITECTURE.md](./docs/ARCHITECTURE.md)** | Technical overview |
| **[CLAIM_LEDGER_IMPLEMENTATION_PLAN.md](./CLAIM_LEDGER_IMPLEMENTATION_PLAN.md)** | Claim ledger staged migration |

---

## Common clone / install mistakes

| Mistake | Fix |
|---------|-----|
| Expecting one process to run all three products | Pick **one** edition for production |
| Uploading raw `php/` without extract layout | Extract so **`install.php` is at the app root** |
| PHP without `pdo_sqlite` | Enable extension; re-run install |
| Building WP zip from wrong folder | Use committed **`wordpress-plugin/manifest-bkbs-converter.zip`** |
| Skipping installer smoke failures | Fix until “Smoke checks passed” |
| Publishing without approving entities | Only **approved** entities go to production exports |
| Setting web root to the admin app folder | Web root = **public site** document root (`public_html`) |

---

## Status

**v0.1** — usable products for real sites.  
Claim Ledger **Stage 0–2** are in-tree (baselines, claims table, backfill + dual-path resolve). Production publish still uses **entity rows**; backfill is optional ledger prep.

- [Open an issue](https://github.com/brandonjoubert/Manifest---BKBS-Converter/issues/new/choose)  
- [Contributing](./CONTRIBUTING.md) · [Code of Conduct](./CODE_OF_CONDUCT.md) · [Security](./SECURITY.md)

## License

**Apache License 2.0** — see [LICENSE](./LICENSE).

## Notes

- Publish/export production knowledge only after **human approval**.  
- Never commit secrets (`.env`, API keys, `php/config.php`, WP credentials).  
- Emerging agent protocols are stubbed where useful (`agent.json`) for future extension.
