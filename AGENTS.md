# AGENTS.md — FatturaFacile ERP Backend (`web-api`)

## Stack & Architecture
- **Repo**: `FatturaFacile/web-api` (Enterprise ERP REST API under `/api/v1/`).
- **Stack**: PHP `^8.6` (strict types), Laravel `^13.17`, Pest `^5.3`, PHPUnit `^13.4`, Larastan/PHPStan (level 5 + baseline), Laravel Pint, Vite `^8.0`, TailwindCSS `^4.0`.
- **Modular Monolith + Clean Architecture / DDD**:
  - `app/`: core services, HTTP middleware, console commands, base contracts/models.
  - `modules/`: numbered domain modules (`02_CRM`, `05_Invoicing`, `06_SDI`, `13_Procurement`, `14_Inventory`, `18_HumanResources`, `22_GeneralLedger`, `24_Treasury`, `30_Manufacturing`, etc.).
  - `packages/`: reusable foundation packages (`Enterprise\*`: `enterprise-foundation`, `audit-ledger`, `ai-gateway`, `event-bus`, `multi-tenant`, `feature-flags`, `openapi`).
  - `routes/`: modular route files (`api.php`, `web.php`, `console.php`).
- **Boundaries** (enforced by `tests/Architecture`):
  - `packages/` (`Enterprise\*`) must NEVER depend on `Modules\*`; foundation contracts must be interfaces; `BaseEnterpriseModel` is abstract.
  - Declare every OpenAPI `OA\(Get|Post|Put|Patch|Delete)` HTTP method + path exactly once (`OpenApiOperationUniquenessTest`).
  - Responses use `{success, message, data, meta}` envelope; errors follow RFC 7807 Problem Details.

## Coding, Typing & Security
- **Strict Types**: Start every PHP file (`App`, `Modules`, `Enterprise\*`) with `declare(strict_types=1);`.
- **Explicit Typing**: Declare explicit parameter and return types (including `: void`), readonly constructor property promotion, typed enums, `match()`, and PHPDoc array/generic shapes; never add unjustified `@phpstan-ignore`.
- **No Debug Calls**: Never leave `dd`, `dump`, `ray`, `var_dump`, `print_r`, `die`, or `exit` in `App`/`Modules`.
- **Thin Controllers**: Orchestrate actions only; NEVER put `Illuminate\Support\Facades\DB` calls, domain queries, business logic, or inline `$request->validate()` in controllers.
- **Validation & Responses**: Every `POST`/`PUT`/`PATCH` endpoint MUST use a dedicated `FormRequest` (auth in `authorize()` or Policies/Gates); NEVER return raw Eloquent models — always use a dedicated `JsonResource`.
- **Multi-Tenancy**: Use `BelongsToTenant` (`TenantScope`), scope all queries and unique rules (`Rule::unique('table')->where('tenant_id', $tenantId)`) to the active tenant ID, and never leak cross-tenant data (`tenant_id IS NULL` rows are global read-only).
- **Transactions & Audit**: Wrap multi-table writes, child-line inserts, and ledger postings in concise `DB::transaction()` (no external HTTP calls or file uploads inside transactions); keep `SoftDeletes` and `Enterprise\AuditLedger` on business-critical entities.

## Localization (`it`, `de`, `fr`, `en`)
- Maintain strict 4-locale parity (`it`, `de`, `fr`, `en`) on every user-facing change; never leave missing keys or partial locales.
- **Backend dictionaries**: Update all 4 `lang/{it,de,fr,en}/*.php` files together.
- **API-served frontend dictionaries**: Update all 4 locales in `database/seeders/Translations/*` and register in `FrontendTranslationSeeder` (zero key overlap with `TranslationSeeder`).

