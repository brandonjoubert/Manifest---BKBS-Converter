<!-- /autoplan restore point: /home/brandon/.gstack/projects/brandonjoubert-Manifest---BKBS-Converter/main-autoplan-restore-20260818-113348.md -->
# Claim Ledger Architecture — Staged Implementation Plan

**Purpose:** Convert entity store from mutable rows to append-only claim ledger, with explicit stage gates for safe incremental deployment.

**Generated:** 2026-07-28  
**Updated:** 2026-08-18  
**Status:** Stage 0–4a complete. **Stage 4b** adapters implemented (goldens unchanged). Next: Stage 5 review UI, then 4c artifacts. /autoplan B2 applied 2026-08-18.

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

**Status:** Implemented 2026-08-11 (Python + PHP Host + WordPress)  
**Scope:** Dual-path only — production export still reads entities; no Stage 3/4.

### ENTRY
- Stage 1 complete
- `resolve_entity()` stubs in place

### TASKS (All Editions)

**Python:**
1. `app/services/claim_codec.py` — encode/decode + `entity_attribute_pairs` (`prop:` keys)
2. `app/services/resolved_entity.py` — `ResolvedEntity` dataclass for export builders
3. Real `app/services/resolver.py::resolve_entity(..., db=)` hybrid (claims override + entity row)
4. `scripts/backfill_claims.py` — approved-only default, idempotent, `--update` supersede
5. `scripts/verify_exports_via_resolve.py` — export-via-resolve vs Stage 0 golden

**PHP Host:**
1. Real `Bkbs\Resolver::resolveEntity($id, $asOf, $pdo)`
2. `php/scripts/backfill_claims.php`
3. `php/scripts/verify_exports_via_resolve.php`

**WordPress:**
1. `MBKBS_Backfill::run()` + admin Tools page + `wp mbkbs backfill-claims`
2. Real `MBKBS_Resolver::resolve_entity()`

**Attribute encoding:** `name`, `description`, `prop:<key>`, `relationships`, `evidence`, plus `trust_level` / `source` / `status` when set. Identity columns (`external_key`, `version`, `last_updated`) stay on entity row.

### EXIT
- [x] Backfill script runs without errors (all three editions)
- [x] Claim counts match attribute cardinality after fixture backfill
- [x] Real `resolve_entity` / `resolveEntity` / `MBKBS_Resolver::resolve_entity` in all three
- [x] Python + PHP: export-via-resolve matches Stage 0 golden (normalized)
- [x] Stage 0 entity-path `verify_exports` still PASS
- [x] Production export/publish still reads entities (no Stage 4 cutover)
- [x] CI Stage 2 gate wired

### ROLLBACK
- `DELETE FROM claims` — revert to Stage 1 empty ledger; resolvers still safe (entity fallback)

---

## STAGE 3: Claim-Only Merge Pipeline (Write Path)

**Status:** Implemented 2026-08-17 (Python + PHP Host + WordPress)  
**Design:** `brandon-main-design-stage3-20260817-090813.md` (APPROVED, Approach A)

### ENTRY
- Stage 2 complete — resolver produces identical exports
- Backfill verified

### TASKS (All Editions)

**Python — `app/services/merger.py` + `claim_writer.py`:**
1. Rewrite `apply_extracted()` — no scan UPDATE of entity attribute columns
2. New entity → shell `pending` + pending claims for all atoms
3. Existing → compare extract vs approved claim (else entity columns) → pending claims; approved + pending → `needs_edit`
4. Supersede prior pending same attribute (status only)

**PHP / WordPress:** same in `Router::upsertEntity` / `MBKBS_Admin::upsert_entity` via `Resolver` / `MBKBS_Backfill` helpers

### EXIT
- [x] Claim **values** not mutated; status transitions to `superseded` only
- [x] Tests: new / identical / change+needs_edit / no entity attr overwrite (`tests/test_merge_stage3.py`)
- [x] Dashboard still reads entities; production export unchanged
- [x] Stage 0 verification **still passes**
- [x] Stage 2 via-resolve still PASS

