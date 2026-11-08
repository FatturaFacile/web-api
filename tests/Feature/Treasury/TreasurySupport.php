<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\Treasury\Models\BankAccount;

/**
 * Two companies with an open fiscal calendar for the current year, an accountant (finance writer) signed in on the
 * first one, and the ledger accounts the treasury postings use, configured in `banking.ledger_defaults`.
 */
function treasurySetUp(object $test): void
{
    $test->tenant = Tenant::create(['name' => 'Tesoreria Alfa', 'legal_name' => 'Tesoreria Alfa Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $test->otherTenant = Tenant::create(['name' => 'Tesoreria Beta', 'legal_name' => 'Tesoreria Beta Srl', 'country' => 'IT', 'default_currency' => 'EUR']);

    foreach ([$test->tenant, $test->otherTenant] as $tenant) {
        fiscalCalendar($tenant, (int) now()->year);
    }

    $test->accounts = [];

    foreach (['1001' => 'Banca Intesa c/c', '1002' => 'UBS CHF', '1003' => 'Banca Sella c/c', '1010' => 'Cassa contanti', '1090' => 'Giroconti', '6220' => 'Spese bancarie', '6510' => 'Interessi passivi', '4810' => 'Interessi attivi', '6900' => 'Spese varie', '4890' => 'Utili su cambi', '6890' => 'Perdite su cambi', '6995' => 'Differenze di cassa'] as $code => $name) {
        $test->accounts[(string) $code] = treasuryAccount($test->tenant, (string) $code, $name);
    }

    $test->tenant->update(['settings' => ['preferences' => ['banking' => ['ledger_defaults' => [
        'bank_charges_account_code' => '6220',
        'interest_expense_account_code' => '6510',
        'interest_income_account_code' => '4810',
        'internal_transfer_account_code' => '1090',
        'generic_expense_account_code' => '6900',
        'fx_unrealised_gain_account_code' => '4890',
        'fx_unrealised_loss_account_code' => '6890',
        'cash_difference_account_code' => '6995',
    ]]]]]);

    $test->accountant = treasuryActor($test, 'accountant');
}

/**
 * Runs a callback in the context of a company: records created in it belong to that company (an explicit tenant
 * context forces the tenant of every record it creates).
 *
 * @template TReturn
 *
 * @param  callable(): TReturn  $callback
 * @return TReturn
 */
function treasuryInTenant(Tenant $tenant, callable $callback): mixed
{
    return app(TenantContext::class)->runWithTenant($tenant, $callback);
}

function treasuryAccount(Tenant $tenant, string $code, string $name): ChartOfAccount
{
    return treasuryInTenant($tenant, fn (): ChartOfAccount => ChartOfAccount::withoutGlobalScopes()->create([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'account_code' => $code, 'name' => $name,
        'account_class' => 'asset', 'account_type' => 'asset', 'normal_balance' => 'debit', 'currency' => 'EUR', 'is_active' => true,
    ]));
}

/**
 * A member of a company with a role, signed in for the next requests on that company.
 */
function treasuryActor(object $test, string $role, ?Tenant $tenant = null): User
{
    $tenant ??= $test->tenant;
    $user = User::create(['name' => ucfirst($role), 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);
    treasuryActAs($test, $user, $tenant);

    return $user;
}

function treasuryActAs(object $test, User $user, ?Tenant $tenant = null): void
{
    Sanctum::actingAs($user);
    $test->withHeader('X-Tenant-Id', ($tenant ?? $test->tenant)->id);
    app(TenantContext::class)->setTenant($tenant ?? $test->tenant);
}

/**
 * A bank account of a company, linked to a ledger account when one is given.
 *
 * @param  array<string, mixed>  $overrides
 */
function treasuryBank(Tenant $tenant, string $iban, ?ChartOfAccount $ledgerAccount, array $overrides = []): BankAccount
{
    return treasuryInTenant($tenant, fn (): BankAccount => BankAccount::withoutGlobalScopes()->create(array_merge([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'company_id' => $tenant->id, 'bank_name' => 'Banca '.substr($iban, -4),
        'iban' => $iban, 'bic_swift' => 'BCITITMM', 'currency' => 'EUR', 'account_type' => 'checking', 'gl_account_id' => $ledgerAccount?->id,
        'opening_balance' => '10000.00', 'current_balance' => '10000.00', 'available_balance' => '10000.00', 'overdraft_limit' => '0', 'is_active' => true,
    ], $overrides)));
}

/**
 * An open supplier invoice of a company.
 *
 * @param  array<string, mixed>  $overrides
 */
function treasurySupplierInvoice(Tenant $tenant, Contact $supplier, string $number, string $amount, array $overrides = []): PassiveInvoice
{
    return treasuryInTenant($tenant, fn (): PassiveInvoice => PassiveInvoice::withoutGlobalScopes()->create(array_merge([
        'tenant_id' => $tenant->id, 'supplier_id' => $supplier->id, 'invoice_number' => $number, 'date' => now()->subDays(10)->toDateString(),
        'due_date' => now()->addDays(5)->toDateString(), 'currency' => 'EUR', 'total_amount' => $amount, 'total_net' => $amount, 'total_tax' => '0',
        'document_type' => 'TD01', 'payment_status' => 'unpaid',
    ], $overrides)));
}

/**
 * The lines of the posted entry of a source as [account code, debit, credit].
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function treasuryPostings(string $sourceType, string $sourceId): array
{
    $entry = JournalEntry::withoutGlobalScopes()->where('source_document_type', $sourceType)->where('source_document_id', $sourceId)->where('journal_type', '!=', 'reversing')->firstOrFail();

    return JournalLine::withoutGlobalScopes()->where('journal_entry_id', $entry->id)->orderBy('line_number')->get()
        ->map(fn (JournalLine $line): array => [(string) ChartOfAccount::withoutGlobalScopes()->find($line->account_id)->account_code, bcadd((string) $line->debit_amount, '0', 2), bcadd((string) $line->credit_amount, '0', 2)])
        ->all();
}
