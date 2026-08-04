# Claim Ledger Architecture — Staged Implementation Plan

**Purpose:** Convert entity store from mutable rows to append-only claim ledger, with explicit stage gates for safe incremental deployment.

**Generated:** 2026-07-28  
**Status:** Stage 0 complete (2026-07-29) — Stages 1+ not started

---

## Stage Gate Protocol

**Each stage must pass ALL exit criteria before proceeding.**

| Gate | What It Means |
|------|---------------|
| **ENTRY** | Prerequisites that must be true before starting the stage |
| **TASKS** | Work to do (both Python + PHP editions in parallel) |
| **EXIT** | Verifiable conditions that prove the stage works |
| **ROLLBACK** | How to revert if exit criteria fail |

---

## STAGE 0: Baseline & Test Fixtures

### ENTRY
- Current codebase running on dev machine
- At least one test site with approved entities in DB

### TASKS
1. **Capture golden exports** — Run current export for test site, save ZIP as `test-fixtures/golden-v0.zip`
2. **Document current entity counts** — `SELECT site_id, status, COUNT(*) FROM entities GROUP BY site_id, status`
3. **Create test script** — `scripts/verify_exports.py` that:
   - Runs export via current code
   - Compares against golden file (byte-for-byte or semantic diff)
   - Exits 0 on match, 1 on mismatch

### EXIT
- [x] Golden export captured and committed
  - Python: `test-fixtures/golden-v0/` + `golden-v0.zip`
  - PHP: `test-fixtures/golden-v0-php/`
  - Shared fixture: `test-fixtures/stage0_site.json`
- [x] Verification script passes against current codebase
  - `python scripts/verify_exports.py --edition all`
  - `php php/scripts/verify_exports.php`
- [x] Test site entity counts recorded → `test-fixtures/entity-counts.json` (4 approved, 1 pending)

### ROLLBACK
- N/A (read-only)

### Implementation notes (Stage 0)
- Covers **all three install paths**: local (Python), python-host (Python), php-host (PHP) via two code editions + shared fixture.
- Capture: `python scripts/capture_golden.py --edition all`
- Verify: `python scripts/verify_exports.py --edition all`

---

## STAGE 1: Add Claims Table (Additive, No Data Migration)

**Status:** Implemented 2026-08-04 (Python + PHP Host + WordPress)  
**Scope amendment:** All three monorepo editions. Contract gate required.

### ENTRY
- Stage 0 complete
- DB backup taken (recommended before deploy)

### TASKS (All Editions)

**Python:**
1. `claims` via SQLAlchemy `Claim` model + `init_db()` / `create_all`
2. `app/services/resolver.py::resolve_entity()` stub returns `None`
3. Indexes created in `_migrate_sqlite()`

**PHP Host:**
1. `Database.php::migrate()` CREATE TABLE claims + indexes
2. `Resolver.php::resolveEntity()` stub returns `null`
3. Autoload entry in `bootstrap.php`

**WordPress:**
1. `{prefix}mbkbs_claims` via `dbDelta`; `MBKBS_DB_VERSION` = `2`
2. `MBKBS_Database::maybe_upgrade()` on `plugins_loaded`
3. `MBKBS_Resolver::resolve_entity()` stub returns `null`

**Contract gate:**
- `test-fixtures/stage1_claims_contract.json`
- `scripts/stage1_contract_check.py` (CI + local)
- Triple-edition PASS required; no partial ship

**Shared logical DDL (SQLite shape):**
```sql
CREATE TABLE claims (
    id              INTEGER PRIMARY KEY,
    entity_id       TEXT NOT NULL,        -- use current Entity.id (UUID) for URL stability
    entity_type     TEXT NOT NULL,
    attribute       TEXT NOT NULL,
    value           TEXT NOT NULL,
    source_url      TEXT,
    extraction_method TEXT NOT NULL,
    confidence      REAL,
    status          TEXT NOT NULL,
    supersedes_id   INTEGER REFERENCES claims(id),
    created_at      TIMESTAMP NOT NULL,
    approved_by     TEXT,
    approved_at     TIMESTAMP,
    review_due_at   TIMESTAMP
);
CREATE INDEX idx_claims_entity_attr ON claims(entity_id, attribute);
CREATE INDEX idx_claims_status ON claims(status);
CREATE INDEX idx_claims_supersedes ON claims(supersedes_id);
```

### EXIT
- [x] `claims` table exists in Python + PHP (WP: `{prefix}mbkbs_claims`)
- [x] Stub resolvers present in all three editions (return null/None)
- [x] No changes to `entities` write paths, scan, or export readers
- [x] Stage 0 verification script still passes
- [x] `python scripts/stage1_contract_check.py` PASS

### ROLLBACK
- Drop `claims` / `{prefix}mbkbs_claims`; remove stub modules; set WP `mbkbs_db_version` to 1

---

## STAGE 2: Backfill Claims from Current Entities

### ENTRY
- Stage 1 complete
- `resolve_entity()` stubs in place

### TASKS (Both Editions)

