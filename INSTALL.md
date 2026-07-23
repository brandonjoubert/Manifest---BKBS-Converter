# BKBS Converter — Complete Installation Instructions

This guide walks you through installing and running **BKBS Converter** so you can scan a website, verify business knowledge, and publish agent-ready files (`llms.txt`, `graph.json`, schema.org JSON-LD, and more).

There are **three supported deployment paths**. Choose **one** based on where you want the application to run.

---

## Table of contents

1. [What you are installing](#1-what-you-are-installing)  
2. [Which path should I choose?](#2-which-path-should-i-choose)  
3. [Requirements checklist](#3-requirements-checklist)  
4. [Path A — Install on your own PC](#4-path-a--install-on-your-own-pc)  
5. [Path B — Install on a Python-supported host](#5-path-b--install-on-a-python-supported-host)  
6. [Path C — Install on a non-Python (PHP) host](#6-path-c--install-on-a-non-python-php-host)  
7. [First use after installation (all paths)](#7-first-use-after-installation-all-paths)  
8. [Publishing files to the live website](#8-publishing-files-to-the-live-website)  
9. [Security checklist](#9-security-checklist)  
10. [Troubleshooting](#10-troubleshooting)  
11. [Feature comparison](#11-feature-comparison)  
12. [Quick reference](#12-quick-reference)  

---

## 1. What you are installing

**BKBS Converter** turns a normal business website into a dual-purpose presence:

- Humans still browse your HTML site.  
- AI agents can read structured files you publish (for example `/llms.txt` and `/graph.json`).

Typical workflow after install:

```text
Add site → Scan website → Review/approve entities → Publish live
                                                      ↓
                              Files appear on the public website
                              (llms.txt, graph.json, schema/, …)
```

Two editions exist:

| Edition | Location in this project | Used by |
|---------|--------------------------|---------|
| **Python** (full) | Project root (`app/`, `run.sh`, `passenger_wsgi.py`) | Path A and Path B |
| **PHP** (shared hosting) | `php/` folder / **`installers/php-host/bkbs-php-edition.zip`** (pre-built) | Path C |

---

## 2. Which path should I choose?

Answer the first question that matches your situation:

```text
Where will the application run?
│
├─ On my laptop or desktop computer?
│     → PATH A — Local PC (Python)
│
├─ On a web server that can run Python?
│     (VPS, Docker, cPanel “Setup Python App”, Cloud hosting)
│     → PATH B — Python-supported host
│
└─ On shared web hosting with PHP only (no Python)?
      (many cPanel accounts, HostGator-style shared, etc.)
      → PATH C — Non-Python host (PHP edition)
```

| Your situation | Path |
|----------------|------|
| Windows, macOS, or Linux PC | **A** |
| Ubuntu/Debian VPS with SSH | **B** |
| cPanel with **Setup Python App** | **B** |
| cPanel / shared host **without** Python | **C** |
| Only FTP access + PHP | **C** |
| Docker available | **B** (run the Python edition in a container or on the host) |

If you are unsure whether your host has Python: look in cPanel for **“Setup Python App”** or **“Passenger”**. If it is missing and you only see PHP, use **Path C**.

---

## 3. Requirements checklist

### Path A — Local PC

| Requirement | Notes |
|-------------|--------|
| Python **3.10+** | [python.org](https://www.python.org/downloads/) (Windows: tick “Add Python to PATH”) |
| Internet access | To install packages and scan websites |
| Optional: API key | OpenAI, xAI, OpenRouter, Groq, etc. for richer extraction |

### Path B — Python host

| Requirement | Notes |
|-------------|--------|
| Python **3.10+** on the server | VPS or cPanel Python App |
| Ability to install pip packages | Virtual environment recommended |
| Writable data directory | For SQLite database and exports |
| Writable **public_html** (or site root) | If you want auto-publish to the live domain |
| Optional: API key | Same as above |

### Path C — Non-Python (PHP) host

| Requirement | Notes |
|-------------|--------|
| PHP **8.0+** recommended | 8.1/8.2 ideal |
| Extensions: `pdo_sqlite`, `curl`, `json` | Enable in cPanel “Select PHP Version” if needed |
| FTP/File Manager upload | To place the PHP app on the server |
| Writable `data/` folder | For SQLite database |
| Writable main site folder | e.g. `public_html` for live publish |
| Optional: API key | Same as above |

---

## 4. Path A — Install on your own PC

Use this to run BKBS Converter on **your computer**. You can scan public websites and either export a ZIP or publish into a local folder.

### A.1 Get the software

Copy or clone the `bkbs-converter` project folder onto your PC.

Example:

```text
/home/you/bkbs-converter     (Linux/macOS)
C:\Users\you\bkbs-converter  (Windows)
```

### A.2 Install — Linux or macOS

1. Open a terminal in the project folder.

2. Make installers executable (once):

```bash
chmod +x installers/choose-install.sh installers/local/install.sh installers/python-host/install.sh installers/php-host/package.sh run.sh
```

3. Run the local installer:

```bash
./installers/local/install.sh
```

This will:

- Create a Python virtual environment (`.venv`)  
- Install dependencies from `requirements.txt`  
- Create `.env` from `.env.example` if needed  
- Create the `data/` directories  

4. Start the application:

```bash
source .venv/bin/activate
./run.sh
```

Or:

```bash
source .venv/bin/activate
uvicorn app.main:app --host 127.0.0.1 --port 8765 --reload
```

5. Open a browser:

**http://127.0.0.1:8765**

### A.3 Install — Windows

1. Install [Python 3.10+](https://www.python.org/downloads/). During setup, enable **“Add python.exe to PATH”**.

2. Open **Command Prompt** or **PowerShell** in the project folder.

3. Run:

```bat
installers\local\install.bat
```

4. Start the app:

```bat
.venv\Scripts\activate
uvicorn app.main:app --host 127.0.0.1 --port 8765
```

5. Open:

**http://127.0.0.1:8765**

### A.4 Optional: interactive chooser (Linux/macOS)

```bash
./installers/choose-install.sh
```

Select option **1** for Local PC.

### A.5 Local PC — what next?

Continue with [§7 First use](#7-first-use-after-installation-all-paths).

**Publishing from a PC:**

- Use **Export ZIP** and upload files to your website with FTP, **or**  
- Set **Web root** to a local folder you control (for testing), **or**  
- When ready for production, deploy Path B or C on your hosting account.

### A.6 Stop the app

In the terminal: press `Ctrl+C`.

---

## 5. Path B — Install on a Python-supported host

Use this when the server can run **Python**. This is the **full** edition (same as local).

### B.0 Decide how the public website and the admin app are arranged

Recommended layout:

```text
/home/USERNAME/
  bkbs-converter/          ← application (admin UI)
    app/
    data/                  ← private database (not public)
    passenger_wsgi.py
    .env
  public_html/             ← public website document root
    index.html             ← your normal human site
    llms.txt               ← written by “Publish live”
    graph.json
    schema/
    .well-known/
```

- Run the **admin app** on a subdomain if possible, e.g. `https://bkbs.yourdomain.com`  
- Keep the **human site** on `https://yourdomain.com` (`public_html`)

---

### B.1 Option 1 — cPanel “Setup Python App” (Passenger)

#### Step 1 — Upload the project

Upload the entire `bkbs-converter` folder via File Manager or FTP/SFTP, for example to:

```text
/home/USERNAME/bkbs-converter
```

Prefer **outside** `public_html` so the database and `.env` are not web-accessible.

#### Step 2 — Create the Python application

1. Log in to **cPanel**.  
2. Open **Setup Python App** (name may vary slightly).  
3. Click **Create Application**.  
4. Set:

| Field | Recommended value |
|-------|-------------------|
| Python version | 3.10, 3.11, or 3.12 |
| Application root | `bkbs-converter` (path to the project) |
| Application URL | subdomain e.g. `bkbs.yourdomain.com` (recommended) |
| Application startup file | `passenger_wsgi.py` |
| Application entry point | `application` |

5. Create / save the application.

cPanel will show a command to **enter the virtual environment**. Copy it.

#### Step 3 — Install Python packages

Open **Terminal** in cPanel (or SSH), then:

```bash
# paste the “source …/bin/activate” command from cPanel first, then:
cd ~/bkbs-converter
pip install --upgrade pip
pip install -r requirements.txt
```

Or run:

```bash
bash installers/python-host/install.sh
```

(if the project is already on the server and `python3` works).

#### Step 4 — Environment variables (optional but recommended)

In the Python App settings, or in a `.env` file in the project root:

```env
BKBS_DATA_DIR=/home/USERNAME/bkbs-converter/data
BKBS_ALLOW_PRIVATE_URLS=0
DEFAULT_PUBLISH_ROOT=/home/USERNAME/public_html
```

Create `data` if needed:

```bash
mkdir -p ~/bkbs-converter/data/exports ~/bkbs-converter/data/live
```

#### Step 5 — Restart and open

1. In **Setup Python App**, click **Restart**.  
2. Open the application URL shown by cPanel (e.g. `https://bkbs.yourdomain.com`).  
3. You should see the BKBS Converter dashboard.

#### Step 6 — Configure live publish

See [§8](#8-publishing-files-to-the-live-website). Set web root to:

```text
/home/USERNAME/public_html
```

(use your real home path from File Manager).

More detail: [`deploy/SHARED_HOSTING.md`](./deploy/SHARED_HOSTING.md)

---

### B.2 Option 2 — VPS / dedicated server with SSH

#### Step 1 — Copy the project to the server

```bash
# example
cd /opt
sudo git clone <your-repo-url> bkbs-converter
# or upload a zip and extract
cd /opt/bkbs-converter
```

#### Step 2 — Install system packages (Debian/Ubuntu example)

```bash
sudo apt update
sudo apt install -y python3 python3-venv python3-pip
```

#### Step 3 — Run the installer

```bash
chmod +x installers/python-host/install.sh
./installers/python-host/install.sh
```

#### Step 4 — Start (test)

```bash
source .venv/bin/activate
uvicorn app.main:app --host 127.0.0.1 --port 8765
```

Visit `http://SERVER_IP:8765` only if the firewall allows it. Prefer a reverse proxy (below).

#### Step 5 — Keep it running with systemd (recommended)

Create `/etc/systemd/system/bkbs-converter.service`:

```ini
[Unit]
Description=BKBS Converter
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/opt/bkbs-converter
Environment="PATH=/opt/bkbs-converter/.venv/bin"
Environment="BKBS_DATA_DIR=/opt/bkbs-converter/data"
Environment="DEFAULT_PUBLISH_ROOT=/var/www/html"
ExecStart=/opt/bkbs-converter/.venv/bin/uvicorn app.main:app --host 127.0.0.1 --port 8765
Restart=always

[Install]
WantedBy=multi-user.target
```

Adjust `User`, paths, and `DEFAULT_PUBLISH_ROOT` for your server.

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now bkbs-converter
sudo systemctl status bkbs-converter
```

#### Step 6 — Nginx reverse proxy (example)

```nginx
server {
    listen 80;
    server_name bkbs.yourdomain.com;

    location / {
        proxy_pass http://127.0.0.1:8765;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Enable TLS with Certbot when ready.

#### Step 7 — Permissions for publish

The service user must be able to write to the website document root:

```bash
# example: app user can write site files
sudo usermod -aG www-data your-app-user
sudo chown -R www-data:www-data /var/www/html
# or grant group write on the web root
```

---

### B.3 After Path B install

Continue with [§7 First use](#7-first-use-after-installation-all-paths) and [§8 Publishing](#8-publishing-files-to-the-live-website).

---

## 6. Path C — Install on a non-Python (PHP) host

Use this when the host **cannot run Python**. You install the **PHP edition**.

### C.1 Overview

```text
Project folder                              Shared host
──────────────                              ───────────
installers/php-host/
  bkbs-php-edition.zip  ──upload/extract──►  public_html/bkbs/
                                             install.php  (browser)
                                                  ↓
                                             Admin UI (PHP)
                                                  ↓
                                             Publish → public_html/llms.txt etc.
```

### C.2 Get the upload package (already built)

The non-Python package is **pre-built** and lives in the installer directory:

```text
installers/php-host/bkbs-php-edition.zip
```

Use that file — you do **not** need to run a build step for a normal install.

| Location | Use |
|----------|-----|
| **`installers/php-host/bkbs-php-edition.zip`** | **Primary — upload this** |
| `dist/bkbs-php-edition.zip` | Copy created when rebuild script runs |
| `php/` folder | Source; developers only |

**Rebuild only if you changed the PHP source:**

```bash
./installers/php-host/package.sh
```

**Or** use the chooser (points you at the same zip):

```bash
./installers/choose-install.sh
# select 3
```

### C.3 Upload to the host

Using **cPanel File Manager** or **FTP**:

1. Go to `public_html` (or your domain’s document root).  
2. Create a folder, e.g. `bkbs`.  
3. Upload **`installers/php-host/bkbs-php-edition.zip`** into `bkbs`.  
4. Extract it so you get either:

```text
public_html/bkbs/
  install.php
  index.php
  .htaccess
  src/
  templates/
  data/
```

or (if the zip has a top folder):

```text
public_html/bkbs/bkbs-php/...
```

If you see an extra `bkbs-php` folder, move its contents up into `public_html/bkbs/` so `install.php` is directly under `bkbs/`.

**Alternative:** point a subdomain’s document root at the PHP app folder (e.g. `bkbs.yourdomain.com` → that folder). That is cleaner for security.

### C.4 Run the web installer

1. In a browser open:

```text
https://yourdomain.com/bkbs/install.php
```

(or `https://bkbs.yourdomain.com/install.php` if using a subdomain)

2. Confirm PHP shows **PDO SQLite: yes** and **cURL: yes**.  
   If not, cPanel → **Select PHP Version** → enable `pdo_sqlite` and `curl`.

3. Fill in:

| Field | Example | Meaning |
|-------|---------|---------|
| Application name | BKBS Converter (PHP) | Label only |
| Default web root | `/home/USERNAME/public_html` | Folder of the **main website**, not the `bkbs` folder |

How to find the real path: cPanel File Manager often shows the full path at the top; it looks like `/home/youruser/public_html`.

4. Click **Install now**.

5. When it says installation complete, click **Open the application**.

### C.5 Folder permissions

If install fails with “not writable”:

- `data/` must be writable by the web server (often `755` or `775`).  
- During install, the app folder must allow creating `config.php`.  
- After install, `config.php` and `data/` should stay writable only as needed; do not make them world-readable beyond what the host requires.

### C.6 After Path C install

Continue with [§7 First use](#7-first-use-after-installation-all-paths).  
Use **Publish live** so files go into the main `public_html` path you configured.

More notes: [`installers/php-host/README.md`](./installers/php-host/README.md)

---

## 7. First use after installation (all paths)

These steps are the same whether you used A, B, or C (labels may vary slightly in PHP vs Python UI).

### Step 1 — Open the admin UI

| Path | Typical URL |
|------|-------------|
| A Local | http://127.0.0.1:8765 |
| B Python host | https://bkbs.yourdomain.com (or host-assigned URL) |
| C PHP host | https://yourdomain.com/bkbs/ |

### Step 2 — Configure an LLM API key (strongly recommended)

Without an API key, scans only use **heuristics** (fewer, thinner entities).

1. Open **Settings**.  
2. Choose any **OpenAI-compatible** provider, for example:

| Provider | Example base URL | Example model |
|----------|------------------|---------------|
| OpenAI | `https://api.openai.com/v1` | `gpt-4o-mini` |
| xAI / Grok | `https://api.x.ai/v1` | `grok-4.5` |
| OpenRouter | `https://openrouter.ai/api/v1` | `openai/gpt-4o-mini` |
| Groq | `https://api.groq.com/openai/v1` | (see Groq docs) |

3. Paste your **API key**.  
4. **Save**, then **Test connection**.

### Step 3 — Add your website

1. On the home page, **Add website** / create site.  
2. **Display name** — for you (e.g. “Main company site”).  
3. **Website URL** — public homepage, e.g. `https://www.example.com`.  
4. **Web root path** — filesystem path where live files should be written (see §8).  
5. Enable **auto-publish** if available.  
6. Save / create.

### Step 4 — Scan

Click **Scan** / **Scan website**. Wait until status is **completed**.

### Step 5 — Review entities

1. Open **Review entities**.  
2. **Approve** facts that are true.  
3. **Reject** junk or wrong items.  
4. Add missing items with **Manual entry** if needed.

Only **approved** items are used for production publish.

### Step 6 — Publish live

Click **Publish live to web root** (Python and PHP editions).

### Step 7 — Verify on the public domain

In a private browser window open:

```text
https://www.example.com/llms.txt
https://www.example.com/graph.json
```

You should see content, not a 404 page.

---

## 8. Publishing files to the live website

### What gets written

When publish succeeds, the web root receives files such as:

| File | Public URL |
|------|------------|
| `llms.txt` | `https://yourdomain.com/llms.txt` |
| `llms-full.txt` | `https://yourdomain.com/llms-full.txt` |
| `graph.json` | `https://yourdomain.com/graph.json` |
| `schema/organization.jsonld` | `https://yourdomain.com/schema/organization.jsonld` |
| `schema/services.jsonld` | `https://yourdomain.com/schema/services.jsonld` |
| `.well-known/agent.json` | `https://yourdomain.com/.well-known/agent.json` |
| `robots.txt` | Existing file is **merged** (BKBS block only), not wiped |

### Setting the web root correctly

| Hosting style | Typical web root value |
|---------------|-------------------------|
| cPanel main domain | `/home/USERNAME/public_html` |
| cPanel addon domain | `/home/USERNAME/public_html/addondomain` or a separate folder shown in File Manager |
| VPS Nginx default | `/var/www/html` or `/var/www/your-site/html` |
| Local test | e.g. `/home/you/bkbs-converter/data/live-public` |

**Important:** Web root is the folder of the **public website**, not the folder of the BKBS admin app (unless they are the same by design).

### Permissions

The user running the app (Python process or PHP/web server) must be allowed to **create and update files** in that folder. If publish fails with “not writable”, fix ownership/permissions in File Manager or with `chmod`/`chown` on a VPS.

### Ongoing updates

Whenever the website content changes:

1. **Rescan**  
2. Review new/changed entities  
3. **Publish live** again  

---

## 9. Security checklist

- [ ] Database folder (`data/`) is **not** publicly listable or downloadable  
- [ ] `.env` (Python) and `config.php` (PHP) are not served as public files  
- [ ] Prefer admin UI on a **subdomain** with HTTPS  
- [ ] Add **password protection** (cPanel Directory Privacy, HTTP basic auth, or reverse-proxy auth) on the admin URL  
- [ ] Do not commit API keys to git; use Settings UI or server env  
- [ ] Publish only **approved** entities  
- [ ] Keep PHP/Python and packages reasonably up to date  

---

## 10. Troubleshooting

| Problem | Likely cause | What to do |
|---------|--------------|------------|
| `python3: command not found` | Python not installed | Install Python 3.10+ or use Path C |
| `pip install` fails | Old pip / no venv / network | Use project venv; upgrade pip; check firewall |
| Browser cannot open :8765 | App not running / wrong host | Start uvicorn; check firewall; use 127.0.0.1 locally |
| cPanel Python app 502 / error | Missing packages or wrong entrypoint | `pip install -r requirements.txt`; startup file `passenger_wsgi.py`; entry `application`; Restart app |
| PHP install: PDO SQLite NO | Extension disabled | Enable `pdo_sqlite` in Select PHP Version |
| PHP install: cannot write config | Folder permissions | Temporarily allow write on app folder; ensure `data/` writable |
| Scan returns few entities | No LLM key | Configure Settings → API key → rescan |
| Publish: no web root | Path not set | Set site web root / default publish root |
| Publish: not writable | Permissions | Fix ownership of `public_html` for the app user |
| `llms.txt` 404 after publish | Wrong web root | Publish path must be the real document root for the domain |
| LLM test fails | Bad key/URL/model | Check base URL ends with `/v1` style path your provider documents; verify model name |

---

## 11. Feature comparison

| Feature | Path A/B Python | Path C PHP |
|---------|-----------------|------------|
| Scan website | Yes | Yes |
| Heuristic extraction | Yes | Yes |
| LLM extraction (OpenAI-compatible) | Yes | Yes |
| Approve / reject entities | Yes | Yes |
| Manual entry | Yes | Yes |
| Publish live to web root | Yes | Yes |
| Export ZIP download | Yes | Not primary (publish live instead) |
| Full entity JSON editor | Yes | Basic |
| Background long scans | Async worker | Runs in request (use moderate max pages) |

Both editions produce the **same style of public machine files** for AI agents.

---

## 12. Quick reference

### One-liner by path

**A — Local Linux/macOS**

```bash
./installers/local/install.sh && source .venv/bin/activate && ./run.sh
# → http://127.0.0.1:8765
```

**A — Local Windows**

```bat
installers\local\install.bat
.venv\Scripts\activate
uvicorn app.main:app --host 127.0.0.1 --port 8765
```

**B — Python host (after upload)**

```bash
./installers/python-host/install.sh
# cPanel: passenger_wsgi.py / application  OR  uvicorn + reverse proxy
```

**C — PHP host**

```text
Upload: installers/php-host/bkbs-php-edition.zip
Extract into: public_html/bkbs/
Open: https://YOURDOMAIN/bkbs/install.php
```

### Interactive menu

```bash
./installers/choose-install.sh
```

### Key files

| File | Purpose |
|------|---------|
| `INSTALL.md` | This document |
| `installers/local/install.sh` / `install.bat` | PC install |
| `installers/python-host/install.sh` | Server Python install |
| `installers/php-host/bkbs-php-edition.zip` | **Pre-built PHP package to upload** |
| `installers/php-host/package.sh` | Rebuild PHP zip after source changes |
| `installers/php-host/README.md` | PHP upload notes |
| `deploy/SHARED_HOSTING.md` | Deep dive for Python shared/cPanel |
| `passenger_wsgi.py` | cPanel Python entrypoint |
| `php/install.php` | PHP web installer |
| `USER_MANUAL.md` | How to use the product day to day |
| `README.md` | Project overview |

### After install — 60-second checklist

1. Open admin URL  
2. Settings → API key → Test  
3. Add site + web root  
4. Scan  
5. Approve entities  
6. Publish live  
7. Open `https://yourdomain.com/llms.txt`  

---

## Summary

| Deploy on… | Install path | Edition |
|------------|--------------|---------|
| **Your own PC** | Path **A** | Python |
| **Python-supported host** | Path **B** | Python |
| **Non-Python shared host** | Path **C** | PHP |

Pick the path that matches your environment, follow that section step by step, then complete **First use** and **Publish** so AI agents can discover your business knowledge on your live domain.

For day-to-day product usage (entity types, verification, rescans), see **[USER_MANUAL.md](./USER_MANUAL.md)**.