## Database & Testing Policy (Strict)
- **Local Dev & Tests (SQLite ONLY)**: Always use SQLite (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_FOREIGN_KEYS=true`). NEVER require or connect to PostgreSQL locally.
- **Dual Compatibility (SQLite + PostgreSQL)**: PostgreSQL is reserved for CI & production; ALL migrations, seeders, and queries MUST be 100% dialect-agnostic (NO `ILIKE` — use `LOWER()`, NO `::jsonb`, NO `DISTINCT ON`, NO SQLite-only pragma hacks in migrations).
- **Test Runner**: Run `composer test` (parallel `php artisan test --parallel`) or `php artisan test` / `composer test:serial` (sequential debugging). Tests extend `Tests\TestCase` with `LazilyRefreshDatabase`/`RefreshDatabase` + `TestingSeeder::class` (keep heavy demo/translation seeders out of `TestingSeeder`).
- **SQLite Schema Dump**: Whenever adding or modifying migrations, regenerate `database/schema/sqlite-schema.sql` WITHOUT `--prune` (preserve PHP migrations for PostgreSQL):
  - `php artisan migrate:fresh --database=sqlite --force && php artisan schema:dump --database=sqlite`
- **Clean Local Test DB Init**: `php artisan migrate:fresh --seed --env=testing`.

## Fast Feedback Commands (iterate with these; full gate only before the final merge)
- While iterating: `composer lint:dirty` (Pint on changed files), `composer analyse:dirty` (PHPStan on changed files, warm cache), `composer test:module -- tests/Feature/<Module>` (single module), `composer test:changed` (parallel, only tests touched in git), `composer test:fast` (parallel Unit + Architecture).
- Tiers: `test:unit` (Unit), `test:arch` (Architecture), `test:fast` (both, parallel), `test` (full parallel suite). `phpunit.xml` defines `Unit`, `Architecture`, `Feature` suites.
- Before the final local merge: `composer quality:full` (Pint check, full PHPStan, architecture tests, full parallel suite).
- Git hooks (`.githooks`, enabled by `composer install`): `pre-commit` = Pint + PHPStan on staged files only; `pre-push` = Pint check, full PHPStan, parallel suite (skip with `SKIP_PRE_PUSH=1` only in emergencies).
- Note: `pest --parallel` accepts a single path; use `--testsuite=A,B` for several.
- **Local CPU budget (many agents share one 16-thread machine)**: every `composer test*` script runs through `scripts/dev/locked.php` → `scripts/test-lock.ps1` (machine-wide named mutex `Global\FatturaFacileLocalTests`). A second run queues instead of thrashing the cores. Workers are capped: `--processes=2` for full/unit/fast runs, `--processes=2` for `test:module` and `test:changed`. Never call `artisan test --parallel` / `pest --parallel` directly without `--processes`; `TEST_LOCK_BYPASS=1` skips the lock (single-process run on an idle machine only), `TEST_LOCK_TIMEOUT_MIN` changes the 90 min wait. CI (`CI=true`) never locks.
- **Worktree rule (`.claude/worktrees/*`)**: run ONLY `composer lint:dirty`, `composer analyse:dirty` (or `phpstan`), `composer test:unit`, and the touched module's tests: `composer test:module -- tests/Feature/<Module>` (= `--parallel --processes=2`). NEVER run the full suite inside a worktree. The full suite runs once, on the primary checkout after merging into `dev` (`composer quality:full`), or in GitHub Actions CI.
- **Workstation PHP**: enable opcache for the CLI in `php.ini` (`opcache.enable_cli=1`, `opcache.memory_consumption=512`, `opcache.interned_strings_buffer=64`, `opcache.max_accelerated_files=100000`, `opcache.file_cache=<writable dir>`). Without `file_cache` each worker process recompiles the whole framework and `modules/` (~4 s per boot with it off). Do not set `validate_timestamps=0`.
- **SQLite schema dump**: whenever a migration is added or changed, regenerate it without `--prune` (see Database & Testing Policy) so each worker loads the schema instead of replaying every migration.

## Git Workflow (`dev` Only — ZERO PRs, ZERO Leftovers)
- **Direct `dev` Merge**: Integrate all work directly into `dev` via local merge from the primary repo root:
  - `git checkout dev && git pull --rebase origin dev && git merge --no-ff <branch>`
- **FORBIDDEN**: NEVER commit, merge, or push to `main`. NEVER create Pull Requests (`gh pr create` / `gh pr`).
- **Worktrees**: For complex work, commit and verify on a feature branch/worktree, then merge into `dev` from the primary repository root.
- **Autonomous Conflict Resolution**: Resolve all merge conflicts autonomously, preserving Clean Architecture, PHP strict types, tenant isolation, SQLite/PostgreSQL portability, and all 4 locales (`it`, `de`, `fr`, `en`).
- **Mandatory End-of-Task Cleanup**: After pushing `dev`, ALWAYS delete merged local & remote feature branches (`git branch -D <branch>`, `git push origin --delete <branch>`), remove linked git worktrees (`git worktree remove --force <path> && git worktree prune`), and remove any temporary/debug files created during the session so `git status` is completely clean.

## Quality Gates (Mandatory Before Push)
- **NEVER push to `origin dev` unless ALL quality gates pass with zero warnings/errors**:
  - Format changed files: `./vendor/bin/pint --dirty --format agent`
  - Format check: `./vendor/bin/pint --test`
  - Static analysis (level 5 + baseline): `./vendor/bin/phpstan analyse --memory-limit=2G`
  - Architecture boundary tests: `./vendor/bin/pest tests/Architecture --no-coverage`
  - Automated tests (SQLite `:memory:`, `DB_FOREIGN_KEYS=true`): `php artisan test` (or parallel `composer test`)
- **Full Pre-Push Gate**: `./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=2G && php artisan test` (use `composer test` for parallel runs).
- **Auxiliary Checks (when applicable)**: `php artisan config:clear`; `php artisan l5-swagger:generate` (after route/OpenAPI changes); `npm run build` (after Vite/Tailwind changes).