**Python:** Create `scripts/backfill_claims.py`
```python
# For each entity in entities table:
#   For each attribute (name, description, each property key, relationships, evidence):
#       INSERT INTO claims (entity_id, entity_type, attribute, value, ...)
#       VALUES (entity.id, entity.entity_type, attr_name, json_value, ...)
#       status='approved', approved_at=entity.last_updated, extraction_method=entity.source
```

**PHP:** Create `php/backfill_claims.php` — same logic, raw PDO.

**Both:** Use `entity_id = Entity.id` (UUID) — keeps `/entities/{uuid}` URLs stable.

### EXIT
- [ ] Backfill script runs without errors
- [ ] `SELECT COUNT(*) FROM claims` ≈ `SUM(attributes per entity)` from Stage 0 counts
- [ ] **Critical:** Implement real `resolve_entity()` in both editions
- [ ] **Critical:** Run verification script — exports via `resolve_entity()` must match golden file **byte-for-byte**
- [ ] No changes to `entities` table, scan pipeline, or UI

### ROLLBACK
- `DELETE FROM claims` — revert to Stage 1 state

---

## STAGE 3: Claim-Only Merge Pipeline (Write Path)

### ENTRY
- Stage 2 complete — resolver produces identical exports
- Backfill verified

### TASKS (Both Editions)

**Python — `app/services/merger.py`:**
1. Rewrite `apply_extracted()` — **remove all UPDATE logic**
2. New algorithm per extracted entity:
   ```python
   for attr in extracted_attributes:
       current = get_latest_approved_claim(entity_id, attr)
       if current and current.value != new_value:
           insert_claim(entity_id, attr, new_value, status='pending', supersedes_id=current.id)
       elif not current:
           insert_claim(entity_id, attr, new_value, status='pending')
   ```
3. Return stats: `created`, `superseded_pending`, `unchanged`
4. Update `scan_runner.py` to call new `apply_extracted()`

**PHP — `php/src/Router.php::upsertEntity()`:**
1. Remove UPDATE block (lines 238-242)
2. Same claim-insert logic using `Resolver::getLatestApprovedClaim()`
3. Update `scan()` to use new merge

### EXIT
- [ ] **No UPDATE/DELETE on `claims` table** — verify with grep: `grep -r "UPDATE.*claims\|DELETE.*claims" app/ php/`
- [ ] Run a **test scan** on test site:
  - New entities → claims with `status='pending'`
  - Changed attributes → new pending claims with `supersedes_id` set
  - Unchanged → no new claims
- [ ] Dashboard still loads, entities list shows (still reads from `entities` table — unchanged)
- [ ] Stage 0 verification script **still passes** (exports unchanged because UI still reads `entities` table)

### ROLLBACK
- Revert `merger.py` / `Router.php` to previous version
- `DELETE FROM claims WHERE status='pending'` — cleans test scan artifacts

---

## STAGE 4: Export Adapters + Resolver Cutover (Read Path)

### ENTRY
- Stage 3 complete — claim-only writes working
- Stage 0 verification still passes

### TASKS

**Python:**
1. Create `app/exports/` with adapter modules:
   ```
   app/exports/
   ├── __init__.py
   ├── base.py              # ResolvedEntity dataclass
   ├── llms_txt.py
   ├── schema_org.py
   ├── graph_json.py
   └── agent_json.py
   ```
2. Move export logic from `export_llms.py`, `export_jsonld.py`, `export_graph.py` → adapters
3. Adapters accept `list[ResolvedEntity]` (from resolver), not `list[Entity]`
4. Update `export_package.py` to:
   - Call `resolve_entity()` for each entity_id
   - Pass resolved entities to adapters
5. **Switch ONE export endpoint** to new path (e.g., `/api/sites/{id}/export?format=llms_txt`)

**PHP:**
1. Create `php/src/Exports/` with adapter functions
2. Refactor `Publisher.php` to use `Resolver::resolveEntity()` + adapters
3. Switch one export route

### EXIT
- [ ] All 4 export formats work via new adapter path (test each format endpoint)
- [ ] **Critical:** Run Stage 0 verification script — exports must match golden file **byte-for-byte**
- [ ] Old export code paths still exist but unused (can delete in Stage 6)
- [ ] UI still reads `entities` table (unchanged)

### ROLLBACK
- Revert `export_package.py` / `Publisher.php` to read `entities` table directly
- Adapters remain as dead code

---

## STAGE 5: Diff-Based Review UI

### ENTRY
- Stage 4 complete — exports via resolver, verified identical
- Claim pipeline writing pending/superseded claims correctly

### TASKS

**Python:**
1. Add diff API: `GET /api/entities/{entity_id}/diff` → returns old vs new per attribute
2. Update `entity_edit.html` — side-by-side diff for each changed attribute
3. Update verify endpoints (`/entities/{id}/verify`, `/entities/bulk-verify`):
   - `approve` → new claim `approved`, old claim `superseded`
   - `reject` → new claim `rejected`
   - `needs_edit` → new claim `needs_edit`
4. Add `review_due_at` population in extraction (certifications → expiry date, hours/pricing → 90 days)

