# Quality gates & fixtures (Claim Ledger Stage 0–2)

This folder holds **frozen baselines** so a clone can prove exports and the claim-ledger foundation still work **without** scanning a live website.

## What is here

| Path | Purpose |
|------|---------|
| `stage0_site.json` | Deterministic site + entities (fixed UUIDs) |
| `entity-counts.json` | Recorded status counts for the fixture |
| `golden-v0/` + `golden-v0.zip` | **Python** full export package (local + python-host) |
| `golden-v0-php/` | **PHP** publisher output (php-host) |
| `stage0-baseline.json` | Capture metadata / hashes |
| `stage1_claims_contract.json` | Stage 1 column/edition contract |

## Install paths covered by goldens

| Install path | Edition | Golden |
|--------------|---------|--------|
| Local PC | Python | `golden-v0` |
| Python host | Python | `golden-v0` |
| PHP host | PHP | `golden-v0-php` |
| WordPress | WP plugin | No Stage 0 golden (schema + LAMP/harness + Stage 1 contract) |

## Commands a cloner should know

From monorepo root (with `pip install -r requirements.txt`):

```bash
# Unit tests (includes Stage 1/2 Python tests)
pytest -q

# Stage 0 — entity-path exports still match goldens
python scripts/verify_exports.py --edition all
# or: python scripts/verify_exports.py --edition python
# or: php php/scripts/verify_exports.php

# Stage 1 — claims table/DDL + resolver modules (Python + PHP + WordPress)
python scripts/stage1_contract_check.py

# Stage 2 — backfill + real resolve matches Stage 0 goldens (dual-path)
python scripts/verify_exports_via_resolve.py --edition all
# or: php php/scripts/verify_exports_via_resolve.php   # needs pdo_sqlite
```

**Python installers** (`installers/local/install.sh`, `installers/python-host/install.sh`) run pytest + Stage 0/1/2 for the **Python** edition automatically and **fail install** if those checks fail.

## Capture goldens (maintainers only)

```bash
python scripts/capture_golden.py --edition all
php php/scripts/capture_golden.php
```

Do **not** hand-edit golden files; re-run capture after intentional export changes.

## Notes

- Production export includes **approved** entities only (fixture includes one pending entity that must be excluded).
- Comparisons normalize timestamps (`generated_at`, ISO datetimes) for stability.
- Stage 2 production publish still uses **entity rows**; via-resolve proves claims can reconstruct the same surface.
- Plan: [CLAIM_LEDGER_IMPLEMENTATION_PLAN.md](../CLAIM_LEDGER_IMPLEMENTATION_PLAN.md)
