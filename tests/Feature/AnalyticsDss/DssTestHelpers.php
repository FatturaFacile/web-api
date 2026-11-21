<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\AnalyticsDss\Infrastructure\Jobs\UpdateAnalyticalMartJob;

/**
 * A company with an open 2026 fiscal calendar, its accounting currency, one member of the role acting on the API and a small
 * chart of accounts whose codes fall in every analytical category of the data mart.
 *
 * @param  list<string>|null  $permissions
 * @return array{tenant: Tenant, user: User, accounts: array<string, string>, center: string, customer: string}
 */
function dssCompany(string $role = 'accountant', string $currency = 'CHF', ?array $permissions = null, bool $actAs = true): array
{
    ['tenant' => $tenant, 'user' => $user] = tfrTenant('Dss '.Str::random(5), $role, $permissions);
    $tenant->forceFill(['default_currency' => $currency])->save();
    fiscalCalendar($tenant, 2026);

    $accounts = [];

    foreach ([
        'cash' => ['10001', 'asset'], 'receivable' => ['11001', 'asset'], 'payable' => ['20001', 'liability'],
        'revenue' => ['90001', 'revenue'], 'cogs' => ['40001', 'expense'], 'opex' => ['65001', 'expense'],
    ] as $key => [$code, $type]) {
        $accounts[$key] = (string) Str::uuid();
        DB::table('chart_of_accounts')->insert(['id' => $accounts[$key], 'tenant_id' => $tenant->id, 'code' => $code, 'name' => "Account {$code}", 'type' => $type, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    $center = (string) Str::uuid();
    DB::table('cost_centers')->insert(['id' => $center, 'tenant_id' => $tenant->id, 'code' => 'CC1', 'name' => 'Werkstatt', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $customer = dssContact((string) $tenant->id, 'Kunde AG');

    if ($actAs) {
        Sanctum::actingAs($user);
        test()->withHeader('X-Tenant-Id', (string) $tenant->id);
    }

    return ['tenant' => $tenant, 'user' => $user, 'accounts' => $accounts, 'center' => $center, 'customer' => $customer];
}

function dssContact(string $tenantId, string $name, string $type = 'customer'): string
{
    $id = (string) Str::uuid();
    DB::table('contacts')->insert(['id' => $id, 'tenant_id' => $tenantId, 'type' => $type, 'company_name' => $name, 'country' => 'CH', 'created_at' => '2025-11-03 10:00:00', 'updated_at' => now()]);

    return $id;
}

/**
 * Stores a ledger entry with its lines and projects it into the mart through the queued job (the sync queue of the tests).
 *
 * @param  list<array{account: string, debit?: float, credit?: float, center?: string|null, partner?: string|null, currency?: string, rate?: float}>  $lines
 */
function dssEntry(Tenant $tenant, string $date, array $lines, string $status = 'posted', ?string $companyId = null, bool $project = true): string
{
    $period = DB::table('fiscal_periods')->where('tenant_id', $tenant->id)->where('start_date', '<=', $date)->where('end_date', '>=', $date)->value('id');
    $entryId = (string) Str::uuid();
    $debit = array_sum(array_map(static fn (array $l): float => (float) ($l['debit'] ?? 0.0), $lines));

    DB::table('journal_entries')->insert([
        'id' => $entryId, 'tenant_id' => $tenant->id, 'company_id' => $companyId, 'fiscal_period_id' => $period, 'entry_number' => 'T-'.Str::upper(Str::random(8)),
        'posting_date' => $date, 'document_date' => $date, 'description' => 'Test entry', 'status' => $status, 'total_amount' => $debit, 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ($lines as $index => $line) {
        $rate = (float) ($line['rate'] ?? 1.0);
        $d = (float) ($line['debit'] ?? 0.0);
        $c = (float) ($line['credit'] ?? 0.0);

        DB::table('journal_lines')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'journal_entry_id' => $entryId, 'posting_date' => $date, 'line_number' => $index + 1, 'account_id' => $line['account'],
            'cost_center_id' => $line['center'] ?? null, 'partner_type' => isset($line['partner']) ? 'customer' : null, 'partner_id' => $line['partner'] ?? null, 'description' => 'Line',
            'debit_amount' => $d, 'credit_amount' => $c, 'currency' => $line['currency'] ?? (string) $tenant->default_currency, 'exchange_rate' => $rate,
            'functional_debit' => round($d * $rate, 4), 'functional_credit' => round($c * $rate, 4), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    if ($project) {
        UpdateAnalyticalMartJob::dispatchSync((string) $tenant->id, UpdateAnalyticalMartJob::SourceJournalEntry, $entryId);
    }

    return $entryId;
}

/**
 * The standard trading scenario of March 2026 (revenue 1000, COGS 400, opex 200, collection 700) used by the endpoint tests.
 *
 * @param  array{tenant: Tenant, accounts: array<string, string>, center: string, customer: string}  $company
 */
function dssTradingMonth(array $company): void
{
    $a = $company['accounts'];
    $tenant = $company['tenant'];

    dssEntry($tenant, '2026-03-10', [['account' => $a['receivable'], 'debit' => 1000.0, 'partner' => $company['customer']], ['account' => $a['revenue'], 'credit' => 1000.0, 'center' => $company['center']]]);
    dssEntry($tenant, '2026-03-12', [['account' => $a['cogs'], 'debit' => 400.0], ['account' => $a['payable'], 'credit' => 400.0]]);
    dssEntry($tenant, '2026-03-15', [['account' => $a['opex'], 'debit' => 200.0], ['account' => $a['cash'], 'credit' => 200.0]]);
    dssEntry($tenant, '2026-03-20', [['account' => $a['cash'], 'debit' => 700.0], ['account' => $a['receivable'], 'credit' => 700.0, 'partner' => $company['customer']]]);
}

/**
 * The tests insert rows of tables they do not model (projects, users, subscriptions): SQLite checks the foreign keys at the end of the
 * transaction instead of at each statement, and the transaction of the test is rolled back.
 */
function dssDeferForeignKeys(): void
{
    if (DB::getDriverName() === 'sqlite') {
        DB::statement('PRAGMA defer_foreign_keys = ON');
    }
}

/**
 * A posted sales invoice and its projection into the commercial velocity fact.
 *
 * @param  array<string, mixed>  $attributes
 */
function dssInvoice(Tenant $tenant, string $contactId, float $net, string $date = '2026-03-10', array $attributes = []): string
{
    $id = (string) Str::uuid();
    dssDeferForeignKeys();

    DB::table('invoices')->insert([
        'id' => $id, 'tenant_id' => $tenant->id, 'contact_id' => $contactId, 'invoice_number' => 'INV-'.Str::upper(Str::random(6)), 'date' => $date, 'due_date' => null,
        'currency' => (string) $tenant->default_currency, 'total_net' => $net, 'total_tax' => 0, 'total_gross' => $net, 'total_paid' => 0, 'payment_status' => 'unpaid',
        'status' => 'issued', 'posted_at' => $date.' 12:00:00', 'created_at' => now(), 'updated_at' => $date.' 12:00:00', ...$attributes,
    ]);
    UpdateAnalyticalMartJob::dispatchSync((string) $tenant->id, UpdateAnalyticalMartJob::SourceInvoice, $id);

    return $id;
}

function dssTimesheet(Tenant $tenant, float $hours, bool $billable, string $status = 'approved', string $date = '2026-03-11'): string
{
    $id = (string) Str::uuid();
    dssDeferForeignKeys();

    DB::table('project_timesheets')->insert([
        'id' => $id, 'tenant_id' => $tenant->id, 'project_id' => (string) Str::uuid(), 'user_id' => (string) Str::uuid(), 'work_date' => $date, 'hours_worked' => $hours, 'overtime_hours' => 0,
        'is_billable' => $billable, 'cost_rate' => 50, 'charge_rate' => 100, 'total_cost' => $hours * 50, 'total_charge' => $hours * 100, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
    ]);
    UpdateAnalyticalMartJob::dispatchSync((string) $tenant->id, UpdateAnalyticalMartJob::SourceTimesheet, $id);

    return $id;
}

function dssRate(string $source, string $target, float $rate, string $date = '2026-01-01', ?string $tenantId = null): void
{
    DB::table('exchange_rates')->insert(['id' => (string) Str::uuid(), 'source' => $source, 'target' => $target, 'rate' => $rate, 'effective_date' => $date, 'tenant_id' => $tenantId, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * Query string of the global filter for a fixed day, so the tests do not depend on today.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function dssQuery(array $extra = []): array
{
    return ['as_of' => '2026-06-30', 'period_preset' => 'YTD', 'currency' => 'CHF', 'comparison_baseline' => 'PRIOR_YEAR', ...$extra];
}