### ROLLBACK
- Revert merger/upsert modules
- `DELETE FROM claims WHERE status='pending'` — cleans test scan artifacts
---

## STAGE 4: Split (4a writers + public-set, 4b adapters, 4c artifacts)

**/autoplan 2026-08-18 (founder B2):** do **not** cut live publish over to resolve until claim writers exist. Keep the **entity envelope**. MCP stays Stage 9.

### 4a — Claim writers + publication rule (ENTRY for 4b cutover)

**Must ship before any production adapter reads resolve.**

1. Approve / reject / Save & approve write **claims** (approve pending or insert manual approved; supersede prior pending). Stop flipping only `entities.status`.
2. Manual create/edit inserts approved (or pending) claims, same codec as backfill.
3. Stop backfilling `attribute='status'` as a publishable fact. Review state stays on the envelope.
4. Add `resolve_site(site_id)` (batch claims query). Public set:
   - Include last **approved** snapshot while envelope is `approved` or `needs_edit`.
   - Exclude envelope `pending` (never approved) and `rejected`.
   - `stale`: keep last approved until operator confirms removal (test T5).
5. Tests T1–T4, T7 required. Current UI-approve-then-resolve (T3) must go green.

### 4b — Adapters (byte-stable)

1. Move builders into `app/exports/` + PHP/WP twins. One `ResolvedEntity` type (`app/services/resolved_entity.py`).
2. One production call path. Point `verify_exports.py` at adapters.
3. Do **not** change `agent.json` bytes in this slice. Goldens stay green.
4. Triple-edition contract fixture for adapter I/O.

### 4c — Additive public-web artifacts (may parallel 4b; must not change 4b bytes)

1. JSON-LD HTML snippet: script-safe JSON (`<` → `\u003c`). ZIP + site-card Copy.
2. **WordPress:** optional checkbox “Print JSON-LD in `wp_head` on homepage” — **default off**.
3. Honest knowledge `agent.json` in a **new** golden extra / schema assert (no stub `protocol`, no A2A `endpoint`). Exclude from old byte-compare.
4. AIPREF / content-signals: three site toggles, all **off**; preview merge block; never write without `# END BKBS`.
5. Machine-layer card on site page (live URL checklist + inject status). INSTALL is backup.

### EXIT (Stage 4)
- [x] 4a tests green (no pending-only entity in live files; needs_edit keeps last approved) — `tests/test_export_stage4.py`, `php/scripts/verify_stage4a.php`
- [x] 4b goldens still match for current formats — `app/exports/` + PHP/WP twins; `scripts/stage4b_contract_check.py`
- [ ] 4c snippet escaped; WP inject off by default; AIPREF off by default
- [ ] Envelope table still exists
- [ ] Triple edition

### ROLLBACK
- Revert writers; publish still filters `entities.status == approved`
- Revert adapters to old modules
- Remove snippet / inject / AIPREF lines

---

## STAGE 5: Claim-level review UI (before or with 4c; after 4a)

### ENTRY
- 4a complete (approve writes claims)
- Production may still publish via envelope filter + approved claims

### TASKS

**Review unit is the claim.** `GET /diff`: `old` = latest approved claim (else “not published”); `new` = latest pending. Never use entity attribute columns as the review baseline.

**Inbox (`entities.html`):** default `pending` + `needs_edit`; one-line change summary; CTA Review changes. Bulk approve = new entities only, or a modal listing every attribute diff.

**Review page:** review-first (changes, then decision, then unchanged/JSON collapsed). Two layouts: **new fact** (no empty old column) vs **changed fact**. Copy for `needs_edit`: “Live facts are still published.”

**Save rule:** typed value ≠ extract → new `manual` claim; Save without approve stays pending; Approve with no edit approves extract; Reject keeps last approved live.

**WP:** same attribute diffs even if raw JSON textareas are omitted.

