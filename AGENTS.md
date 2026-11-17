# AGENTS.md — FatturaFacile ERP Backend (`web-api`)

## Project & Stack Overview
- **Repository**: `FatturaFacile/web-api` (Enterprise ERP backend REST API)
- **Stack**: PHP `^8.5` (strict types), Laravel Framework `^13.17`, Pest PHP `^4.7`, PHPUnit `^12.5`, Vite `^8.0`, TailwindCSS `^4.0`
- **Architectural Pattern**: Modular Monolith + Clean Architecture / DDD:
  - `app/`: Core application services, HTTP middleware, console commands, base contracts, base models.
  - `modules/`: Domain feature modules (e.g., `02_CRM`, `05_Invoicing`, `06_SDI`, `13_Procurement`, `14_Inventory`, `18_HumanResources`, `22_GeneralLedger`, `24_Treasury`, `30_Manufacturing`, etc.).
  - `packages/`: Reusable enterprise foundation packages (`enterprise-foundation`, `audit-ledger`, `ai-gateway`, `event-bus`, `multi-tenant`, `feature-flags`, `openapi`).
  - `routes/`: Modular route definitions (`api.php`, `web.php`, `console.php`).

---

## Coding & Quality Standards
1. **Strict Types**: Every PHP file must declare strict types as its first statement:
   ```php
   <?php

   declare(strict_types=1);
   ```
2. **Explicit Typing**: Enforce explicit parameter types and return types (including `: void`) on all methods and functions.
3. **Constructor Property Promotion**: Use readonly constructor properties where appropriate:
   ```php
   public function __construct(
       private readonly TenantContext $tenantContext,
       private readonly InvoiceRepository $invoices,
   ) {}
   ```
4. **Thin Controllers & FormRequests**:
   - Controllers orchestrate actions; never put domain queries, business logic, or inline `$request->validate()` in controllers.
   - Every mutating endpoint (`POST`, `PUT`, `PATCH`) must use a dedicated `FormRequest` class in `app/Http/Requests/` or module equivalent.
   - Never return raw Eloquent models directly from controller actions; always transform responses via dedicated `JsonResource` classes.
5. **Multi-Tenancy Isolation**:
   - Scope all database operations and unique validation rules to the active tenant ID (`Rule::unique('table')->where('tenant_id', $tenantId)`).
   - Never bypass tenant isolation or leak cross-tenant data.
6. **Data Integrity & Audit Trail**:
   - Wrap multi-table updates, line insertions, or ledger postings in `DB::transaction()`.
   - Keep transactions concise: no external HTTP calls or file uploads inside `DB::transaction()`.
   - Maintain soft deletes (`SoftDeletes`) and audit records (`Enterprise\AuditLedger`) on business-critical entities.

---

## Database & Testing Policy (Strict)
1. **Local Development & Automated Tests (SQLite Only)**:
   - Always use a fresh in-memory or isolated file SQLite database for local development and test execution.
   - Enforce: `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_FOREIGN_KEYS=true`.
   - Never require or connect to PostgreSQL for local test execution or local verification.
2. **Test Runner & Refresh Policy**:
   - Run tests via `php artisan test` (or `./vendor/bin/pest`).
   - Feature and Unit test cases extend `Tests\TestCase`, utilizing `LazilyRefreshDatabase` / `RefreshDatabase` with `$seed = true`.
   - Clean local test DB initialization:
     ```bash
     php artisan migrate:fresh --seed --env=testing
     ```
3. **PostgreSQL Reserved for CI & Production**:
   - PostgreSQL is strictly reserved for CI pipelines (when explicitly configured) and the production server.
   - All migrations, seeders, and queries **must be 100% dialect-agnostic and compatible with both SQLite and PostgreSQL**.
   - Prohibited driver-locked raw SQL: no `ILIKE` (use `LOWER()`), no `::jsonb`, no `DISTINCT ON`, no SQLite-only pragma hacks in migrations.

---

## Exact Verification & Quality Gate Commands

All quality gates must be executed and passing before pushing or finalizing tasks:

| Gate | Command | Notes |
| :--- | :--- | :--- |
| **Code Formatting** | `vendor/bin/pint --dirty --format agent` | Format changed files |
| **Format Check** | `vendor/bin/pint --test` | Verify PSR-12 and Pint standards |
| **Static Analysis** | `vendor/bin/phpstan analyse --memory-limit=2G` | PHPStan level 5 baseline check (zero errors) |
| **Automated Tests** | `php artisan test` | Runs Pest / PHPUnit test suite on SQLite `:memory:` |
| **Clear Config Cache** | `php artisan config:clear` | Ensure clean testing environment |
| **Frontend Assets** | `npm run build` | Vite asset compilation check (when modifying frontend assets) |

### Quick Full Gate Pipeline
```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=2G
php artisan test
```
