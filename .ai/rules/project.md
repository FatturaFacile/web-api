---
paths:
  - "**"
---

# Working rules for every AI tool (Claude Code, Codex, Gemini/Antigravity, Copilot, Cursor, Junie)

This file is the single source of the cross-tool rules of this repository. The tool entry files
(`CLAUDE.md`, `AGENTS.md`, `.cursorrules`, `.agy/instructions.md`, `.gemini/GEMINI.md`,
`.github/copilot-instructions.md`) only point here: change the rules here, not there.
Architecture, tenant and API invariants, and build/test commands: `.github/copilot-instructions.md`.

## Language

- Write every piece of code in English: identifiers, comments, PHPDoc and JSDoc, commit messages, test names, log and exception messages, and these AI rule files. No Italian (or any other language) in code or comments.
- The only exceptions are user-facing texts, which live as translation keys in the four languages (it, en, de, fr), and domain terms that are legal names (for example SDI, IBAN, AHV, QST).
- Chat replies to the owner follow the language the owner writes in.

## Behaviour

- Fix every error you meet (failing test, 422 payload, type error, broken translation), also when it is outside the request. Never leave a known error behind: if it cannot be fixed now, say so explicitly.
- Read only what you need: signatures and the relevant methods, not whole files. Emit focused diffs, not whole files. No filler phrases and no unsolicited explanations.
- Follow the sibling files of the area you change (structure, naming, annotations). Reuse existing components before writing new ones.
- Never add or change dependencies without approval, except the libraries a task needs when the owner pre-approved them (xlsx, SAML).
- Never write passwords, secrets or tokens as fixed strings in code or tests (`.ai/rules/test-passwords.md`). Never read or print `.env*`, `auth.json` or keys in `certs/`.

## API contract (web-api and web-frontend are one product)

- The API FormRequest is the source of truth of a payload: same field names, enums, `Y-m-d` dates, nullable and boolean types. When you change one side, change the other side and its tests in the same piece of work (frontend in `C:\dev\fattura-facile\web-frontend`).
- Every endpoint is documented in OpenAPI like its sibling controllers (the owner uses Swagger daily).
- Frontend texts are keys seeded by `database/seeders/Translations/{Italian,English,German,French}FrontendTranslations.php`: add every new key to all four languages.

## Tests and tooling

- Run the narrowest tests that cover the change; the full suite is run by the owner. Pest on PostgreSQL: `.claude/run-pest.sh <path>` style runs (set `PEST_DB`); `php artisan test` prints nothing on this machine.
- PHP: `vendor/bin/pint --dirty --format agent` before finishing. Frontend: use `pnpm` only (`pnpm exec vitest run <spec>`, `pnpm exec tsc --noEmit -p tsconfig.app.json`), never npm or yarn.
- Windows + Git Bash: keep paths short, write scripts with the file tools instead of nested quoting, files may be CRLF.

## Git

- Work on `dev` without review (colleague Alessandro opens PRs on `dev`: `git fetch` before pushing). Never push or commit unless the owner asks.
- Agent worktrees live in `C:\dev\fattura-facile\wt-*`: remove them (and any junction to `vendor`) when the work ends.
