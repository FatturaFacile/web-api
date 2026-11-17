# JetBrains AI Assistant & Junie Guidelines

Refer to canonical rules in [AGENTS.md](../AGENTS.md).

## Quick Reference
- **Stack**: PHP 8.5+ / Laravel 13+ / Pest & PHPUnit
- **Strict Typing**: `declare(strict_types=1);` required on all PHP files.
- **Database & Testing**: Always use SQLite (`:memory:`) for local testing. Run `php artisan test`. Never attempt to start or connect to local PostgreSQL.
- **Portability**: Keep all Eloquent queries and migrations compatible with both SQLite and PostgreSQL.

## Verification Commands
- **Lint / Format**: `vendor/bin/pint --dirty --format agent`
- **Static Analysis**: `vendor/bin/phpstan analyse --memory-limit=2G`
- **Test Suite**: `php artisan test`