**Republish:** knowledge files only if `auto_publish` + root set; never re-merge robots as a side effect of one claim. Toast: live URL vs “saved, not published.”

### EXIT
- [ ] Diff old/new matches 4a writers
- [ ] Approve/reject update claims; live files follow publication rule
- [ ] Bulk cannot launder `needs_edit` diffs
- [ ] Triple edition

### ROLLBACK
- Revert UI; keep 4a writers

---

## STAGE 6: Drop attribute columns — not the envelope

### ENTRY
- Stage 5 complete
- Dual-read soak (export via resolve == last approved snapshot) on a real-sized DB
- Restore drill on a **copy** of that DB (not Stage 0 fixture)

### TASKS
1. Keep `entities` (or rename `entity_envelopes`): `id`, `site_id`, `external_key`, `entity_type`, `review_status`, `version`, `last_updated`.
2. Null or drop **attribute columns** only (`name`, `description`, `properties`, `relationships`, `evidence`).
3. UI counts use envelope `review_status` + pending-claim existence, not resolved `status` claim.
4. Remove old unused export modules and `EntityVersion` if unused.
5. `resolve_entities_for_site` stays batched. WP public URLs stay **static/cached**, not N+1 live resolve.
6. **Do not** grep-ban `Entity` / `entities`. **Do not** “restore Stage 0 backup” as rollback.

### EXIT
- [ ] Public files still populate after columns dropped
- [ ] Manual entities still resolve (claims written in 4a)
- [ ] Delete site cascades or deletes claims by `entity_id` list
- [ ] Soak + restore drill documented

### ROLLBACK
- Forward migration restoring columns from latest approved claims — not a fixture restore

---

## STAGE 7: Query API (Optional)

### ENTRY
- Auth for `/api/*` (Stage 7 **entry**, not a doc note)
- Stage 5 complete

### TASKS
- Authenticated: `GET /api/entities/{id}`, `?as_of=`, `/claims`
- Live adapter output stays operator-only
- Origin `/llms.txt` etc. remain anonymous HTTP

### EXIT
- [ ] Unauthenticated `/api/entities` is 401
- [ ] `as_of` uses `approved_at` (required on approve)
- [ ] No MCP required for origin files

---

## STAGE 8: Crawler audit (Independent)

*After Stage 2. If 4c shipped, 8.1/8.3 are audit only.*

### TASKS
- Findings object: `id`, `severity`, `title`, `evidence`, `cta` (`pass|fail|unknown`)
- Scan page: findings above raw stats; completed-with-warnings if high severity
- 8.6 fetch counter is a **metric**, not a design essay
- 8.7 TDMRep document-only
- 8.4 documents the WP `wp_head` checkbox from 4c

### EXIT
- [ ] Findings render in product, not only INSTALL
- [ ] No second AIPREF implementation

---

## STAGE 9: Capability Layer (Optional — MCP / A2A / NLWeb)

**Separate product surface.** Do not fold this into Claim Ledger Stages 4–7.  
Knowledge files stay the default; this stage is only if the **site itself becomes callable**.

### ENTRY
- Stages 4a–4c complete at minimum (writers, adapters, honest knowledge `agent.json`)
- Explicit product decision: “we are exposing an agent or tools,” not “we need a trendier well-known file”

### TASKS
1. **Keep** `/.well-known/agent.json` as the BKBS **knowledge index** (llms / graph / schema pointers).
2. If (and only if) an agent endpoint exists, add **`/.well-known/agent-card.json`** that matches current A2A Agent Card fields (name, description, version, `url`/`endpoint`, skills, auth). Do not overwrite the knowledge index.
3. MCP (and/or NLWeb `/ask`) as an **optional** host-side server that reads the **resolver** (approved claims only). Tools wrap query/export — they do not bypass review.
4. Never require MCP to consume `/llms.txt` or JSON-LD; anonymous HTTP remains the interoperability baseline.

