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
cd bkbs-converter
./installers/local/install.sh
source .venv/bin/activate
./run.sh
```

Run tests:

```bash
pytest -q
```

## PHP edition

Source lives in `php/`. After changes, rebuild the upload package:

```bash
./installers/php-host/package.sh
```

Commit the updated `installers/php-host/bkbs-php-edition.zip` when the PHP app changes.

## Pull request guidelines

1. Keep changes focused and described clearly.
2. Do not commit secrets (`.env`, API keys, `data/*.db`, `php/config.php`).
3. Add or update tests when fixing logic.
4. Update `INSTALL.md` / `USER_MANUAL.md` if user-facing behavior changes.
5. Open a PR against `main` with a short summary of *what* and *why*.

## Code of conduct

Be respectful. See [CODE_OF_CONDUCT.md](./CODE_OF_CONDUCT.md).

## License

By contributing, you agree your contributions are licensed under the same **Apache License 2.0** as this project.
