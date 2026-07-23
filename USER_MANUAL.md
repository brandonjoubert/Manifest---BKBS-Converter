# Manifest BKBS Converter — User Manual

**Version:** 1.0  
**Audience:** Business owners, marketing/ops staff, and web managers who want their website to work for both people *and* AI agents.

---

## 1. What problem does this solve?

Today, most business websites are built for **human browsers**: pages, menus, images, and marketing copy.

AI agents (chatbots, research tools, shopping agents, procurement bots) usually try to understand your business by **scraping HTML**. That is slow, error-prone, and often produces wrong answers about:

- What you actually sell or install  
- Where you operate  
- How fast you respond  
- What facilities you serve  
- Policies, warranties, and certifications  

**Manifest BKBS Converter** turns your existing website into a **Business Knowledge Base** — a structured, verifiable set of facts that AI systems can read reliably, while your normal human website stays as it is.

Think of it as:

| For humans | For AI agents |
|------------|----------------|
| Your normal website (HTML pages) | A machine package: clean summaries, structured data, knowledge graph |

You stay in control: nothing becomes “official” until **you approve** it.

---

## 2. What is the value?

### 2.1 Authoritative answers instead of guesswork

Without structure, an AI might invent a phone number, wrong service area, or claim you offer something you do not.

With BKBS, agents can answer from **your approved facts**, with links back to evidence on your site.

### 2.2 Better discoverability in the AI era

Search and assistants increasingly prefer sources that publish:

- Schema.org / JSON-LD structured data  
- Clean AI navigation files (`llms.txt`)  
- Explicit product/service and capability information  

This app builds those layers from your site (plus your edits), instead of forcing you to hand-write everything from scratch.

### 2.3 One living knowledge base, not a pile of pages

BKBS organises your business into **entities** and **relationships**, for example:

- Capability: *Install CCTV*  
- Facility served: *Warehouses*  
- Asset: *Hikvision*  
- Policy: *Warranty*  

Linked together, so an agent can ask:

> “Who installs CCTV for warehouses in Durban with certified installers?”

…and get a precise match — not a vague page scrape.

### 2.4 Human verification = trust

Automated extraction is a starting point. **You** decide what is true:

- Approve correct items  
- Edit incomplete ones  
- Reject junk or marketing fluff  

Exports for “production” use only **approved** knowledge.

### 2.5 Rescan when the site changes

Websites change. Re-run a scan to pick up new services or pages. The app **merges** updates instead of wiping your approved work, and flags things that need another look.

### 2.6 Manual entry for knowledge not on the website

Price lists, certifications, response SLAs, and internal expertise often live in emails or people’s heads. Paste text or fill a form — the app converts that into the same standard format.

---

## 3. Plain-language overview of the product

```
Your website  →  Scan  →  Draft knowledge entries  →  You review  →  Export package  →  Deploy on your site
                      ↗                    ↘
              Manual form / free text     Rescan later
```

**Main objects you work with:**

| Object | Meaning |
|--------|---------|
| **Site** | One business website (e.g. your company domain) |
| **Scan** | One crawl job that reads pages and proposes knowledge |
| **Entity** | One fact-object (a service, capability, policy, person, etc.) |
| **Status** | Whether that entity is ready for the outside world |
| **Export** | A downloadable ZIP of agent-ready files |

---

## 4. The 12 types of knowledge (BKBS entities)

Every piece of knowledge is one of these types:

| Type | What it captures | Example |
|------|------------------|---------|
| **Business Identity** | Who you are: name, contact, area, hours | GENSIX Security Solutions |
| **Products & Services** | Things you sell or deliver | CCTV camera package |
| **Capabilities** | What you can *do* | Install CCTV, Access control install |
| **Expertise** | Knowledge domains | Physical security, networking |
| **Facilities Served** | Types of places you work in | Warehouses, offices, retail |
| **Operational Problems** | Customer pain points you address | Perimeter breaches |
| **Projects** | Completed work (non-confidential) | Warehouse CCTV upgrade, Durban |
| **Knowledge Articles** | Guides, FAQs, explanations | “How many cameras for a yard?” |
| **Policies** | Warranty, privacy, safety, terms | 12-month workmanship warranty |
| **Team** | People and roles | Lead installer, project manager |
| **Assets** | Brands, tools, platforms you support | Hikvision, Dahua |
| **Relationships** | How things connect | Warehouse → requires → Access Control |

