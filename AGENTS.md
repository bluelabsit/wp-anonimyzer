# Repository Guidelines

## Project Structure & Module Organization

This is a focused PHP CLI utility. `shop-anonymizer.php` contains the application: terminal helpers, interactive configuration, table rules, temporary-schema processing, verification, artifact creation, and cleanup. Keep new behavior near the numbered section it extends and reusable logic in the helper area. `README.md` is the operator guide and security contract; update it whenever options, prerequisites, outputs, or guarantees change. There is no separate `src/`, `tests/`, or assets directory.

## Build, Test, and Development Commands

No dependency installation or build step is required. Use PHP 7.4 or newer.

```bash
php -l shop-anonymizer.php
php shop-anonymizer.php --help
php shop-anonymizer.php --path=/var/www/shop --dry-run
```

The first command performs the required syntax check. The second checks CLI option rendering. The dry run exercises planning without modifying the source database; use a disposable WordPress shop and dedicated output directory because it can write local configuration.

## Coding Style & Naming Conventions

Preserve `declare(strict_types=1)` and PHP 7.4 compatibility. Follow the existing four-space indentation, brace placement, and compact guard clauses. Use `camelCase` for functions and variables, `UPPER_SNAKE_CASE` for constants, and long CLI options such as `--output-dir`. Keep numbered phase banners and comments explaining safety decisions. Escape shell arguments with `escapeshellarg()` and SQL identifiers using existing helpers; never place credentials on a command line.

## Testing Guidelines

There is no automated test framework or coverage threshold yet. Every change must pass `php -l`, `--help`, and a representative dry run. For database-affecting changes, test only against disposable fixtures and verify cleanup on both success and failure. Confirm that residual-data checks, the manifest, and checksum still match the documented guarantees. Name future tests after observable behavior, for example `CleanupOnVerificationFailureTest.php`.

## Commit & Pull Request Guidelines

History is small, but it establishes concise imperative subjects and a Conventional Commit-style `feat:` prefix. Continue with scoped subjects such as `fix: guard temporary schema cleanup` or `docs: clarify seed handling`. Pull requests should explain behavior and data-safety impact, list commands run, link the relevant issue, and update `README.md`. Include terminal output or screenshots when the wizard flow changes; never attach real dumps, credentials, seeds, or customer data.

## Security & Configuration

Treat `.anon-seed`, `run-config.json`, database dumps, manifests, and credentials as sensitive. Do not commit generated exports. Preserve the temporary-schema prefix guard, read-only treatment of the production database, restrictive file permissions, verification gate, and shutdown cleanup path.
