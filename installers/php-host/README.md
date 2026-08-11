# PHP host installer (no Python)

For **shared hosting without Python** (typical cPanel PHP accounts).

Part of **Manifest BKBS Converter** — same product goals as the Python edition  
(local PC and python-host installers).

## Ready-made package (already built)

```text
installers/php-host/bkbs-php-edition.zip
```

Upload **this zip** to your host (no need to build anything first).

If you change the PHP source later, rebuild with:

```bash
./installers/php-host/package.sh
```

## What to upload

Upload **`bkbs-php-edition.zip`** from this folder, **or** the contents of the **`php/`** folder to your host:

```text
public_html/bkbs/          ← recommended subdirectory
  install.php
  index.php
  .htaccess
  src/
  templates/
  scripts/                 ← Stage 0/2 verify + backfill CLIs (optional on host)
  data/
```

Or:

```text
bkbs.yourdomain.com  document root  →  php/ folder
```

## Install

1. Open `https://yourdomain.com/bkbs/install.php` in a browser  
2. Set **default web root** to your main site folder from File Manager, e.g.  
   `/home/YOUR_CPANEL_USERNAME/public_html`  
   (**not** the placeholder `/home/user/public_html`, and not the `bkbs` folder)  
3. Click **Install now**  
4. Open the app → **Settings** (LLM key optional)  
5. Add site → **Scan** → **Edit** each pending entity if needed → **Approve** → **Publish live**

## Requirements

- PHP 8.0+ recommended  
- Extensions: `pdo_sqlite`, `curl`, `json`  
- `data/` directory writable by the web server  
- Web root folder writable for live publish  

## After install (smoke)

On a machine with PHP CLI + `pdo_sqlite` (optional but recommended after clone):

```bash
# Stage 0 — entity-path export matches golden
php php/scripts/verify_exports.php

# Stage 2 — backfill + real resolve matches same golden (packaged zip uses scripts/ under bkbs-php/)
php php/scripts/verify_exports_via_resolve.php
# or after extract: php scripts/verify_exports_via_resolve.php

# One-shot backfill for existing approved entities (ledger dual-path; publish still uses entities)
php php/scripts/backfill_claims.php --db=/path/to/data/bkbs.sqlite
```

Requires monorepo layout (or mounted fixtures) for Stage 0/2 golden scripts. Shared-host install only needs `install.php` for day-to-day scan/publish.

## Security

- Prefer installing under a subdirectory or subdomain  
- Ensure `data/` and `config.php` are not downloadable (`.htaccess` included)  
- Add HTTP auth on `/bkbs/` if the host allows  

## Rebuild package (developers)

```bash
./installers/php-host/package.sh
# writes installers/php-host/bkbs-php-edition.zip
# and copies to dist/bkbs-php-edition.zip
```

## Docs

- Full install: `INSTALL.md` (Path C)  
- Product use: `USER_MANUAL.md`  
- Stage 0 fixtures: `test-fixtures/README.md`  
