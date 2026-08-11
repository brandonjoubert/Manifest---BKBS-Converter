# Manifest BKBS — Ecosystem overview

This repository is a **monorepo for the Manifest BKBS ecosystem**: open tools that help businesses publish a **dual-purpose web presence** (human HTML + machine-trustworthy knowledge).

It is **not** a single-purpose app folder. Multiple **products** share one standard (BKBS) and one contribution surface.

---

## Vision

| Layer | Audience | Goal |
|-------|----------|------|
| **Human web** | People | Normal site / CMS experience |
| **Machine web** | AI agents & crawlers | Accurate, approved facts (`llms.txt`, graph, schema.org) |
| **Manifest tools** | Operators & developers | Scan → verify → publish that machine layer |

---

## Products in this monorepo

| Product | Path | Who installs it | Runtime |
|---------|------|-----------------|---------|
| **Manifest BKBS Converter (Python)** | `app/` | VPS, local PC, cPanel Python App | FastAPI |
| **Manifest BKBS Converter (PHP Host)** | `php/` | Shared hosting without Python | PHP 8 |
| **Manifest BKBS Converter (WordPress)** | `wordpress-plugin/` | WordPress site owners | WP plugin |
| **Installers & packages** | `installers/`, zips | Operators | Shell / upload |
| **Fixtures & baselines** | `test-fixtures/`, `scripts/` | Maintainers / CI | Stage 0/1/2 quality gates (see test-fixtures/README.md) |

All products aim at the **same publish surface**:

```text
/llms.txt
/llms-full.txt
/graph.json
/schema/*.jsonld
/.well-known/agent.json
```

---

## How the WordPress plugin fits

The WordPress plugin is a **first-class product**, not an afterthought:

- Own source tree: `wordpress-plugin/manifest-bkbs-converter/`
- Own distribution zip: `wordpress-plugin/manifest-bkbs-converter.zip`
- Own plugin `readme.txt` (WordPress.org style)
- Own install guide: `wordpress-plugin/README.md`

It does **not** require the Python or PHP Host runtimes. It speaks the same BKBS *language* (entities, approve, publish).

### Suggested release naming

| GitHub Release tag | What it ships |
|--------------------|---------------|
| `v0.1.0` | Ecosystem snapshot (all products at that date) |
| `wordpress-plugin-v0.1.0` | WordPress plugin only (attach `manifest-bkbs-converter.zip`) |
| `php-host-v0.1.0` | Optional: PHP shared-host zip only |

Use **ecosystem tags** for monorepo milestones and **product tags** when you want a clean plugin download story.

---

## Repository layout (ecosystem map)

```text
Manifest---BKBS-Converter/          ← ecosystem monorepo
├── README.md                       ← ecosystem front door
├── ECOSYSTEM.md                    ← this file
├── app/                            ← Product: Python Converter
├── php/                            ← Product: PHP Host Converter
├── wordpress-plugin/               ← Product: WordPress Converter
│   ├── README.md
│   ├── manifest-bkbs-converter/    ← installable plugin source
│   └── manifest-bkbs-converter.zip ← distributable
├── installers/                     ← Operator tooling (all hosts)
├── test-fixtures/ · scripts/       ← Quality / Stage 0 baselines
├── docs/                           ← Shared architecture & screenshots
└── .github/                        ← CI, issues, community for the ecosystem
```

---

## Publishing the WordPress plugin on GitHub (ecosystem style)

### Option A — Stay in this monorepo (recommended for now)

1. Keep the plugin under `wordpress-plugin/`.
2. Root README lists it as a **product card** (not a “bonus folder”).
3. Create a **GitHub Release** titled e.g.  
   `WordPress Plugin v0.1.0`  
   with asset: `wordpress-plugin/manifest-bkbs-converter.zip`.
4. In the release notes, link back to the monorepo and the other products.
5. Topics on the repo include both `wordpress-plugin` and `bkbs` / `ai-agents`.

**Pros:** one issue tracker, one CI story, one standard.  
**Cons:** WordPress users see other products in the same repo (mitigated by clear product cards).

### Option B — Sibling repo under the same org/user (later)

Create e.g. `Manifest-BKBS-WordPress` that:

- Contains only the plugin (or git subtree / mirror of `wordpress-plugin/manifest-bkbs-converter`), and  
- README says: “Part of the [Manifest BKBS ecosystem](link-to-monorepo).”

**Pros:** classic WordPress-looking single-plugin repo.  
**Cons:** dual maintenance unless automated with subtree/submodule.

### Option C — GitHub org as brand

`github.com/manifest-bkbs/` (example) with:

- `ecosystem` or `manifest-bkbs` monorepo  
- `wordpress-plugin`  
- optional `docs` / `spec` later  

Use when you want brand separation from a personal username.

---

## What “ecosystem presentation” looks like on GitHub

| Element | Ecosystem style |
|---------|-----------------|
| **Repo description** | “Manifest BKBS ecosystem — tools for agent-ready business knowledge (Python · PHP · WordPress)” |
| **Topics** | `bkbs`, `ai-agents`, `llms-txt`, `wordpress-plugin`, `fastapi`, `php`, `knowledge-graph`, `open-source` |
| **README hero** | Vision + product grid, not only “clone and run uvicorn” |
| **Releases** | Ecosystem milestones + product-specific zips |
| **Issues** | Labels by product: `product:python`, `product:php-host`, `product:wordpress` |
| **Discussions** | Categories per product (optional) |
| **Projects board** | Columns: Python / PHP Host / WordPress / Spec |

---

## Contribution surface

Contributors should be able to choose a lane:

- Improve **Python Converter** (`app/`)  
- Improve **PHP Host Converter** (`php/`)  
- Improve **WordPress plugin** (`wordpress-plugin/`)  
- Improve **shared BKBS docs / fixtures / CI**  

Issue templates and labels should mention product, not only “bug.”

---

## Non-goals for ecosystem framing

- Pretending products are unrelated brands  
- Forcing WordPress users to install Python  
- Merging plugin code into `php/` “because both are PHP”  

Separation of **products**, unity of **standard and mission**.
