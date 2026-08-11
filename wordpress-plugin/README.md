# Manifest BKBS Converter — WordPress plugin

Standalone **WordPress product path**. Independent of:

- the **Python** edition (`app/`)
- the **PHP shared-hosting** edition (`php/`)

You do **not** need Python or the PHP Host app to run this plugin.

---

## Install from this monorepo (recommended)

The repo already ships a ready zip. Use it so Stage 2 files (backfill, resolver, Tools page) are included:

```text
wordpress-plugin/manifest-bkbs-converter.zip
```

1. In WordPress: **Plugins → Add New → Upload Plugin**.  
2. Choose `manifest-bkbs-converter.zip` → **Install Now** → **Activate**.  
3. Open **Manifest BKBS** in the admin menu.

### Alternative: copy the plugin folder

```text
wordpress-plugin/manifest-bkbs-converter/  →  wp-content/plugins/manifest-bkbs-converter/
```

Then activate under **Plugins**.

### Rebuild zip (developers only)

After changing plugin source:

```bash
cd wordpress-plugin
rm -f manifest-bkbs-converter.zip
zip -r manifest-bkbs-converter.zip manifest-bkbs-converter \
  -x '*/.git/*' -x '*/node_modules/*' -x '*/__pycache__/*'
```

Commit the updated zip when shipping plugin changes.

---

## First-use workflow (error-free path)

1. **Settings** — optional OpenAI-compatible LLM API key (without it, scans use heuristics only).  
2. **Dashboard** — scan this WordPress site (or add another base URL).  
3. **Entities** — open each pending item → **Edit before approve** if needed → Approve.  
4. **Publish live (approved only)** — optionally tick “write static files into the WordPress root”.  
5. Verify in a private window:
   - `https://yoursite.com/llms.txt`
   - `https://yoursite.com/graph.json`
   - `https://yoursite.com/schema/organization.jsonld` (`@type` should be **LocalBusiness**)

Only **approved** entities are published.

---

## Claim ledger (Stage 1–2) — optional

After activation the plugin creates `{prefix}mbkbs_claims` (DB version **2**).

| Action | When |
|--------|------|
| **Do nothing** | Normal scan / approve / publish — still works; production publish reads **entities**. |
| **Tools → Run backfill** | You already have approved entities and want the append-only claim ledger filled for dual-path resolve (Stage 2). Idempotent. |

WP-CLI (if available):

```bash
wp mbkbs backfill-claims
wp mbkbs backfill-claims --dry-run
wp mbkbs backfill-claims --update
```

---

## Requirements

| Requirement | Notes |
|-------------|--------|
| WordPress **6.0+** | Tested against modern WP admin |
| PHP **8.0+** | Same as monorepo PHP edition |
| Capability | Admin needs `manage_options` |
| Permalinks | Flush once after activate if rewrite URLs 404 |

---

## Features (v0.1.x)

- Scan this site or additional URLs  
- Heuristic + optional OpenAI-compatible LLM extraction  
- Entity list with **Edit before approve** / Save & approve  
- Manual entity entry  
- Publish agent layers via rewrites + optional static file write  
- Admin **Tools** → claim backfill (Stage 2)

Public endpoints after publish (examples):

- `https://yoursite.com/llms.txt`
- `https://yoursite.com/graph.json`
- `https://yoursite.com/schema/organization.jsonld`
- `https://yoursite.com/schema/services.jsonld`
- `https://yoursite.com/.well-known/agent.json`

---

## Troubleshooting

| Problem | What to do |
|---------|------------|
| Plugin missing after clone | Install the **zip**, not only the monorepo root |
| Tables missing | Deactivate/reactivate or ensure `MBKBS_DB_VERSION` upgrade ran |
| Publish 404 | Permalinks → Save; confirm rewrite rules; or enable “write static files” |
| Few entities from scan | Add LLM key under Settings → rescan |
| Backfill inserted 0 | Need at least one **approved** entity first |

Monorepo-wide install and quality gates: **[INSTALL.md](../INSTALL.md)** · **[README.md](../README.md)** · **[test-fixtures/README.md](../test-fixtures/README.md)**

---

## License

Apache-2.0 (same as the monorepo).
