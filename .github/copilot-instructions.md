# Copilot instructions

## Project shape

- This is a PHP 8.5 / Laravel 13 API application. `routes/api.php` defines the `/api/v1` surface; `app/Http` contains controllers, middleware, requests, resources, and API response helpers. Keep controllers as orchestration and put business operations in the existing application services/actions.
- Business capabilities are split between `app/` and bounded contexts under `modules/<number>_<Name>/`. Shared platform capabilities live in `packages/` under the `Enterprise\` namespaces (including foundation, multi-tenancy, audit ledger, event bus, feature flags, AI gateway, and OpenAPI). Composer defines the PSR-4 mappings. Follow the established layout in the specific area being changed; modules may contain both `src/` and layered `Application/`, `Domain/`, `Infrastructure/`, and `Presentation/` directories.
- Cross-domain changes should use existing contracts and domain events/listeners rather than introducing direct dependencies between unrelated modules. `tests/Architecture/` contains executable boundary rules; keep the `Enterprise\` foundation packages independent of `Modules\`.

## Tenant and API invariants

- Tenant context is request-scoped in behavior: `IdentifyTenant` resolves `X-Tenant-Id` (or the authenticated user's first tenant), checks membership, sets `TenantContext`, and clears it after the request. Preserve cleanup and authorization behavior when changing middleware or tenant access.
- Tenant-owned Eloquent models use the shared `Enterprise\MultiTenant` scope/trait. Operational records fail closed when no tenant is active; shared lookup records may include global rows where `tenant_id` is null. Keep validation of tenant-owned references and uniqueness rules scoped to the active tenant.
- Keep API success responses in the `success` / `message` / `data` envelope and errors in the RFC 7807 Problem Details shape using the existing response helpers. For new endpoints, follow the current route grouping, middleware, naming, FormRequest, policy, and JsonResource patterns.
- Fiscal behavior is jurisdiction-specific (notably Italian SDI and Swiss QR-Bill rules). Preserve existing decimal, validation, and rounding behavior and cover changes with the closest domain tests.

## Code conventions

- Follow `CODING_STANDARDS.md`: PHP files use `declare(strict_types=1)`, explicit parameter and return types, constructor property promotion, native typed enums where appropriate, and PHPDoc array shapes/generics.
- Keep mutation validation in FormRequest classes, authorization in policies/Gates, and API serialization in JsonResources. Wrap multi-record/accounting writes in `DB::transaction()`; do not make external calls or upload files inside a transaction.
- Avoid adding dependencies unless the change requires them. Check the installed dependency versions in `composer.lock` / `package.json` before relying on package APIs.

## Build, test, and lint

- Build frontend assets: `npm run build` (Vite).
- Run all tests: `php artisan test --compact` (or `composer test`). Run one file with `php artisan test --compact tests/Feature/MultiTenancyAndInvoicingTest.php`; run one test with `php artisan test --compact --filter="test name"`.
- Run architecture boundary tests: `./vendor/bin/pest tests/Architecture --no-coverage`.
- Format changed PHP files: `vendor/bin/pint --dirty --format agent`. CI checks formatting with `./vendor/bin/pint --test`.
- CI runs tests against PostgreSQL and Redis. Locally, test database settings come from `.env.testing` or environment variables; use the repository's configured test database rather than assuming SQLite.

Keep edits focused, inspect only the relevant code and tests, and prefer concise diffs. Do not introduce third-party dependencies without a concrete need.