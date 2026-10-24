# FatturaFacile ERP — Engineering & Coding Standards

## 1. PHP 8.3+ & Modern Syntax Guidelines

All PHP files must adhere to strict typing and modern language features:

1. **Strict Types Declaration**: Every PHP file must declare strict types as its very first statement:
   ```php
   <?php

   declare(strict_types=1);
   ```

2. **Constructor Property Promotion**:
   ```php
   public function __construct(
       private readonly TenantContext $tenantContext,
       private readonly QrBillGeneratorService $qrBillGenerator
   ) {}
   ```

3. **Explicit Return Types & Parameter Typing**:
   Always declare explicit return types and parameter types on all functions and methods, including `void`.

4. **Enums & Match Expressions**:
   Use native PHP typed enums and `match()` expressions in place of legacy switch statements or untyped magic strings.

---

## 2. Static Analysis: PHPStan Level 8+ Requirements

The codebase is configured to satisfy PHPStan level 8+ checks:
- No untyped arrays in docblocks: always document array shapes (`array<string, mixed>`, `array{name: string, amount: float}`).
- Explicit null checks on all nullable return values.
- Never suppress type errors using `@phpstan-ignore` without explicit architectural justification.
- Generics must be documented on all Eloquent collections and query builder methods (`Collection<int, TimeEntry>`, `HasMany<Invoice, $this>`).

---

## 3. Automated Code Formatting: Laravel Pint

All code must be formatted using Laravel Pint prior to committing:

```bash
# Format dirty files according to project Pint rules
vendor/bin/pint --dirty --format agent

# Check all application files
vendor/bin/pint --format agent
```

Key style conventions enforced by Pint:
- PSR-12 and Laravel standard spacing rules.
- Fully qualified strict types.
- Ordered imports and unused import removal.
- Trailing commas in multi-line arrays and parameter lists.
- Unified single quote string usage.

---

## 4. Thin Controllers & FormRequest Validation

Controllers must remain thin orchestrators and must **never** contain inline validation logic or raw domain queries:

1. **Dedicated Form Requests**:
   Every mutation (`POST`, `PUT`, `PATCH`) must have a corresponding `FormRequest` class in `app/Http/Requests/`.

2. **Authorization**:
   Evaluate user roles and policies inside `authorize()` or via Gates/Policies.

3. **Multi-Tenant Unique Constraints**:
   Ensure unique validation rules are explicitly scoped to the active tenant ID:
   ```php
   'invoice_number' => [
       'required',
       'string',
       Rule::unique('invoices', 'invoice_number')->where('tenant_id', $tenantId),
   ]
   ```

4. **API Resources (`JsonResource`)**:
   Never return raw Eloquent models directly from controller actions. Always transform entities through dedicated `JsonResource` classes to prevent leaking database internals or sensitive attributes.

---

## 5. Database Transactions & Data Integrity (`DB::transaction`)

Any operation modifying more than one table, creating related child lines, or updating ledger balances **must** be wrapped in a database transaction:

```php
use Illuminate\Support\Facades\DB;

public function createInvoice(array $data): Invoice
{
    return DB::transaction(function () use ($data): Invoice {
        $invoice = new Invoice();
        $invoice->fill($data);
        $invoice->save();

        foreach ($data['items'] as $item) {
            $invoice->items()->create($item);
        }

        return $invoice->load('items');
    });
}
```

Transactional Best Practices:
- Keep transaction blocks concise: do not perform external HTTP calls or file uploads inside `DB::transaction()`.
- Rely on database cascade constraints for foreign keys.
- Preserve soft-deletes (`SoftDeletes`) on all business-critical enterprise models.
