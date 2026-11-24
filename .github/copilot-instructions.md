# GitHub Copilot Instructions
- Follow [AGENTS.md](../AGENTS.md) as the single source of truth for all architecture, coding, i18n (`it`, `de`, `fr`, `en`), DB, git, and quality-gate rules.
- Enforce `declare(strict_types=1);`, thin controllers (`FormRequest`/`JsonResource`), and strict `tenant_id` scoping on every change.
- Keep migrations/queries 100% SQLite + PostgreSQL portable; run local tests on SQLite `:memory:` only.
- Verify with `./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=2G && php artisan test` (or `composer test`).
