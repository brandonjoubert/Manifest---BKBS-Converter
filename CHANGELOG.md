# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Claim Ledger Stage 3** (claim-only scan merge, all three editions)
  - Python: `claim_writer` + rewritten `apply_extracted` — new entities get pending claims; rescans insert pending claims without overwriting entity attribute columns; approved + pending → `needs_edit`
  - PHP Host / WordPress: same semantics in `upsertEntity` / `upsert_entity`
  - Production export still reads entities; Stage 0 goldens unchanged
  - Tests: `tests/test_merge_stage3.py`

### Changed

- **Docs for cloners:** README, INSTALL.md/txt, WordPress + PHP installer READMEs, test-fixtures, CONTRIBUTING, and USER_MANUAL now give a clear **pick one product** path, prerequisites, smoke/quality gates (Stage 0/1/2), and common failure fixes so a fresh clone can run any edition without guesswork.

### Also added

- **Claim Ledger Stage 2** (backfill + real resolve, all three editions; dual-path only)
  - Python: `claim_codec`, `ResolvedEntity`, real `resolve_entity(db=)`, `scripts/backfill_claims.py`, `scripts/verify_exports_via_resolve.py`
  - PHP Host: real `Bkbs\Resolver`, `php/scripts/backfill_claims.php`, `php/scripts/verify_exports_via_resolve.php`
  - WordPress: `MBKBS_Backfill`, real `MBKBS_Resolver`, admin Tools “Run backfill”, `wp mbkbs backfill-claims`
  - Hybrid resolve: claims override attributes; entity row supplies id/type/external_key/version/last_updated
  - Production export/publish still reads entities (Stage 4 cutover later)
  - CI: Stage 2 export-via-resolve for Python + PHP; Stage 0 entity path still required
- **Claim Ledger Stage 1** (additive foundation, all three editions)
  - Python: `Claim` model, `claims` table via `init_db`, `app/services/resolver.py` stub
  - PHP Host: `claims` DDL in `Database::migrate`, `Bkbs\Resolver` stub
  - WordPress: `{prefix}mbkbs_claims`, `MBKBS_DB_VERSION=2`, `maybe_upgrade`, `MBKBS_Resolver` stub
  - Contract: `test-fixtures/stage1_claims_contract.json`, `scripts/stage1_contract_check.py`, CI step
  - No scan/export/UI behavior change; Stage 0 goldens still required to pass

### Fixed

- Document live-publish artifact `bkbs/README.txt` in `INSTALL.md` §8 and `deploy/SHARED_HOSTING.md` (written by Python and PHP publishers; was missing from the published-files tables)
- WordPress plugin: emit `@type: LocalBusiness` in `schema/organization.jsonld` to match Python and PHP public contract (was `Organization`)
- Publish live test asserts `bkbs/README.txt` and organization JSON-LD `@type`

### Also added (earlier)

- **Claim Ledger Stage 0** baseline fixtures and export verification
  - `test-fixtures/stage0_site.json`, `golden-v0` (Python), `golden-v0-php` (PHP)
  - `scripts/capture_golden.py`, `scripts/verify_exports.py`
  - `php/scripts/capture_golden.php`, `php/scripts/verify_exports.php`
  - Covers all three install paths (local / python-host / php-host)
- Installers run smoke checks (`pytest` + Stage 0 verify) after install
- Entity review: clearer **Edit before approve** / Save & approve flow
- GitHub CI (pytest + PHP lint + zip check)
- Issue / PR templates, Dependabot, CODEOWNERS
- `ROADMAP.md`, `docs/ARCHITECTURE.md`, screenshot folder

## [0.1.0] — 2026-07-23

### Added

- Python edition (FastAPI): site scan, heuristic + multi-provider LLM extraction, entity verification, manual entry, export ZIP, live publish to web root
- PHP edition for non-Python shared hosting (`php/`, web `install.php`)
- Install paths: local PC, Python host, PHP host (`installers/`)
- Pre-built PHP package: `installers/php-host/bkbs-php-edition.zip`
- LLM settings UI (OpenAI-compatible providers)
- Site delete, publish path validation and host path detection (PHP)
- Documentation: `INSTALL.md`, `USER_MANUAL.md`, `deploy/SHARED_HOSTING.md`
- Branding: **Manifest BKBS Converter**

### Notes

- First public open-source release