### EXIT
- [ ] Knowledge files unchanged for operators who skip Stage 9
- [ ] Agent Card (if shipped) validates against A2A card schema; no stub protocol
- [ ] MCP/NLWeb (if shipped) returns only approved/resolved facts
- [ ] INSTALL documents three layers: knowledge files → optional HTML inject → optional capability endpoint

### ROLLBACK
- Delete Agent Card / MCP routes; leave knowledge publish intact

---

## Public-web leverage → stage map

Gaps identified against the 2026 machine-readable stack (schema.org + robots + `llms.txt` + fragmented agent discovery). **Do these on the ledger path; do not start a parallel “standards” product.**

| Gap | Where it lives | Rule |
|-----|----------------|------|
| Embed JSON-LD in HTML | **4c** snippet + WP `wp_head` off-by-default; **8** audit | Files stay; Google-class needs on-page |
| Honest `agent.json` vs A2A | **4c** knowledge index (new golden); **9** = `agent-card.json` only with a real endpoint | Never fake A2A |
| AIPREF / content-signals | **4c** toggles default off; **8** detect/report | Operator opt-in |
| MCP / NLWeb / A2A runtime | **Stage 9 only** | Not the knowledge layer |

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
Stage 4a (claim writers + resolve_site public-set)
    ↓
Stage 4b (adapters, golden-stable)  +  Stage 5 (claim-level review UI)
    ↓
Stage 4c (snippet, WP inject, honest agent.json, AIPREF toggles)
    ↓
Stage 6 (drop attribute columns; KEEP entity envelope)
    ↓
Stage 7 (Query API) — optional knowledge reads
    ↘
Stage 8 (Crawler addendum) — independent after Stage 2
    ↘
Stage 9 (Capability: MCP / A2A card / NLWeb) — optional, never blocks 4–7
```

---

## Quick Reference: Verification Commands

```bash
# Stage 0: Capture / verify entity-path goldens
python scripts/capture_golden.py --edition all
python scripts/verify_exports.py --edition all
php php/scripts/verify_exports.php

# Stage 1: Contract
python scripts/stage1_contract_check.py

# Stage 2: Via-resolve goldens
python scripts/verify_exports_via_resolve.py
php php/scripts/verify_exports_via_resolve.php

# Stage 3: Claim values not mutated on scan
pytest tests/test_merge_stage3.py -q

# Stage 4a: publication rule (add tests/test_export_stage4.py)
pytest tests/test_export_stage4.py -q   # T1–T4, T7 — pending must not go live

# Stage 4b: adapters still match goldens
python scripts/verify_exports.py --edition all

