# Stage 0 test fixtures (Claim Ledger baseline)

These fixtures lock **current** export behaviour before the claim-ledger migration.

## What is here

| Path | Purpose |
|------|---------|
| `stage0_site.json` | Deterministic site + entities (fixed UUIDs) |
| `entity-counts.json` | Recorded status counts for the fixture |
| `golden-v0/` + `golden-v0.zip` | **Python** full export package (local + python-host) |
| `golden-v0-php/` | **PHP** publisher output (php-host) |
| `stage0-baseline.json` | Capture metadata / hashes |

## Install paths covered

| Install path | Edition | Golden |
|--------------|---------|--------|
| Local PC | Python | `golden-v0` |
| Python host | Python | `golden-v0` |
| PHP host | PHP | `golden-v0-php` |

## Commands

```bash
# Capture goldens (both editions)
python scripts/capture_golden.py --edition all

# Verify current code still matches
python scripts/verify_exports.py --edition all

# PHP only
php php/scripts/capture_golden.php
php php/scripts/verify_exports.php
```

## Notes

- Production export includes **approved** entities only (fixture includes one pending entity that must be excluded).
- Comparisons normalize timestamps (`generated_at`, ISO datetimes) for stability.
- Do not hand-edit golden files; re-run capture after intentional export changes.