You do not need every type on day one. Start with **Identity**, **Capabilities**, **Facilities**, and **Policies**.

---

## 5. Getting started (first run)

### 5.1 Start the application

On the machine where Manifest BKBS Converter is installed:

```bash
cd /home/brandon/bkbs-converter
source .venv/bin/activate
./run.sh
```

Or:

```bash
uvicorn app.main:app --host 127.0.0.1 --port 8765 --reload
```

Open a browser:

**http://127.0.0.1:8765**

### 5.2 Optional: enable AI extraction (recommended)

In the top bar you may see **“No XAI_API_KEY”**.

- **Without a key:** the app still works using rules (schema.org on pages, keywords, contact details). Good for a first pass.  
- **With a SpaceXAI / xAI key:** the app uses **Grok** to read page text and propose richer, more accurate entities.

To enable AI:

1. Copy `.env.example` to `.env` (if you do not already have `.env`).  
2. Set `XAI_API_KEY=your_key_here`.  
3. Restart the app.  
4. Confirm the top bar shows **“SpaceXAI ready”**.

> The API key stays on the server. It is never shown in the browser.

---

## 6. Step-by-step: your first website

### Step 1 — Add a site

On the **Sites** (home) page:

1. **Display name** — a label for you (e.g. `GENSIX main site`).  
2. **Website URL** — the public homepage (e.g. `https://www.example.com`).  
   - For local testing you can use `http://127.0.0.1:8090` if you serve a site locally.  
3. **Max pages** — how many pages to crawl (default `40`). Raise for large sites; lower for a quick test.  
4. **Crawl delay** — pause between page requests in milliseconds (default `300`). Be polite to live servers.  
5. Click **Create site**.

You land on the **site overview**.

### Step 2 — Scan the website

Click **Scan website**.

The app will:

1. Read `robots.txt` and `sitemap.xml` when available  
2. Visit pages on the same domain (up to max pages)  
3. Extract text and any existing structured data  
4. Propose BKBS entities (heuristic ± AI)  
5. Save them as **Pending** for your review  

The scan status page refreshes automatically. When status is **completed**, click **Review entities**.

If status is **failed**, read the error message (common causes: site offline, blocked crawl, invalid URL).

### Step 3 — Review and verify entities

Open **Review entities**.

Each row is one knowledge entry with:

- **Status** (pending, approved, …)  
- **Type** (capability, policy, …)  
- **Name** and short description  
- **Source** (scan / heuristic / llm / manual)

#### What each status means

| Status | Meaning | Included in production export? |
|--------|---------|--------------------------------|
| **pending** | Suggested; not yet reviewed | No (unless you export “draft”) |
| **approved** | You confirmed it is true | **Yes** |
| **needs_edit** | Changed after approval, or flagged for fix | No |
| **rejected** | Wrong / spam / not useful | No |
| **stale** | Not seen on the latest rescan (may be outdated) | No |

#### Quick review actions

**Bulk (table view):**

1. Tick the checkboxes for several rows (or use select-all).  
2. Click **Approve selected**, **Reject selected**, or **Mark needs edit**.

**One entity:**

1. Click **Edit**.  
2. Fix name, description, type, properties.  
3. Use **Approve** / **Reject** / **Needs edit**, or set status and **Save changes**.

#### Editing tips

- Prefer **facts** over marketing slogans.  
- Put structured detail in **Properties (JSON)** when useful, e.g.  
  `{"region": "Durban", "response_hours": 24}`  
- **Evidence** should point at real page URLs that support the claim.  
- **Relationships** link entities, e.g.  
  `[{"predicate": "supports", "target_name": "Warehouses"}]`

When in doubt: **approve** only what you would put on a signed company fact sheet.

### Step 4 — Fill gaps manually

Some knowledge never appears clearly on the website. Click **Manual entry** on the site page.

#### Option A — Structured form

1. Choose **entity type**.  
2. Enter **name** and **description**.  
3. Optionally add properties as JSON.  
4. Tick **Approve immediately** if you already trust the content.  
5. Click **Create entity**.

#### Option B — Free text → AI conversion

1. Paste notes, brochure text, or an email summary.  
2. Optionally pick a preferred type (or leave Auto).  
3. Click **Convert with AI**.  
4. Review the new entities in the inbox (they start as pending unless you ticked approve).

