# Antigravity Rules

@AGENTS.md

- Canonical project guidelines, architecture, and verification commands are defined in `AGENTS.md`.
- Enforce SQLite `:memory:` for all local and test executions.
- Run `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan analyse --memory-limit=2G`, and `php artisan test` to verify code quality.
