# Security policy

## Supported versions

Security fixes are applied on a best-effort basis to the latest `main` branch.

## Reporting a vulnerability

Please **do not** open a public GitHub issue for security-sensitive reports.

Instead:

1. Use GitHub **Security Advisories** on this repository (preferred), or  
2. Contact the maintainers privately via the email listed on the GitHub profile / org.

Include:

- Description of the issue  
- Steps to reproduce  
- Impact assessment (if known)  
- Suggested fix (optional)

## What is in scope

- Remote code execution, path traversal into unintended directories
- Exposure of API keys stored by the app
- Authentication/authorization bypass (when auth is added)
- Unsafe defaults that leak `data/` or `config.php` on shared hosts

## What is out of scope

- Denial of service via scanning very large sites (use `max_pages` limits)
- Issues that require already having local filesystem access as the app user
- Third-party LLM provider outages or model quality

## Hardening tips for operators

- Keep admin UI off the public internet or behind auth / IP allowlist  
- Never commit `.env` or `php/config.php`  
- Ensure `data/` is not web-accessible  
- Rotate LLM API keys if they may have leaked  
