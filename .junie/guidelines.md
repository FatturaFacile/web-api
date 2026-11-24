# JetBrains AI Assistant & Junie Guidelines
- Follow [AGENTS.md](../AGENTS.md) as the single source of truth for all architecture, coding, i18n (`it`, `de`, `fr`, `en`), DB, git, and quality-gate rules.
- Enforce PHP 8.5 `declare(strict_types=1);`, thin controllers (`FormRequest`/`JsonResource`), and strict `tenant_id` isolation.
- Run all local tests on SQLite `:memory:` (`DB_FOREIGN_KEYS=true`); keep SQL/migrations PostgreSQL-portable.
- Verify with `./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=2G && php artisan test`.
