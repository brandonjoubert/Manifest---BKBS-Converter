=== Manifest BKBS Converter ===
Contributors: manifest-bkbs
Tags: bkbs, ai, llms.txt, schema.org, knowledge-graph, agents, seo
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.1.0
License: Apache-2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0

WordPress edition of Manifest BKBS Converter: scan, human-verify, and publish agent-ready business knowledge (llms.txt, graph.json, schema.org).

== Description ==

**Manifest BKBS Converter for WordPress** is a **standalone** plugin path.

It is separate from the Python edition and the generic PHP shared-hosting edition in the main repository. Those products are not required to use this plugin.

= What it does =

1. Scan this WordPress site (or another URL you add)
2. Extract business knowledge entities (heuristic + optional OpenAI-compatible LLM)
3. Let you **edit before approve**
4. Publish agent-ready layers for the public site:
   - `/llms.txt`
   - `/llms-full.txt`
   - `/graph.json`
   - `/schema/organization.jsonld`
   - `/schema/services.jsonld`
   - `/.well-known/agent.json`

= Why it matters =

Business websites were built for people. AI agents need structured, trustworthy facts. This plugin helps you publish a dual-purpose presence: human HTML + machine knowledge you control.

= Scan findings =

After each scan the dashboard lists origin-audit findings (JSON-LD on page, AIPREF, /llms.txt, agent.json, TDMRep) above raw stats. A first scan before Publish is expected to complete with warnings. The optional "Print JSON-LD in wp_head on homepage" checkbox stays **off by default**. This plugin does not generate TDMRep files.

== Installation ==

1. Upload the `manifest-bkbs-converter` folder to `/wp-content/plugins/`
2. Activate **Manifest BKBS Converter** in Plugins
3. Open **Manifest BKBS** in the wp-admin menu
4. (Optional) Settings → add OpenAI-compatible API key
5. Scan → edit pending entities → approve → Publish live

== Frequently Asked Questions ==

= Does this replace the Python or PHP editions? =

No. It is a **separate WordPress-native** product path for sites that already run WordPress.

= Where are agent files served? =

Via WordPress rewrite rules after you click Publish (and optionally as static files in the WordPress root if writable).

= Will this change my theme? =

No. It only adds admin screens and public machine-layer endpoints/files.

== Changelog ==

= 0.1.x =
* Scan origin-audit findings on the dashboard (Stage 8)
* Existing "Print JSON-LD in wp_head on homepage" remains default off
* TDMRep is detect-only; the plugin does not generate it

= 0.1.0 =
* Initial WordPress plugin edition
* Sites, scan, entity review/edit/approve, LLM settings, live publish

== Upgrade Notice ==

= 0.1.0 =
First release of the WordPress plugin path.