# Any stage: smoke
curl -s http://127.0.0.1:8765/health
```

---

## File References (Current Codebase)

| Component | Python | PHP | WordPress |
|-----------|--------|-----|-----------|
| Entity / envelope | `app/models.py:Entity` | `php/src/Database.php` | `class-mbkbs-database.php` |
| Merge | `merger.py` + `claim_writer.py` | `Router.php:upsertEntity` | `MBKBS_Admin::upsert_entity` |
| Resolve | `app/services/resolver.py` | `php/src/Resolver.php` | `MBKBS_Resolver` |
| Publish | `publish_live.py` / `app/exports/` | `php/src/Publisher.php` + `Exports/` | `MBKBS_Publisher` + `exports/` |
| Verify / UI | `app/api/entities.py`, templates | `Router.php` + templates | `class-mbkbs-admin.php` + views |

---

## Next Action

**Stages 0–4b complete** (writers, public-set, adapters).  

Next: **Stage 5** claim-level review UI. Then 4c artifacts. Stage 6 is **columns only**. Stage 9 still gated.

---

<!-- AUTONOMOUS DECISION LOG -->
## Decision Audit Trail

| # | Phase | Decision | Classification | Principle | Rationale | Rejected |
|---|-------|----------|----------------|-----------|-----------|----------|
| 1 | CEO | Mode = SELECTIVE EXPANSION | Mechanical | autoplan override | Remaining work is iteration on shipped 0–3 | EXPANSION / REDUCTION |
| 2 | CEO | Premises accepted (user A) | User | Premise gate | Founder confirmed 4–9 review; Stage 6 not accepted as inevitable today | B/C/D |
| 3 | CEO | Do not rewrite remaining stages until final gate | Mechanical | P6 + User Challenge rule | CEO voice wants reorder/split; user said review 4–9 as written | Silent plan rewrite |
| 4 | CEO | Codex CEO voice skipped | Mechanical | unavailable | `codex` binary not on PATH | Dual-model CEO |
| 5 | Design | Run design phase | Mechanical | UI scope | Stage 5 diff UI + dashboard/forms | Skip design |
| 6 | DX | Run DX phase | Mechanical | DX scope | Installers, APIs, MCP, onboarding | Skip DX |
| 7 | Eng | Flag approved-only public resolve as P1 contract | Mechanical | P1 + P5 | Hybrid resolve overlaying *pending* would leak unreviewed facts if used raw | Hybrid-for-public |
| 8 | Eng | Stage 4 “one endpoint” vs “all 4 formats” | Taste | P5 | Canary one format first, then cut all | Silent both |
| 9 | CEO | Stage 6 drop-entities | User Challenge | both-models | CEO voice: never drop without identity home + soak | Treat Stage 6 as next obligatory step |
| 10 | Design | JSON-LD snippet vs WP `wp_head` inject | Taste / UC | P1 | Snippet conversion historically near-zero; WP can inject | Paste-only |
| 11 | Eng | Split Stage 4 into 4a/4b/4c | User Challenge | CEO+Eng | Four projects in one stage | Keep Stage 4 as one ship |

---

## /autoplan Phase 1 — CEO (SELECTIVE EXPANSION)

**Working with:** whole Claim Ledger (0–3 shipped on `b380c70`; 4–9 remaining including public-web leverage). UI scope: **yes** (Stage 5). DX scope: **yes** (installers, APIs, MCP). Codex: **unavailable** (`[subagent-only]`). Design doc: Stage 3 APPROVED `brandon-main-design-stage3-20260817-090813.md`. User premise: **A**.

### 0A Premises (confirmed)

1. Durable approved facts for agents is the problem; the ledger is the mechanism.
2. Stages 0–3 stay locked.
3. Remaining review covers 4–9 as written, plus leverage gaps already folded in.
4. Knowledge layer first; Stage 9 MCP/A2A optional.
5. Stage 6 drop-entities is a later one-way door, not a premise you accepted today.

CEO independent voice challenged #1 (says 0–3 already bought the option) and #3 (says 4–6 is a purity program). That is a **User Challenge** at the final gate, not an auto-rewrite.

### 0B What already exists

| Sub-problem | Existing code |
|-------------|---------------|
| Resolve approved claims + entity fallback | `app/services/resolver.py` (`_latest_approved_claims` only overlays **approved**) |
| Export pack | `export_llms.py`, `export_jsonld.py`, `export_graph.py`, `export_package.py` |
| Live publish | `publish_live.py`, `php/src/Publisher.php`, `MBKBS_Publisher` |
| Claim encode | `claim_codec.py`, PHP/WP backfill helpers |
| Scan write path | `merger.py` + `claim_writer.py` (Stage 3) |
| Goldens | `scripts/verify_exports.py`, via-resolve twins |
| WP rewrite artifacts | plugin publisher + rewrites |
| EntityVersion | already exists; not a full per-attribute ledger |

`resolver.py` already filters `Claim.status == "approved"`. Pending does **not** overlay today. Stage 4 is safer than the CEO voice feared *if* adapters keep calling this function. Risk is a future “hybrid including pending” or resolving `needs_edit` entity *columns* that still hold last approved values (Stage 3 freeze) — public files would then show frozen columns, which is correct.

### 0C Dream state

```
CURRENT                         THIS PLAN (4–9)                    12-MONTH IDEAL
Dual-path: scan→pending         Resolve-backed publish             Operators review a diff,
claims; publish still           + snippet/agent.json/AIPREF        approve, live files update;
entity rows; no diff UI         + later drop entities              on-page JSON-LD + /llms.txt
No bot-hit proof                Stage 8 audit + optional MCP       fetched; ledger is boring
                                                                   infra, not the product
