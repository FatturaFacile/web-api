@AGENTS.md

# Web-API Claude Instructions

Follow `AGENTS.md` in this directory as the single source of truth for architecture, coding standards, commands, and the database/testing policy.

## Key Workflow Rules
- Generate models, migrations, and seeders using `php artisan make:* --no-interaction`.
- Run `vendor/bin/pint --dirty --format agent` before every commit.
- Keep PHPStan clean (0 new errors).
- Never hardcode passwords or secrets in tests; use runtime generation.
- Respect the mandatory SQLite-only local testing policy.
