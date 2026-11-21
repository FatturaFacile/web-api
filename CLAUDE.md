# CLAUDE.md

@AGENTS.md

## Claude Code Execution Instructions
- **Single Source of Truth**: Strictly adhere to all rules, multi-tenancy requirements, and architectural standards documented in `@AGENTS.md`.
- **Database Rules**: Always use SQLite (`:memory:`) for local testing. Never attempt to boot or connect to a local PostgreSQL instance. After adding or modifying migrations, regenerate `database/schema/sqlite-schema.sql` with `php artisan schema:dump --database=sqlite` (never use `--prune`).
- **Worktree Discipline**: Perform complex features and refactorings in dedicated git worktrees. Keep changes clean and focused.
- **Mandatory Quality Gates**: Before completing any task or presenting finished work, execute and verify:
  1. `vendor/bin/pint --dirty --format agent`
  2. `vendor/bin/phpstan analyse --memory-limit=2G`
  3. `composer test` (runs `php artisan test --parallel` on SQLite `:memory:`)

## Git Workflow & Direct Merge Policy (STRICT)
- **Target Branch (`dev`):** Integrate all work directly into `dev` via local merge. **NEVER commit, merge, or push directly to `main`**, and **NEVER create Pull Requests (`gh pr create` / `gh pr`).**
- **Worktree Handling:** If running inside a linked git worktree, finish commits and tests on the feature branch, then run the merge on `dev` from the primary repository root (`git checkout dev && git pull --rebase origin dev && git merge --no-ff <branch>`).
- **Conflict Resolution:** Resolve any merge conflict markers autonomously, preserving Standalone Components, Signals, strict TypeScript types, and all 4 i18n JSON files (`it`, `de`, `fr`, `en`).
- **Pre-Push Quality Gate:** Before `git push origin dev`, execute and ensure zero errors on:
  - `pnpm run lint`
  - `pnpm run build`
  - `pnpm test -- --watch=false`
- **Cleanup:** Delete merged local/remote feature branches and remove temporary worktrees (`git worktree remove` + `git branch -D <branch>`).