```

### 0C-bis Approaches (CEO)

**A — Plan as written (4 then 5 then optional 6)** Effort L, risk Med. Completeness 7/10. Reuses adapters rewrite. Cons: Stage 5 gated on 4; Stage 4 is four projects.

**B — Projection-on-approve + Stage 5 first (recommended by CEO voice)** Effort M, risk Low. Completeness 8/10 on user value. Cons: adapters still messy.

**C — Split 4a approved-only contract / 4b adapters / 4c artifacts; Stage 5 parallel; Stage 6 RFC** Effort M, risk Low. Completeness 10/10. **Primary recommendation (P1+P5).** User Challenge if we change the written sequence.

### CLAUDE SUBAGENT (CEO — strategic independence)

See session CEO voice: critical findings on (1) ledger-as-strategy vs control-plane, (2) Stage 4 bundling, (3) Stage 6 identity + rollback theater, (4) snippet conversion, (5) no consumption metric. Codex: N/A.

### CEO DUAL VOICES — CONSENSUS TABLE

```
  Dimension                           Claude  Codex  Consensus
  1. Premises valid?                   MIXED   N/A    [subagent-only]
  2. Right problem to solve?           MIXED   N/A    Ledger is mechanism not strategy
  3. Scope calibration correct?        NO      N/A    Stage 4 too fat; 6 premature
  4. Alternatives sufficiently explored? NO    N/A    Dual-path stop not analyzed
  5. Competitive/market risks covered? NO      N/A    SEO plugins / hosts
  6. 6-month trajectory sound?         NO      N/A    Risk: green CI, zero fetches
```

### NOT in scope (CEO, auto-deferred)

- Manifest Cloud, multi-tenant, vertical packs
- Replacing CMS
- Guaranteeing Google ranking from llms.txt
- Building MCP/A2A in Stage 4 (already Stage 9)
- Auto-publishing TDMRep opt-out

### Error & Rescue Registry (CEO / remaining stages)

| METHOD | WHAT CAN GO WRONG | CLASS | RESCUED? | USER SEES |
|--------|-------------------|-------|----------|-----------|
| Stage 4 resolve→publish | Adapter uses entity columns for `needs_edit` | logic | plan unspecified | Stale or wrong live files |
| Stage 4 resolve→publish | Someone passes pending claims into adapters | logic | **must test** | Unreviewed facts live |
| JSON-LD inject | Operator never pastes | adoption | none | Google never sees schema |
| robots AIPREF | Wrong default trains/forbids | policy | opt-in only | Legal/product surprise |
| Stage 6 DROP entities | Identity gone; backup is Stage 0 fixture | data | rollback theater | Unrecoverable WP/PHP site |
| agent.json rewrite | Clients expect stub keys | compat | unspecified | Silent discovery miss |
| Triple-edition zip | PHP/WP zip stale vs source | pack | known pitfall | Cloners get old merge |

### Failure Modes Registry

| CODEPATH | FAILURE | RESCUED? | TEST? | USER SEES | LOGGED? |
|----------|---------|----------|-------|-----------|---------|
| Public export pending leak | Unreviewed live | N | N in plan | Wrong facts | N |
| Stage 6 drop | Cannot restore | N | N | Broken app | N |
| Snippet unused | No on-page JSON-LD | N | N | Silent | N |
| Golden fetish | Cannot improve export | Y (block) | Y | Frozen quality | Y |

**CRITICAL GAPS:** Stage 6 rollback; no consumption metric; snippet-not-inject.

### Phase 1 complete.

CEO: 1 independent voice, 5 critical/high strategy findings. Codex unavailable. Passing to Phase 2 (Design).

---

## /autoplan Phase 2 — Design (Stage 5 + site card)

**UI scope:** Stage 5 diff UI, entity list, site detail, scan report. Initial completeness **3/10** (plan says “update entity_edit.html”). After specifying review-first layout in findings: still **4/10** until implementers get a screen spec.

### Design litmus (subagent-only)

| Dimension | Score | Note |
|-----------|-------|------|
| Hierarchy | 2/10 | Diff buried under JSON form; INSTALL owns inject |
| States | 2/10 | No partial claim review, no empty/new-fact, no publish fail |
| Journey | 3/10 | needs_edit reads as “unpublished”; approve climax silent |
| Specificity | 2/10 | “Side-by-side” is a pattern name |
| Accessibility | 3/10 | Color-only risk; no mobile spec |
| WP parity | 2/10 | WP edit has no prop/rel/evidence fields |
| Trust | 2/10 | Bulk approve can launder diffs |

### CLAUDE SUBAGENT (design)

Critical: F1 review-first hierarchy; F6/F22 claim vs entity status; F12 needs_edit copy; F13 Save write rule; F23 `/diff` old=last approved claim; F24 bulk launder; F4 machine-layer card not INSTALL.

### Auto-decided (Design)

- Do not rewrite Stage 5 tasks until final gate (User Challenge: claim-level review).
- Recommend: `/diff` contract + review-first layout + WP `wp_head` checkbox as cherry-picks.

### Phase 2 complete.

Design: 28 findings, 7 critical. Codex N/A. Passing to Phase 3.

---

## /autoplan Phase 3 — Eng

### Architecture (ASCII)

```
                    scan (Stage 3)
                         |
                         v
              +--------------------+
              | entities ENVELOPE  |  id, site_id, external_key,
              | + attr columns     |  status, version
              +---------+----------+
                        |
         +--------------+--------------+
         |                             |
         v                             v
   claims (pending)              claims (approved)
         |                             |
         |                    resolve_entity()
         |                    approved overlay
         |                    + COLUMN FALLBACK
         |                             |
         |                             v
         |                    adapters / Publisher
         |                             |
         +---- if Stage 4 iterates ----+
              ALL entity ids
              pending SHELLS leak
              via column fallback
