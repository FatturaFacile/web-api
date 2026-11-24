<?php

declare(strict_types=1);

use App\Contracts\PdfGenerator;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Models\VatSettlement;

/**
 * Builders of the Italian VAT compliance tests (Mod 029). Rows of the neighbouring modules (settlements, journal entries,
 * withholding lines) are written directly: the module reads them. Foreign keys are deferred, the test transaction is rolled back.
 */
function ivDeferForeignKeys(): void
{
    DB::statement('PRAGMA defer_foreign_keys = ON');
}

function ivTenant(string $name = 'Italian VAT'): Tenant
{
    return Tenant::create([
        'name' => $name.' '.Str::random(5),
        'legal_name' => $name.' Srl',
        'vat_number' => 'IT00743110157',
        'fiscal_code' => '00743110157',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);
}

function ivUser(Tenant $tenant, string $role = 'accountant'): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);

    return $user;
}

function ivActAs(Tenant $tenant, ?User $user = null): User
{
    $user ??= ivUser($tenant);
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', (string) $tenant->id);

    return $user;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ivContact(Tenant $tenant, array $attributes = []): Contact
{
    return Contact::create($attributes + [
        'tenant_id' => $tenant->id,
        'type' => 'supplier',
        'country' => 'IT',
        'company_name' => 'Rossi Studio',
        'vat_number' => '12345678903',
        'fiscal_code' => 'RSSMRA80A01H501U',
    ]);
}

/**
 * The id of a standard sectional of the tenant, created on first use through the API.
 */
function ivSectionId(string $code): string
{
    $sections = test()->getJson('/api/v1/italian-vat/sections')->assertOk()->json('data');

    foreach ($sections as $section) {
        if ($section['code'] === $code) {
            return (string) $section['id'];
        }
    }

    throw new RuntimeException("Sectional {$code} not found.");
}

/**
 * @param  array<string, mixed>  $overrides
 */
function ivRegister(string $sectionCode, array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/italian-vat/register-entries', $overrides + [
        'section_id' => ivSectionId($sectionCode),
        'entry_date' => '2025-01-10',
        'document_date' => '2025-01-10',
        'document_number' => 'DOC-'.Str::random(4),
        'taxable_amount' => '1000.00',
        'vat_rate' => '22',
    ]);
}

/**
 * A fake PDF generator: the views are rendered by the real one in production, the tests only check that a PDF is served.
 */
function ivFakePdf(): void
{
    app()->instance(PdfGenerator::class, new class implements PdfGenerator
    {
        public function render(string $view, array $data = []): string
        {
            return '%PDF-fake '.$view;
        }
    });
}

/**
 * A final Italian settlement of a period, with its posting on the Erario c/IVA account when $ledgerAmount is given
 * (credit = amount to pay, a negative amount is a debit).
 *
 * @param  array<string, mixed>  $overrides
 */
function ivSettlement(Tenant $tenant, string $from, string $to, array $overrides = [], ?string $ledgerAmount = null): VatSettlement
{
    $journalId = null;

    if ($ledgerAmount !== null) {
        $journalId = ivErarioPosting($tenant, $to, $ledgerAmount);
    }

    $start = CarbonImmutable::parse($from);

    return VatSettlement::create($overrides + [
        'tenant_id' => $tenant->id,
        'country' => 'IT',
        'currency' => 'EUR',
        'year' => $start->year,
        'period' => $start->month,
        'period_type' => $start->format('Y-m') === CarbonImmutable::parse($to)->format('Y-m') ? 'monthly' : 'quarterly',
        'period_from' => $from,
        'period_to' => $to,
        'status' => 'posted',
        'output_vat_amount' => '1000.0000',
        'input_vat_amount' => '700.0000',
        'taxable_turnover' => '4545.45',
        'passive_operations' => '3181.82',
        'net_vat_payable' => '300.0000',
        'payable_amount' => '300.00',
        'settlement_journal_id' => $journalId,
    ]);
}

/**
 * Posts an entry on the Erario c/IVA account (code 2210) and returns the id of the journal entry.
 */
function ivErarioPosting(Tenant $tenant, string $date, string $amount): string
{
    $account = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->where('code', '2210')->value('id');

    if ($account === null) {
        $account = (string) Str::uuid();
        DB::table('chart_of_accounts')->insert([
            'id' => $account, 'tenant_id' => $tenant->id, 'code' => '2210', 'account_code' => '2210', 'name' => 'Erario c/IVA',
            'type' => 'liability', 'account_type' => 'liability', 'normal_balance' => 'credit', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $entry = (string) Str::uuid();
    DB::table('journal_entries')->insert([
        'id' => $entry, 'tenant_id' => $tenant->id, 'fiscal_period_id' => (string) Str::uuid(), 'entry_number' => 'JE-'.Str::random(6),
        'posting_date' => $date, 'document_date' => $date, 'description' => 'VAT settlement', 'status' => 'posted',
        'source_document_type' => 'vat_settlement', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $negative = str_starts_with($amount, '-');
    DB::table('journal_lines')->insert([
        'id' => (string) Str::uuid(), 'journal_entry_id' => $entry, 'line_number' => 1, 'account_id' => $account, 'description' => 'Net VAT',
        'debit_amount' => $negative ? ltrim($amount, '-') : '0', 'credit_amount' => $negative ? '0' : $amount,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $entry;
}
