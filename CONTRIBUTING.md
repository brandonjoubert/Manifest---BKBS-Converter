# Contributing to Manifest BKBS Converter

Thanks for helping improve Manifest BKBS Converter.

## Ways to contribute

- Bug reports and feature ideas (GitHub Issues)
- Documentation improvements
- Fixes and features via pull requests
- Testing on real shared hosting (PHP) and Python hosts

## Development setup (Python edition)

```bash
git clone https://github.com/brandonjoubert/Manifest---BKBS-Converter.git
cd Manifest---BKBS-Converter
./installers/local/install.sh   # fails if Stage 0/1/2 smoke fails
source .venv/bin/activate
./run.sh
```

Full quality gates (same as CI / clone verification):

```bash
pytest -q
python scripts/verify_exports.py --edition all
python scripts/stage1_contract_check.py
python scripts/verify_exports_via_resolve.py --edition all
```

See [test-fixtures/README.md](./test-fixtures/README.md) and [README.md](./README.md).

## PHP edition

Source lives in `php/`. After changes, rebuild the upload package:

```bash
./installers/php-host/package.sh
```

Commit the updated `installers/php-host/bkbs-php-edition.zip` when the PHP app changes.  
User-facing PHP install docs: [installers/php-host/README.md](./installers/php-host/README.md).

## WordPress edition

Source: `wordpress-plugin/manifest-bkbs-converter/`. After changes, rebuild:

```bash
cd wordpress-plugin
zip -r manifest-bkbs-converter.zip manifest-bkbs-converter
```

Commit the zip. Docs: [wordpress-plugin/README.md](./wordpress-plugin/README.md).

## Pull request guidelines

1. Keep changes focused and described clearly.
2. Do not commit secrets (`.env`, API keys, `data/*.db`, `php/config.php`).
3. Add or update tests when fixing logic.
4. Update `README.md` / `INSTALL.md` / product READMEs if user-facing install or behavior changes.
5. Keep installer zips in sync when PHP or WordPress product files change.
6. Open a PR against `main` with a short summary of *what* and *why*.

## Code of conduct

Be respectful. See [CODE_OF_CONDUCT.md](./CODE_OF_CONDUCT.md).

## License

By contributing, you agree your contributions are licensed under the same **Apache License 2.0** as this project.
