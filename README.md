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

## Products

| Product | Best for | Start here |
|---------|----------|------------|
| **Python Converter** | Local PC, VPS, cPanel Python App | [`app/`](./app/) · [INSTALL.md](./INSTALL.md) (Paths A–B) |
| **PHP Host Converter** | Shared hosting without Python | [`php/`](./php/) · zip in [`installers/php-host/`](./installers/php-host/) |
| **WordPress Plugin** | Existing WordPress sites | [`wordpress-plugin/`](./wordpress-plugin/) · [plugin README](./wordpress-plugin/README.md) |

All products share the **BKBS** idea and the same public file goals.  
They are **independent runtimes** — pick one path, not all three.

```text
                    ┌─────────────────────────────────────┐
                    │     Manifest BKBS Ecosystem         │
                    │  dual-purpose web presence tools    │
                    └──────────────┬──────────────────────┘
           ┌───────────────────────┼───────────────────────┐
           ▼                       ▼                       ▼
    Python Converter        PHP Host Converter      WordPress Plugin
         app/                     php/            wordpress-plugin/
```

---

## WordPress plugin (quick path)

For site owners who already run WordPress:

1. Download **`wordpress-plugin/manifest-bkbs-converter.zip`**  
   (or from a [GitHub Release](https://github.com/brandonjoubert/Manifest---BKBS-Converter/releases) when tagged as a plugin asset)  
2. wp-admin → **Plugins → Upload** → Activate  
3. Open **Manifest BKBS** → Scan → **Edit before approve** → Approve → Publish  

Public machine URLs after publish:

- `https://yoursite.com/llms.txt`  
- `https://yoursite.com/graph.json`  

Plugin-only docs: **[wordpress-plugin/README.md](./wordpress-plugin/README.md)**  
(WordPress edition does **not** require the Python or PHP Host apps.)

---

## Python & PHP Host converters

**Full install guide (PC / Python host / PHP host):**

### → [INSTALL.md](./INSTALL.md) ←

| Deploy on… | Path | Action |
|------------|------|--------|
| Your PC | A | `./installers/local/install.sh` |
| Python host | B | `./installers/python-host/install.sh` or cPanel + `passenger_wsgi.py` |
| PHP-only host | C | Upload `installers/php-host/bkbs-php-edition.zip` → `install.php` |

```bash
./installers/choose-install.sh
```

Product usage (Python/PHP Host apps): [USER_MANUAL.md](./USER_MANUAL.md)

---

## Why this is an ecosystem, not one app

| Concern | Approach in this repo |
|---------|------------------------|
| Different hosts | Different **products** (Python / PHP Host / WordPress) |
| Same standard | Shared BKBS goals and publish surface |
| Quality | Shared CI, Stage 0 fixtures, claim ledger roadmap |
| Community | One monorepo, product labels on issues, multi-asset releases |

See **[ECOSYSTEM.md](./ECOSYSTEM.md)** for layout, release naming, and how to publish the WordPress plugin without looking like a “random folder in an app repo.”

---

## Documentation map

| Doc | Role |
|-----|------|
| [ECOSYSTEM.md](./ECOSYSTEM.md) | Monorepo / product map / GitHub presentation |
| [INSTALL.md](./INSTALL.md) | Python & PHP Host deploy |
| [USER_MANUAL.md](./USER_MANUAL.md) | Operating the Python/PHP Host converters |
| [wordpress-plugin/README.md](./wordpress-plugin/README.md) | WordPress product only |
| [ROADMAP.md](./ROADMAP.md) | Future work |
| [docs/ARCHITECTURE.md](./docs/ARCHITECTURE.md) | Technical overview |
| [CLAIM_LEDGER_IMPLEMENTATION_PLAN.md](./CLAIM_LEDGER_IMPLEMENTATION_PLAN.md) | Planned architecture upgrade (staged) |

---

## Quick start (Python, local)

```bash
git clone https://github.com/brandonjoubert/Manifest---BKBS-Converter.git
cd Manifest---BKBS-Converter
./installers/local/install.sh
source .venv/bin/activate
./run.sh
```

Open **http://127.0.0.1:8765**

---

## Status

**v0.1** — usable products for real sites.  
Agent-era web presence is early; feedback and contributions welcome.

- [Open an issue](https://github.com/brandonjoubert/Manifest---BKBS-Converter/issues/new/choose) (choose product when relevant)  
- [Contributing](./CONTRIBUTING.md) · [Code of Conduct](./CODE_OF_CONDUCT.md) · [Security](./SECURITY.md)

## License

**Apache License 2.0** — see [LICENSE](./LICENSE).

## Notes

- Publish/export production knowledge only after **human approval**.  
- Never commit secrets (`.env`, API keys, `php/config.php`, WP credentials).  
- Emerging agent protocols are stubbed where useful (`agent.json`) for future extension.
