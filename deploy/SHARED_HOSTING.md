# Deploy BKBS Converter on a shared host (auto-publish to public web root)

This guide is for hosts where you **upload files** (cPanel, Plesk, managed shared/VPS) and want the app to **write machine files into the live website folder** automatically — so agents can fetch:

```text
https://yourdomain.com/llms.txt
https://yourdomain.com/graph.json
https://yourdomain.com/schema/organization.jsonld
https://yourdomain.com/.well-known/agent.json
```

You do **not** download a ZIP and FTP each file by hand after every scan.

---

## Reality check: what “shared hosting” can run

| Host type | Can run this app? | Notes |
|-----------|-------------------|--------|
| **cPanel “Setup Python App” / Passenger** | Yes | Best path for traditional shared+Python |
| **VPS / Cloud (Ubuntu, Docker)** | Yes | Full control; recommended |
| **PHP-only classic shared** (no Python) | **No** | Cannot run FastAPI; use a small VPS or host with Python |
| **Static-only Netlify/Pages** | Partial | Build elsewhere; publish static files only |

If your host has **no Python**, you cannot run the converter *on* that host. Run it on a $5 VPS and set **publish root** via SFTP mount, or run on VPS and point DNS — or upgrade hosting to Python App support.

---

## Recommended layout on the server

```text
/home/USERNAME/
  bkbs-converter/          ← application (not required to be public)
    app/
    data/                  ← SQLite DB, private
    passenger_wsgi.py
    requirements.txt
  public_html/             ← website document root (public)
    index.html             ← your human website
    llms.txt               ← written by BKBS Publish
    graph.json
    schema/
    .well-known/
      agent.json
```

**Important:** Keep `data/` and `.env` **outside** public URLs (or protect them). Never expose `data/bkbs.db` or API keys.

---

## Install (cPanel Python App / Passenger)

1. Upload or `git clone` this project to e.g. `/home/USERNAME/bkbs-converter`.
2. cPanel → **Setup Python App** → Create:
   - Python version 3.10+
   - Application root: `bkbs-converter`
   - Application startup file: `passenger_wsgi.py`
   - Application entry point: `application`
3. Enter the virtualenv shown by cPanel and install deps:

```bash
pip install -r requirements.txt
```

4. Set environment variables in the Python App UI (or `.env` in app root):

```env
BKBS_DATA_DIR=/home/USERNAME/bkbs-converter/data
BKBS_ALLOW_PRIVATE_URLS=0
# Optional default publish root for all sites:
# DEFAULT_PUBLISH_ROOT=/home/USERNAME/public_html
```

5. Restart the Python application.
6. Open the app URL cPanel gives you (or map a subdomain `bkbs.yourdomain.com`).

### Map admin to a subdomain (recommended)

- Subdomain `bkbs.yourdomain.com` → Passenger app  
- Main site `yourdomain.com` → `public_html`  

Humans use the main site; you use the subdomain to scan/approve/publish.

---

## Configure auto-save to the correct locations

In the BKBS UI, open the **site** → **Live publish**:

| Field | Example |
|-------|---------|
| **Web root path** | `/home/USERNAME/public_html` |
| **Auto-publish** | checked |

Click **Save site settings**, then **Publish live to web root**.

The app writes:

| File on disk | Public URL |
|--------------|------------|
| `public_html/llms.txt` | `https://domain/llms.txt` |
| `public_html/llms-full.txt` | `https://domain/llms-full.txt` |
| `public_html/graph.json` | `https://domain/graph.json` |
| `public_html/schema/organization.jsonld` | `https://domain/schema/organization.jsonld` |
| `public_html/schema/services.jsonld` | `https://domain/schema/services.jsonld` |
| `public_html/.well-known/agent.json` | `https://domain/.well-known/agent.json` |
| `public_html/robots.txt` | Merged safely (BKBS block only) |

### Workflow every time content changes

1. **Scan / Rescan**  
2. **Approve** entities  
3. **Publish live** (or Export with auto-publish on)  
4. Verify: open `https://yourdomain.com/llms.txt` in a browser  

---

## VPS / Docker (full control)

```bash
cd /opt/bkbs-converter
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
export DEFAULT_PUBLISH_ROOT=/var/www/your-site/html
uvicorn app.main:app --host 127.0.0.1 --port 8765
```

Put Nginx/Caddy in front; reverse-proxy `/admin` or a subdomain to port 8765.  
Set site web root to `/var/www/your-site/html`.

---

## Permissions

The Unix user running the Python app must be able to **write** to the web root:

```bash
# example
chmod u+w /home/USERNAME/public_html
# or ensure app user is in the same group as the web files
```

If publish fails with “not writable”, fix ownership/permissions in cPanel File Manager or SSH.

---

## Security checklist

- [ ] Admin UI not world-writable; prefer auth / IP allowlist / HTTP basic auth in front of Passenger  
- [ ] `.env` and `data/` not under a public URL  
- [ ] LLM API keys only in Settings DB / env, never in public files  
- [ ] Only **approved** entities are published live  

---

## API

```http
POST /api/sites/{id}/publish
```

Publishes approved entities to the configured web root.

```http
POST /api/sites/{id}/export
```

Builds ZIP; if `auto_publish` is on, also writes live files.

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| Publish error: no root | Set web root path on the site |
| Not writable | Fix directory permissions for the app user |
| Files written but 404 | Wrong path (not real document root); check where `index.html` lives |
| robots.txt overwritten? | App only merges a `# BEGIN BKBS` … `# END BKBS` block |
| Python app 502 | Check Passenger logs; `pip install -r requirements.txt`; restart app |
| Host has no Python | Move converter to a VPS; keep public_html on shared host only if you sync files another way |

---

## Summary

**Yes — on a host that can run Python**, upload this app, set **web root = public_html**, approve knowledge, and **Publish live**. The required BKBS files are saved to the correct public locations automatically.