**PHP:**
1. Equivalent diff route + template updates
2. Same claim status transitions in `entityUpdate()` / `verify()`

### EXIT
- [ ] Diff view shows correct old/new for test rescan
- [ ] Approve → old claim status=`superseded`, new claim status=`approved`
- [ ] Reject → new claim status=`rejected`, old unchanged
- [ ] Export after approve → includes new value, excludes superseded
- [ ] Stage 0 verification still passes (golden export unchanged for test site)

### ROLLBACK
- Revert UI routes to old verify logic (status flip on `entities` table)
- Claims table retains history — no data loss

---

## STAGE 6: Full Resolver Cutover + Cleanup

### ENTRY
- Stage 5 complete — diff UI working, claim transitions verified

### TASKS

**Python:**
1. Update ALL UI routes to use resolver:
   - Dashboard counts
   - Site detail status counts
   - Entities list (filter by resolved status)
   - Entity edit load
2. Drop `Entity` model, `entities` table, `EntityVersion` table via migration
3. Remove old export modules (`export_llms.py`, `export_jsonld.py`, `export_graph.py`)
4. Remove `EntityVersion` snapshots from `scan_runner.py`

**PHP:**
1. Update all routes (`home`, `siteDetail`, `entities`, `entityEdit`) to use `Resolver`
2. Drop `entities` table from `Database.php::migrate()`
3. Remove old `Publisher.php` direct table reads

### EXIT
- [ ] **No code references `entities` table or `Entity` model** (grep verification)
- [ ] Full rescan cycle test passes:
  1. Scan → pending claims
  2. Review diff → approve/reject
  3. Re-scan (modified source) → new pending claims supersede
  4. Review new diffs → approve
  5. Export → all 4 formats correct
  6. `GET /api/entities/{id}/claims` shows full history
- [ ] Stage 0 verification passes against **new** golden file (capture new golden)
- [ ] Performance: resolver queries < 100ms for typical site

### ROLLBACK
- Restore `entities` table from backup (Stage 0 backup)
- Revert UI routes to read `entities` table
- Claims table preserved for audit

---

## STAGE 7: Query API (Optional, Can Defer)

### ENTRY
- Stage 6 complete

### TASKS
- `GET /api/entities/{id}` → current resolved
- `GET /api/entities/{id}?as_of=...` → historical
- `GET /api/entities/{id}/claims` → full audit trail
- `GET /api/sites/{id}/export?format=...` → live adapter output

### EXIT
- [ ] All endpoints return correct JSON
- [ ] Historical `as_of` verified against known rescan timestamps

---

## STAGE 8: Crawler Accessibility Addendum (Independent)

*Can start any time after Stage 2 (resolver exists). Does not depend on Stages 3-7.*

### TASKS
- 8.1 Robots.txt AI-crawler audit in scan pipeline
- 8.2 JS-vs-static content detection
- 8.3 JSON-LD copy-paste snippet export (new adapter)
- 8.4 WordPress plugin scope doc
- 8.5 Prose linting guidance
- 8.6 Bot-hit log monitoring design

---

## Summary: Stage Dependencies

```
Stage 0 (Baseline)
    ↓
Stage 1 (Add claims table) → ADDITIVE, zero risk
    ↓
Stage 2 (Backfill + resolver) → VERIFY: exports match golden
    ↓
Stage 3 (Claim-only writes) → VERIFY: no UPDATE/DELETE on claims
    ↓
Stage 4 (Adapters + read cutover) → VERIFY: exports match golden
    ↓
Stage 5 (Diff UI) → VERIFY: approve/reject transitions work
    ↓
Stage 6 (Full cutover + cleanup) → VERIFY: full rescan cycle works
    ↓
Stage 7 (Query API) — optional
    ↓
Stage 8 (Crawler addendum) — independent
```

---

## Quick Reference: Verification Commands

```bash
# Stage 0: Capture golden
./run_export_test.py --capture-golden --site test-site

# Stage 2: Verify backfill resolver
./run_export_test.py --verify --site test-site

# Stage 3: Check no UPDATE/DELETE on claims
grep -r "UPDATE.*claims\|DELETE.*claims" app/ php/

# Stage 4: Verify adapter exports
./run_export_test.py --verify --site test-site

# Stage 6: Full cycle test
./run_full_cycle_test.py --site test-site

# Any stage: Smoke test
curl -s http://127.0.0.1:8765/health | jq .ok
```

---

## File References (Current Codebase)

| Component | Python | PHP |
|-----------|--------|-----|
| Entity model | `app/models.py:Entity` | `php/src/Database.php` (table def) |
| Merge logic | `app/services/merger.py:apply_extracted()` | `php/src/Router.php:upsertEntity()` |
| Scan pipeline | `app/services/scan_runner.py` | `php/src/Router.php:scan()` |
| Exports | `app/services/export_*.py` | `php/src/Publisher.php` |
| UI routes | `app/main.py` | `php/src/Router.php` |
| Templates | `app/templates/*.html` | `php/templates/*.php` |

---

## Next Action

**Stage 0 complete.** Next: **Start Stage 1** — add `claims` table (additive) + resolver stubs in Python and PHP.