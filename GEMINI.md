# GEMINI.md
@AGENTS.md
- Follow [AGENTS.md](AGENTS.md) as the single source of truth for all architecture, coding, i18n (`it`, `de`, `fr`, `en`), DB, git, and quality-gate rules.
- Run all local commands and tests strictly against SQLite `:memory:` (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_FOREIGN_KEYS=true`).
- Never finalize or push to `origin dev` until Pint, PHPStan (`--memory-limit=2G`), and tests pass with zero warnings/errors.
