<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Raw-table builders of the KPI dashboard tests: the KPIs aggregate the ledger and the operational tables, so the tests
 * write those rows directly with known amounts. Foreign keys are deferred (the transaction of the test is rolled back).
 */
function kpiDeferForeignKeys(): void
{
    DB::statement('PRAGMA defer_foreign_keys = ON');
}

function kpiTenant(string $currency = 'CHF', string $country = 'CH'): Tenant
{
    return Tenant::create([
        'name' => 'Kpi '.Str::random(5),
        'legal_name' => 'Kpi Srl',
        'vat_number' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
        'fiscal_code' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
        'country' => $country,
        'default_currency' => $currency,
    ]);
}

/**
 * Acts as a member of the tenant with the role and the membership permissions.
 *
 * @param  list<string>  $permissions
 */
function kpiActAs(Tenant $tenant, string $role = 'owner', array $permissions = []): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode($permissions)]);

    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', (string) $tenant->id);

    return $user;
}

function kpiAccount(Tenant $tenant, string $code, string $accountType, ?string $reportingClass = null): string
{
    $id = (string) Str::uuid();
    DB::table('chart_of_accounts')->insert([
        'id' => $id, 'tenant_id' => $tenant->id, 'code' => $code, 'account_code' => $code, 'name' => "Account {$code}", 'type' => $accountType,
        'account_type' => $accountType, 'reporting_class' => $reportingClass, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function kpiCompany(Tenant $tenant, string $code, string $currency = 'CHF'): string
{
    $id = (string) Str::uuid();
    DB::table('companies')->insert([
        'id' => $id, 'tenant_id' => $tenant->id, 'code' => $code, 'legal_name' => "Company {$code}", 'country' => $currency === 'CHF' ? 'CH' : 'IT',
        'currency' => $currency, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

/**
 * A posted journal entry: each line is [account id, debit, credit, cost center id|null].
 *
 * @param  list<array{0: string, 1: float|int, 2: float|int, 3?: string|null}>  $lines
 */
function kpiPost(Tenant $tenant, string $date, array $lines, ?string $companyId = null): string
{
    $entry = (string) Str::uuid();
    $total = array_sum(array_map(static fn (array $line): float => (float) $line[1], $lines));
    DB::table('journal_entries')->insert([
        'id' => $entry, 'tenant_id' => $tenant->id, 'company_id' => $companyId, 'fiscal_period_id' => (string) Str::uuid(), 'entry_number' => 'JE-'.Str::random(8),
        'posting_date' => $date, 'document_date' => $date, 'ledger_type' => 'statutory', 'journal_type' => 'standard', 'description' => 'KPI test posting',
        'status' => 'posted', 'total_amount' => $total, 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ($lines as $index => $line) {
        DB::table('journal_lines')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'journal_entry_id' => $entry, 'posting_date' => $date, 'line_number' => $index + 1, 'account_id' => $line[0],
            'cost_center_id' => $line[3] ?? null, 'description' => 'line', 'debit_amount' => $line[1], 'credit_amount' => $line[2], 'functional_debit' => $line[1],
            'functional_credit' => $line[2], 'currency' => 'CHF', 'exchange_rate' => 1, 'amount_in_functional_currency' => $line[1] - $line[2],
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $entry;
}

function kpiRate(string $source, string $target, string $date, float $rate): void
{
    DB::table('exchange_rates')->insert(['id' => (string) Str::uuid(), 'source' => $source, 'target' => $target, 'rate' => $rate, 'effective_date' => $date, 'created_at' => now(), 'updated_at' => now()]);
}

function kpiContact(Tenant $tenant, string $name = 'Customer AG', string $country = 'CH'): string
{
    $id = (string) Str::uuid();
    DB::table('contacts')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => $name, 'country' => $country, 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function kpiDashInvoice(Tenant $tenant, string $contact, string $date, float $net, array $overrides = []): string
{
    $id = (string) Str::uuid();
    $tax = $overrides['total_tax'] ?? round($net * 0.081, 2);
    DB::table('invoices')->insert([
        'id' => $id, 'tenant_id' => $tenant->id, 'contact_id' => $contact, 'invoice_type' => 'standard', 'invoice_number' => 'INV-'.Str::random(8), 'date' => $date,
        'due_date' => $date, 'currency' => 'CHF', 'exchange_rate' => 1, 'total_net' => $net, 'total_tax' => $tax, 'total_gross' => $net + $tax, 'total_paid' => 0,
        'payment_status' => 'unpaid', 'jurisdiction' => 'CH', 'created_at' => now(), 'updated_at' => now(), ...$overrides,
    ]);

    return $id;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function kpiPassiveInvoice(Tenant $tenant, string $supplier, string $date, float $net, array $overrides = []): string
{
    $id = (string) Str::uuid();
    $tax = $overrides['total_tax'] ?? round($net * 0.081, 2);
    DB::table('passive_invoices')->insert([
        'id' => $id, 'tenant_id' => $tenant->id, 'supplier_id' => $supplier, 'invoice_number' => 'PI-'.Str::random(8), 'date' => $date, 'due_date' => $date,
        'total_amount' => $net + $tax, 'total_net' => $net, 'total_tax' => $tax, 'currency' => 'CHF', 'total_paid' => 0, 'payment_status' => 'unpaid',
        'created_at' => now(), 'updated_at' => now(), ...$overrides,
    ]);

    return $id;
}

/**
 * @param  array<string, string>  $query
 * @return TestResponse<JsonResponse>
 */
function kpiCockpit(object $test, string $role, array $query = []): TestResponse
{
    $query = ['role' => $role, 'from' => '2026-01-01', 'to' => '2026-06-30', 'preset' => 'custom', 'comparison' => 'yoy', 'currency' => 'CHF', ...$query];

    return $test->getJson('/api/v1/kpi-dashboards/cockpit?'.http_build_query($query));
}

/**
 * The widget of the KPI in a cockpit response.
 *
 * @return array<string, mixed>
 */
function kpiWidget(TestResponse $response, string $kpi): array
{
    foreach ($response->json('data.widgets') as $widget) {
        if ($widget['kpi_code'] === $kpi) {
            return $widget;
        }
    }

    throw new RuntimeException("No widget for [{$kpi}] in the cockpit.");
}

/**
 * @param  array<string, mixed>  $overrides
 */
function kpiEmployee(string $tenantId, string $hired, float $fte = 1.0, array $overrides = []): string
{
    $id = (string) Str::uuid();
    $number = 'E'.Str::random(6);
    DB::table('hr_employees')->insert([
        'id' => $id, 'tenant_id' => $tenantId, 'employee_number' => $number, 'first_name' => 'Anna', 'last_name' => $number, 'full_name' => "Anna {$number}",
        'work_email' => "{$number}@example.com", 'hire_date' => $hired, 'fte_ratio' => $fte, 'employment_status' => 'active', 'created_at' => now(), 'updated_at' => now(), ...$overrides,
    ]);

    return $id;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function kpiTimesheet(string $tenantId, string $employee, string $date, float $regular, float $overtime, float $billable, float $rate = 40.0, array $overrides = []): void
{
    DB::table('hr_timesheets')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'employee_id' => $employee, 'timesheet_number' => 'TS-'.Str::random(8), 'work_date' => $date,
        'regular_hours' => $regular, 'overtime_hours' => $overtime, 'total_hours' => $regular + $overtime, 'billable_hours' => $billable, 'hourly_cost_rate' => $rate,
        'total_labor_cost' => ($regular + $overtime) * $rate, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now(), ...$overrides,
    ]);
}
