# CLAUDE.md
@AGENTS.md
- Follow [AGENTS.md](AGENTS.md) as the single source of truth for all architecture, coding, i18n (`it`, `de`, `fr`, `en`), DB, git, and quality-gate rules.
- Use dedicated git worktrees for complex features/refactors; finish commits and tests on the branch, then merge into `dev` from the primary repo root.
- Delete merged branches/worktrees (`git worktree remove --force <path> && git worktree prune`) and temp files before finishing.
