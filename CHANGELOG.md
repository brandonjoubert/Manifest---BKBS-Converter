# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Claim Ledger Stage 7** (authenticated query API, all three editions)
  - `/api/*` requires `Authorization: Bearer` or `X-API-Key` (Python). PHP JSON `api/entities/{id}` and `/claims` use the same headers. WordPress REST `/wp-json/mbkbs/v1/entities/{id}` accepts an admin session or the bearer token.
  - `GET .../entities/{id}?as_of=` resolves the approved snapshot using `approved_at` (set on every approve; legacy rows backfilled from `created_at`)
  - `GET .../entities/{id}/claims` returns the append-only ledger
  - Origin `/llms.txt` and other published files stay anonymous HTTP
  - Operator HTML UI stays cookie/session (or local-open); ZIP download moved to `/exports/{id}/download`
  - Tests: `tests/test_stage7.py`, `php/scripts/verify_stage7.php`
- **Claim Ledger Stage 6** (drop attribute columns as source of truth; keep entity envelope)
  - Resolver reads name/description/properties/relationships/evidence from claims only
  - New scans and manual creates write claims; envelope columns are cleared
  - Operator/draft resolve overlays pending claims; public files stay last approved
  - Delete site/entity removes claims by entity_id
  - Restore drill: `restore_attribute_columns_from_claims` copies claims back onto columns
  - Tests: `tests/test_stage6.py`, `php/scripts/verify_stage6.php`
- **Claim Ledger Stage 4c** (additive public-web artifacts, all three editions)
  - Script-safe JSON-LD HTML snippet (`<` → `\u003c`) in ZIP as `schema/jsonld-snippet.html` and Copy on the site machine-layer card
  - WordPress: optional “Print JSON-LD in `wp_head` on homepage” — **default off**
  - Honest `/.well-known/agent.json` knowledge index (no stub `protocol`, no A2A `endpoint`); excluded from Stage 0 byte-compare; schema-asserted
  - AIPREF / Content-Usage: three site toggles, all **off**; preview of the robots merge block; never write without `# END BKBS`
  - Machine-layer card: live URL checklist, snippet, inject status, AIPREF preview
  - Tests: `tests/test_artifacts_stage4c.py`, `php/scripts/verify_stage4c.php`, `scripts/stage4c_contract_check.py`
- **Claim Ledger Stage 5** (claim-level review UI, all three editions)
  - `GET /diff` (Python `/api/entities/{id}/diff` and `/entities/{id}/diff`; PHP `entities/{id}/diff`) — old = last approved claim, new = latest pending; never entity columns
  - Inbox defaults to pending + needs_edit with a one-line change summary and **Review changes**
  - Review-first page: new-fact vs changed-fact layouts; needs_edit copy: “Live facts are still published.”
  - Save rule: typed value ≠ extract → manual claim; Save without approve stays pending; Approve with no edit promotes extract; Reject keeps last approved live
  - Bulk approve is new entities only (or a diffs confirmation list); cannot launder `needs_edit`
  - Republish knowledge files only when auto_publish + root; no robots re-merge; toast live URL vs “saved, not published”
  - Tests: `tests/test_review_stage5.py`
- **Claim Ledger Stage 4b** (export adapters, golden-stable, all three editions)
  - Python: `app/exports/` (`llms_txt`, `schema_org`, `graph_json`, `agent_json`, `robots`); production export/publish import adapters; old `export_*.py` modules are shims
  - PHP Host: `php/src/Exports/*`; `Publisher` writes via adapters
  - WordPress: `includes/exports/class-mbkbs-export-*.php`; publisher `build_payload` uses adapters
  - One `ResolvedEntity` type (`app/services/resolved_entity.py`, re-exported from `app.exports.base`)
  - `agent.json` stub bytes unchanged (`protocol: agent-web-protocol-stub`)
  - Contract: `test-fixtures/stage4b_adapters_contract.json`, `scripts/stage4b_contract_check.py`
- **Claim Ledger Stage 4a** (claim writers + `resolve_site` public-set, all three editions)
  - Approve / reject / Save & approve write claims (promote pending or insert manual approved). Envelope `status` still exists; `attribute=status` is no longer backfilled as a fact.
  - `resolve_site` / `resolveSite` / `MBKBS_Resolver::resolve_site` batch-loads approved claims. Public set: last approved snapshot while envelope is `approved`, `needs_edit`, or `stale`. Never-approved `pending` and `rejected` stay out of live files.
  - Python export/publish, PHP `Publisher`, and WordPress rewrite/static files use that public set. Draft `include_pending` ZIP does not write the origin (T7).
  - Tests: `tests/test_export_stage4.py` (T1–T4, T7) and `php/scripts/verify_stage4a.php`
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
