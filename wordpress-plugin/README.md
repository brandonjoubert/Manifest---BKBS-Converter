# Manifest BKBS Converter — WordPress plugin (standalone)

This folder is a **completely separate product path** from:

- the **Python** edition (`app/`)
- the **PHP shared-hosting** edition (`php/`)

It does **not** change or replace the main project documentation (`INSTALL.md`, `USER_MANUAL.md`, etc.).

## Install on WordPress

1. Zip the inner folder `manifest-bkbs-converter/` (or copy it into `wp-content/plugins/`).
2. In wp-admin → **Plugins** → activate **Manifest BKBS Converter**.
3. Open **Manifest BKBS** in the admin menu.

### Quick zip from this monorepo

```bash
cd wordpress-plugin
zip -r manifest-bkbs-converter.zip manifest-bkbs-converter \
  -x '*/.git/*' -x '*/node_modules/*'
```

Upload `manifest-bkbs-converter.zip` via Plugins → Add New → Upload.

## Features (v0.1.0)

- Scan this site or additional URLs  
- Heuristic + optional OpenAI-compatible LLM extraction  
- Entity list with **Edit before approve** / Save & approve  
- Manual entity entry  
- Publish agent layers via rewrites + optional static file write  

Public endpoints after publish:

- `https://yoursite.com/llms.txt`
- `https://yoursite.com/graph.json`
- `https://yoursite.com/schema/organization.jsonld`
- etc.

## Requirements

- WordPress 6.0+  
- PHP 8.0+  
- `manage_options` capability for admin  

## License

Apache-2.0 (same family as the monorepo).
