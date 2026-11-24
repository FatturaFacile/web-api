# Antigravity Rules
@AGENTS.md
- Follow [AGENTS.md](../AGENTS.md) as the single source of truth for all architecture, coding, i18n (`it`, `de`, `fr`, `en`), DB, git, and quality-gate rules.
- Enforce SQLite `:memory:` (`DB_FOREIGN_KEYS=true`) for all local runs and automated tests.
- Run Pint, PHPStan (`--memory-limit=2G`), and tests with zero errors before finalizing or pushing to `dev`.