Example paste:

> We install Hikvision and major-brand CCTV for warehouses and commercial sites in Durban. Certified installers. Target response within 24 hours in metro. 12-month workmanship warranty. Access control and intrusion detection also available.

### Step 5 — Export the BKBS package

On the site overview:

- **Export approved (ZIP)** — production package (only approved entities).  
- **Export draft (incl. pending)** — includes unreviewed items for internal review only.

Download the ZIP. Inside you will find files ready to publish (see [Section 8](#8-what-is-in-the-export-package)).

### Step 6 — Deploy to your live website

Copy files to your web host so they are publicly reachable, for example:

| File in ZIP | Publish as |
|-------------|------------|
| `llms.txt` | `https://yoursite.com/llms.txt` |
| `llms-full.txt` | `https://yoursite.com/llms-full.txt` (optional) |
| `graph.json` | `https://yoursite.com/graph.json` |
| `schema/organization.jsonld` | `/schema/organization.jsonld` or embed on pages |
| `schema/services.jsonld` | `/schema/services.jsonld` |
| `.well-known/agent.json` | `/.well-known/agent.json` |
| `robots.txt.suggestion` | **Merge** into your existing robots.txt (do not overwrite blindly) |
| `sitemap.xml.suggestion` | **Merge** into your sitemap process |

Also keep (or add) normal **JSON-LD** on key HTML pages where your CMS allows it.

Your human website does not need to look different. You are adding **machine layers** beside it.

### Step 7 — Rescan when content changes

When you add services, change contact details, or publish new pages:

1. Open the site.  
2. Click **Rescan website**.  
3. Review **pending** and **needs_edit** items.  
4. Export again and re-deploy updated files.

**Rescan behaviour (important):**

- New discoveries → **pending**  
- Approved items that changed in meaning → **needs_edit** (you re-check)  
- Items from old scans no longer found → **stale**  
- Pure **manual** entries are not discarded just because the crawl missed them  

---

## 7. Screen-by-screen guide

### 7.1 Home — Sites

- List of all websites you manage  
- Counts: total entities, pending review, approved  
- Form to add a new site  

### 7.2 Site overview

- Summary stats  
- **Scan / Rescan**  
- Links to entities and manual entry  
- Export buttons  
- History of scans and previous export downloads  

### 7.3 Scan status

- Live status: queued → running → completed / failed  
- Pages fetched and how many entities were touched  
- Stats blob for debugging  

### 7.4 Entity review

- Filters: status, type, search text  
- Bulk approve/reject  
- Open any row to edit  

### 7.5 Entity editor

- Full control over one knowledge item  
- Quick verify buttons  
- Advanced JSON fields for properties, relationships, evidence  

### 7.6 Manual entry

- Form create  
- Free-text AI convert  

---

## 8. What is in the export package?

| File | Purpose |
|------|---------|
| **llms.txt** | Short, curated Markdown summary for AI systems (about, capabilities, contact, policies) |
| **llms-full.txt** | Longer dump of all included entities for deep use |
| **graph.json** | Full BKBS knowledge graph (IDs, types, properties, relationships, evidence, versions) |
| **schema/organization.jsonld** | schema.org LocalBusiness / Organisation structured data |
| **schema/services.jsonld** | schema.org Service (and related) entries |
| **.well-known/agent.json** | Stub capabilities manifest for emerging agent protocols |
| **robots.txt.suggestion** | Guidance for crawler permissioning |
| **sitemap.xml.suggestion** | Suggested URLs for discovery |
| **README.md** | Deploy notes for your web team |

**Production export** = approved only.  
**Draft export** = approved + pending + needs_edit (for internal QA).

---

## 9. Recommended operating rhythm

| Cadence | Action |
|---------|--------|
| **First week** | Add site → scan → approve identity & top capabilities → export → deploy `llms.txt` + graph |
| **After major site edits** | Rescan → review needs_edit/pending → re-export → re-deploy |
| **Monthly** | Spot-check approved entities against real operations |
| **Anytime** | Manual entry for facts that should be public but are not on the website yet |

Quality beats quantity. Twenty accurate capabilities beat two hundred vague marketing phrases.

---

## 10. Worked example (security integrator)

**Goal:** Make a physical security company agent-ready.

1. Add site `https://your-security-site.example`.  
2. Scan (max pages 40).  
3. Approve:  
   - Business Identity (name, phone, email, area)  
   - Capabilities: CCTV install, Access control, Intrusion  
   - Facilities: Warehouses, Offices, Retail  
   - Policy: Warranty  
4. Manual free text for: response time, brands supported, certifications.  
5. Export approved ZIP.  
6. Deploy `llms.txt` and `graph.json` to the live domain.  
7. Later: when a new “Fire detection” page goes live, **Rescan**, approve the new capability, re-export.

An agent can then match:

> Install CCTV · Durban · warehouse · 24h response · certified  

…to your approved capability, with evidence URLs.

---

## 11. FAQ

**Do I replace my website with this app?**  
No. The app is a **studio** for building machine layers. Your public HTML site stays your human face.

**Will this rank me higher on Google by itself?**  
No silver bullet. Accurate structured data and clear facts help machines; traditional SEO still applies. The main value is **agent-ready truth**, not a guaranteed ranking boost.

**Is AI required?**  
No. Heuristic extraction works without a key. AI makes extraction richer and free-text conversion smarter.

**Can I use this for multiple businesses?**  
Yes. Create multiple **sites**. Each has its own entities, scans, and exports.

**What if the scanner misses pages?**  
Increase **max pages**, ensure pages are linked or listed in sitemap, and add missing facts via **Manual entry**.

**What if extraction invents something?**  
Reject it. Never approve unverified claims. Evidence URLs should support every important fact.

**Who should approve entities?**  
Someone who knows the business (owner, operations lead), not only SEO copy.

**Is my data private?**  
Data is stored in the app’s local database (`data/` folder) on the machine running the converter. Page content is sent to the AI API only when LLM extraction is enabled. Do not paste confidential customer data into free-text conversion if it must not leave your systems.

---

## 12. Troubleshooting

| Symptom | What to try |
|---------|-------------|
| “No XAI_API_KEY” | Expected without a key. Add key to `.env` and restart for AI. |
| Scan **failed** | Check URL is reachable; try in a browser; for localhost set `BKBS_ALLOW_PRIVATE_URLS=1`. |
| Zero or few entities | Site may be image-heavy or JS-only; add content via Manual entry; enable AI; check max pages. |
| Export ZIP almost empty | Approve entities first (production export ignores pending). |
| Rescan overwrote nothing but marked needs_edit | That is intentional for previously approved items that changed — re-verify and approve again. |
| Cannot open UI | Confirm the server is running on port 8765; open `http://127.0.0.1:8765`. |

---

## 13. Glossary

| Term | Simple definition |
|------|-------------------|
| **BKBS** | Business Knowledge Base Standard — the entity model and publishing pattern this app targets |
| **Entity** | One structured knowledge object (service, capability, person, …) |
| **Evidence** | Link/snippet proving where a fact came from |
| **JSON-LD** | A web format for structured data (used by Google and many tools) |
| **llms.txt** | A community convention: a clean Markdown file AI systems can read first |
| **Knowledge graph** | Network of entities connected by relationships |
| **Agent** | Software that acts on behalf of a user (search, quote, book, compare) |
| **Heuristic extraction** | Rule-based reading of the site without AI |
| **LLM extraction** | AI (Grok) reading page text into entities |

---

## 14. One-page checklist

- [ ] Start the app and open the dashboard  
- [ ] (Optional) Configure `XAI_API_KEY`  
- [ ] Create a **Site** with the correct base URL  
- [ ] Run **Scan** and wait for **completed**  
- [ ] **Approve** identity, top services/capabilities, contact facts  
- [ ] **Reject** fluff and errors  
- [ ] **Manual entry** for missing operational facts  
- [ ] **Export approved** ZIP  
- [ ] Deploy `llms.txt`, `graph.json`, and schema files to production  
- [ ] After website updates: **Rescan → review → re-export → re-deploy**  

---

## 15. Where to get more technical detail

- Developer setup and API: see `README.md` in this project  
- Interactive API docs (when the app is running): **http://127.0.0.1:8765/docs**  

---

*Manifest BKBS Converter treats your business as a living, queryable knowledge graph — not just a collection of web pages. You remain the editor-in-chief of every fact agents are allowed to trust.*
