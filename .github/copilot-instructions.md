# GitHub Copilot Instructions

See [AGENTS.md](../AGENTS.md) for full project architecture and coding standards.

## Core Guidelines & Stack Constraints
- **Stack**: PHP 8.5+, Laravel 13+, Pest/PHPUnit, Vite 8.
- **Strict Typing**: All PHP files must start with `declare(strict_types=1);` and use explicit parameter/return types.
- **Architecture**: Modular monolith (`modules/`, `packages/`, `app/`). Keep controllers thin and use dedicated `FormRequest` and `JsonResource` classes.
- **Multi-Tenancy**: All database queries and unique validations must be strictly scoped to the active tenant ID.
- **Local Testing**: Tests strictly run against in-memory SQLite (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`). Never use or require PostgreSQL for local development.
- **Database Compatibility**: Ensure migrations and queries are 100% portable across SQLite and PostgreSQL (avoid driver-specific syntax like `ILIKE` or `::jsonb`).

## Verification Commands
```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=2G
php artisan test
```
