# Roadmap — Manifest BKBS Converter

High-level direction. Dates are indicative, not promises.  
Track concrete work in [GitHub Issues](https://github.com/brandonjoubert/Manifest---BKBS-Converter/issues).

## Now (v0.1.x) — solid open-source foundation

- [x] Python edition: scan, LLM settings, verify, export, live publish  
- [x] PHP edition for non-Python shared hosting  
- [x] Install paths: local PC, Python host, PHP host  
- [x] Apache-2.0 license and community docs  
- [x] CI, issue templates, Dependabot  
- [ ] Screenshot / short demo in README  
- [ ] GitHub Release assets for each tag  

## Next — product depth

- [ ] Optional simple auth for admin UI (password / basic auth)  
- [ ] Scheduled rescan (cron-friendly)  
- [ ] Richer entity editor + relationship UI  
- [ ] Docker image + `docker compose up`  
- [ ] Example fixture site under `examples/` for demos and tests  
- [ ] PHP free-text → multi-entity AI convert (parity with Python)  

## Later — platform

- [ ] Public read API for approved graph (per site)  
- [ ] Multi-user / multi-tenant workspaces  
- [ ] Plugin / vertical packs (security, legal, clinics, …)  
- [ ] Hosted “Manifest Cloud” (optional paid run) while core stays open  

## Non-goals (for now)

- Replacing the human HTML website CMS  
- Guaranteeing search-engine ranking from `llms.txt` alone  
- Supporting every proprietary LLM API that is not OpenAI-compatible  

## How to influence the roadmap

1. Open a [feature request](https://github.com/brandonjoubert/Manifest---BKBS-Converter/issues/new/choose)  
2. Describe the **problem** and environment (Python vs PHP host)  
3. Upvote / comment on existing issues  

Maintainers prioritize: correctness of published knowledge, install simplicity, and maintainability of both editions.