```

**Stage 6 drop `entities`:** `resolve_entity` returns None without the row. No `site_id` on claims. Public files empty. **CRITICAL.**

### What I examined in code

- `resolver.py` `_latest_approved_claims` filters `status==approved` only.
- New scan entities: Stage 3 seeds pending claims **and** writes extract into columns → fallback **is** the leak.
- Verify endpoints flip `entities.status` only; claims stay pending.
- `export_package.py` / publish filter `status==approved`.
- No FK from claims to entities; delete-site can orphan claims.

### ENG DUAL VOICES

Codex: N/A. Claude subagent: 22 findings, 5 critical (no public-set function; cutover before claim writers; Stage 6 identity; XSS in snippet; dual status).

| Dimension | Claude | Codex | Consensus |
|-----------|--------|-------|-----------|
| Architecture sound? | NO | N/A | Envelope required |
| Tests sufficient? | NO | N/A | Goldens not enough |
| Performance | NO | N/A | N+1 on WP rewrite |
| Security | NO | N/A | snippet XSS; unauth /api |
| Error paths | PARTIAL | N/A | needs_edit unpublish |
| Deploy risk | NO | N/A | Stage 6 rollback theater |

### Test diagram (new paths)

See `~/.gstack/projects/brandonjoubert-Manifest---BKBS-Converter/brandon-main-test-plan-20260818-autoplan.md` (T1–T24).

### Phase 3 complete.

Eng: critical_gaps=4. Passing to Phase 3.5 DX.

---

## /autoplan Phase 3.5 — DX (POLISH, auto-decided)

**Product type:** self-host converter (installers + admin UI + origin files).  
**Persona (inferred, auto):** operator who already cloned for Stages 0–3; secondary: WP admin.  
**Mode:** DX POLISH (autoplan).

### Journey

| Stage | Today | Plan 4–9 | Friction |
|-------|-------|----------|----------|
| Discover | README / LinkedIn | same | OK |
| Install | install.sh / zip / WP | unchanged | TTHW ~10–15 min |
| Hello world | scan LAMP → publish | same | works (E2E proven) |
| Real usage | approve form | Stage 5 unspecified | lying inbox |
| Debug | INSTALL wall | Stage 8 stats_json | findings not product |
| Upgrade | pull + zips | Stage 4 agent.json break | golden vs honesty |

**TTHW current ~15 min** (venv + smoke + scan). Target **5 min** if we do not add Stage 4 as a required mental model. Competitive: Yoast schema checkbox ~2 min; llms.txt gist ~5 min.

### DX scorecard

| Dimension | Score |
|-----------|-------|
| Getting Started | 6/10 |
| API/CLI | 4/10 (verify command names in plan footer are stale) |
| Errors | 4/10 (publish fail after approve unspecified) |
| Docs | 6/10 (INSTALL strong; Stage 4 dumps inject there) |
| Upgrade | 3/10 (agent.json + Stage 6) |
| Dev env | 7/10 (harness exists) |
| Community | 6/10 |
| Measurement | 2/10 (no fetch counter) |
| **Overall** | **5/10** |

### Magical moment

Not “dropped entities table.” It is: approve a change and see `/llms.txt` + on-page JSON-LD update. Plan does not put that on a screen.

### Phase 3.5 complete.

---

## Cross-phase themes

1. **Approved-only public set** — CEO, Design, Eng. Stage 4 as written can leak columns or unpublish `needs_edit`.
2. **Do not drop the entity envelope** — CEO + Eng. Stage 6 as written is a hard fail.
3. **Review UI is the product; adapters are plumbing** — CEO + Design. Stage 5 gated behind a fat Stage 4.
4. **Inject must be in-product (WP checkbox), not INSTALL paste** — CEO + Design.
5. **Goldens are a net, not a spec** — Eng + DX. Honest `agent.json` cannot byte-match stub.

---

## Implementation Tasks (aggregated; plan edits only after gate)

- [x] **T1 (P1)** Split Stage 4 into 4a / 4b / 4c *(plan text, B2)*
- [x] **T2 (P1)** Keep `entities` as envelope; Stage 6 = drop attribute columns only *(plan text, B2)*
- [x] **T3 (P1)** `GET /diff` + claim-level verify + Save write rule *(specified in Stage 5)*
- [ ] **T4 (P1)** JSON-LD snippet XSS escape; WP optional `wp_head` *(implement in 4c)*
- [ ] **T5 (P2)** Machine-layer card; AIPREF toggles default off *(implement in 4c)*
- [x] **T6 (P2)** Fix plan footer verify commands
- [ ] **T7 (P3)** Fetch counter (8.6) as a real metric
- [ ] **T8 (P1)** Implement Stage 4a in product code — not started

---

## GSTACK REVIEW REPORT

| Review | Trigger | Why | Runs | Status | Findings |
|--------|---------|-----|------|--------|----------|
| CEO Review | `/autoplan` | Scope & strategy | 1 | issues_open | SELECTIVE EXPANSION; 3 user challenges; 3 critical gaps |
| Codex Review | `/codex` | Independent 2nd opinion | 0 | skipped | binary not on PATH |
| Eng Review | `/autoplan` | Architecture & tests | 1 | issues_open | 22 issues, 4 critical gaps |
| Design Review | `/autoplan` | UI/UX | 1 | issues_open | score 3/10 → 4/10; claim-level review unspecified |
| DX Review | `/autoplan` | Operator/installer DX | 1 | issues_open | score 5/10; TTHW 15 → 5 min target |

- **VERDICT:** Plan text updated for founder B2 (2026-08-18). Still NOT CLEARED to implement product code until 4a tests exist. Eng review should be re-run after 4a lands.

**UNRESOLVED DECISIONS:**
- None remaining from autoplan UCs (B2 accepted: split 4, keep envelope, writers first, WP inject opt-in, claim-level review)