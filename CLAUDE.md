# CLAUDE.md
@AGENTS.md
- Follow [AGENTS.md](AGENTS.md) as the single source of truth for all architecture, coding, i18n (`it`, `de`, `fr`, `en`), DB, git, and quality-gate rules.
- Use dedicated git worktrees for complex features/refactors; finish commits and tests on the branch, then merge into `dev` from the primary repo root.
- Inside `.claude/worktrees/*` NEVER run the full suite: only `composer lint:dirty`, `composer analyse:dirty`, `composer test:unit` and `composer test:module -- tests/Feature/<Module>` (capped, queued by `scripts/test-lock.ps1`). The full suite runs once on the primary checkout (`composer quality:full`) or in CI. See "Fast Feedback Commands" in AGENTS.md.
- Delete merged branches/worktrees (`git worktree remove --force <path> && git worktree prune`) and temp files before finishing.
