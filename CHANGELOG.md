# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] — 2026-07-23

### Added

- Python edition (FastAPI): site scan, heuristic + multi-provider LLM extraction, entity verification, manual entry, export ZIP, live publish to web root
- PHP edition for non-Python shared hosting (`php/`, web `install.php`)
- Install paths: local PC, Python host, PHP host (`installers/`)
- Pre-built PHP package: `installers/php-host/bkbs-php-edition.zip`
- LLM settings UI (OpenAI-compatible providers)
- Site delete, publish path validation and host path detection (PHP)
- Documentation: `INSTALL.md`, `USER_MANUAL.md`, `deploy/SHARED_HOSTING.md`

### Notes

- First public open-source release
