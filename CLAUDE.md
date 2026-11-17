# CLAUDE.md

@AGENTS.md

## Claude Code Execution Instructions
- **Single Source of Truth**: Strictly adhere to all rules, multi-tenancy requirements, and architectural standards documented in `@AGENTS.md`.
- **Database Rules**: Always use SQLite (`:memory:`) for local testing. Never attempt to boot or connect to a local PostgreSQL instance.
- **Worktree Discipline**: Perform complex features and refactorings in dedicated git worktrees. Keep changes clean and focused.
- **Mandatory Quality Gates**: Before completing any task or presenting finished work, execute and verify:
  1. `vendor/bin/pint --dirty --format agent`
  2. `vendor/bin/phpstan analyse --memory-limit=2G`
  3. `php artisan test`
