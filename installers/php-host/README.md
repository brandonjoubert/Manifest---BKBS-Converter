# PHP host installer (no Python)

For **shared hosting without Python** (typical cPanel PHP accounts).

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
  data/
```

Or:

```text
bkbs.yourdomain.com  document root  →  php/ folder
```

## Install

1. Open `https://yourdomain.com/bkbs/install.php` in a browser  
2. Set **default web root** to your main site folder, e.g.  
   `/home/USERNAME/public_html`  
   (not the bkbs folder — the folder that serves the human website)  
3. Click **Install now**  
4. Open the app → add site → scan → approve → **Publish live**

## Requirements

- PHP 8.0+ recommended (7.4 may work with minor issues)  
- Extensions: `pdo_sqlite`, `curl`, `json`  
- `data/` directory writable by the web server  

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
