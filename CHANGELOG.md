# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- Document live-publish artifact `bkbs/README.txt` in `INSTALL.md` §8 and `deploy/SHARED_HOSTING.md` (written by Python and PHP publishers; was missing from the published-files tables)
- WordPress plugin: emit `@type: LocalBusiness` in `schema/organization.jsonld` to match Python and PHP public contract (was `Organization`)
- Publish live test asserts `bkbs/README.txt` and organization JSON-LD `@type`

### Added

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
