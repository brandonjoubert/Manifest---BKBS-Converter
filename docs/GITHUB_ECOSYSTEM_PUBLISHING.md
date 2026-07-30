# How to present this monorepo as an ecosystem on GitHub

Practical checklist for maintainers. Complements [ECOSYSTEM.md](../ECOSYSTEM.md).

## 1. Repo “About” box

**Description (example):**

```text
Manifest BKBS ecosystem — agent-ready business knowledge tools for Python, PHP hosts, and WordPress
```

**Homepage:** repo URL or future docs site  

**Topics (suggested):**

```text
bkbs
ai-agents
llms-txt
knowledge-graph
schema-org
fastapi
php
wordpress-plugin
open-source
monorepo
```

## 2. README as front door

Root `README.md` should:

- Lead with **mission + product grid**  
- Give WordPress its **own section** and zip path  
- Link Python/PHP Host to `INSTALL.md` without burying WP  

Avoid: “Also we have a wordpress folder at the bottom.”

## 3. Releases that look multi-product

### Ecosystem milestone (default)

**Tag:** `v0.1.0`  
**Title:** `Manifest BKBS Ecosystem v0.1.0`  
**Assets:**

- `installers/php-host/bkbs-php-edition.zip`  
- `wordpress-plugin/manifest-bkbs-converter.zip`  

**Notes structure:**

```markdown
## Products in this release
- Python Converter — …
- PHP Host Converter — …
- WordPress Plugin — …

## Downloads
- WordPress: manifest-bkbs-converter.zip
- PHP Host: bkbs-php-edition.zip
```

### Product-focused release (optional, clearer for WP users)

**Tag:** `wordpress-plugin-v0.1.0`  
**Title:** `WordPress Plugin v0.1.0`  
**Asset:** only the WP zip  
**Body first line:** *Part of the [Manifest BKBS ecosystem](../blob/main/ECOSYSTEM.md).*

## 4. Labels for issues

Create labels:

| Label | Use |
|-------|-----|
| `product:python` | `app/` |
| `product:php-host` | `php/` |
| `product:wordpress` | `wordpress-plugin/` |
| `product:docs` | Shared docs |
| `product:ci` | Tooling |

Ask reporters to pick a product in the issue form (update templates when ready).

## 5. Do you need a second repository?

| Situation | Recommendation |
|-----------|----------------|
| Early stage, small team | **One monorepo** (this repo) |
| Heavy WordPress.org / agency distribution | Later: **sibling repo** that mirrors plugin + points to monorepo |
| Brand as company platform | GitHub **Organization** + monorepo + product repos |

## 6. Commands: attach both zips to a release

```bash
cd /path/to/Manifest---BKBS-Converter

# refresh packages
./installers/php-host/package.sh
(cd wordpress-plugin && zip -qr manifest-bkbs-converter.zip manifest-bkbs-converter)

gh release create v0.1.1 \
  installers/php-host/bkbs-php-edition.zip \
  wordpress-plugin/manifest-bkbs-converter.zip \
  --title "Manifest BKBS Ecosystem v0.1.1" \
  --notes-file CHANGELOG.md
```

## 7. What not to do

- Don’t merge the WP plugin into `php/` “to simplify”  
- Don’t require WordPress users to read Python install docs first  
- Don’t leave the only WP entry as a buried path with no product name  
