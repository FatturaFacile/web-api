# FatturaFacile ERP — Backend (`web-api`) AI Instructions

Laravel 13 / PHP 8.5 enterprise ERP REST API for Italy (IT) and Switzerland (CH).

---

## 1. Architecture & Coding Standards

- **Architecture**: Clean Architecture / Domain-Driven Design (DDD).
  - Presentation: `app/Http/Controllers`, `app/Http/Requests` (FormRequests), `app/Http/Resources` (JsonResources).
  - Bounded Contexts: `modules/<number>_<Name>/` (PSR-4 autoloaded).
  - Platform Capabilities: `packages/` (`Enterprise\` namespaces: foundation, multi-tenant, audit-ledger, event-bus, etc.).
- **Multi-Tenancy**:
  - Request-scoped resolution via `App\Http\Middleware\IdentifyTenant` (reads `X-Tenant-Id` header).
  - Tenant-scoped models use `Enterprise\MultiTenant\Concerns\BelongsToTenant`.
  - Global lookup models implement `AllowsGlobalRecords` where `tenant_id` is null.
  - Foreign keys and IDs use UUIDs (`$table->uuid('id')->primary()`, `$table->foreignUuid('tenant_id')`).
  - Cross-tenant isolation must be strictly preserved; unique constraints must scope to tenant:
    `Rule::unique('table', 'column')->where('tenant_id', $tenantId)`.
- **PHP Guidelines**:
  - Strict typing enabled on every file: `declare(strict_types=1);`.
  - PHP 8 constructor property promotion.
  - Explicit parameter and return types on all functions/methods.
  - Native typed Enums (TitleCase keys).
  - PHPDoc array shapes (`array<string, mixed>`, `array{name: string, amount: float}`).
  - Curly braces on all control structures.
- **OpenAPI & API Contracts**:
  - Document all endpoints using L5-Swagger / Scramble annotations/attributes.
  - API responses follow standard envelope (`success`, `message`, `data`) and RFC 7807 Problem Details on error.
- **Language**: All code, comments, PHPDoc, commit messages, and tests in English. User-facing strings live in translation seeders/files.

---

## 2. Quality Gates, Linting & Static Analysis

```bash
# Code Style & Formatting (Laravel Pint)
vendor/bin/pint --dirty --format agent
# Check formatting in CI
vendor/bin/pint --test

# Static Analysis (PHPStan / Larastan)
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
# CI Quality check (Level 8)
./vendor/bin/phpstan analyse --level=8 --memory-limit=1G --no-progress

# Architecture Boundary Invariants (Pest)
./vendor/bin/pest tests/Architecture --no-coverage

# Dependency Security Audit
composer audit --locked
```

---

## 3. Database & Testing Policy (Mandatory)

- **Always use a fresh SQLite database for local development and tests.** Never require or connect to PostgreSQL locally (PostgreSQL is reserved exclusively for CI pipelines and Production).
- **Automated Test Execution (`web-api`):**
  - Run tests via `php artisan test` (which uses SQLite `:memory:` via `phpunit.xml` with `LazilyRefreshDatabase` / `RefreshDatabase` and `$seed = true` in `Tests\TestCase`).
  - If verifying manually on a local file DB before running tests, initialize a clean SQLite database with:
    `php artisan migrate:fresh --seed --env=testing`
- Ensure all new migrations and seeders are 100% compatible with both SQLite and PostgreSQL (avoid raw PostgreSQL-only SQL like `ILIKE`, `::jsonb`, or `DISTINCT ON`).

### Test Commands:
```bash
# Run all tests using configured SQLite :memory:
php artisan test

# Run specific test file or filter
php artisan test tests/Feature/SomeTest.php
php artisan test --filter="test_name"

# Run tests via Pest directly
./vendor/bin/pest tests/Feature/SomeTest.php --no-coverage
```
