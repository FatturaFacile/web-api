<?php

declare(strict_types=1);

use App\Models\Tenant;
use Database\Seeders\ForeignVatRefundReferenceSeeder;
use Illuminate\Testing\TestResponse;
use Modules\ForeignVatRefund\Models\ForeignVatLedgerEntry;
use Modules\ForeignVatRefund\Models\VatRefundClaim;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\DefaultPostingAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\Treasury\Models\BankAccount;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

const FVR_API = '/api/v1/foreign-vat-refund';

/**
 * Builders of the foreign VAT refund tests (Mod 227): an Italian or Swiss company with the seeded matrix, a member signed in with a role,
 * a fiscal period over 2026-2027, the input VAT and expense accounts of the postings, and a bank account linked to the ledger. The clock stands
 * on 10 July 2027, so the fiscal year 2026 is open for claims (deadlines 30 June 2027 for the ESTV, 30 September 2027 for the EU and Italy).
 */
function fvSetUp(object $test, string $home = 'IT', string $role = 'accountant'): void
{
    $test->travelTo('2027-07-10 10:00:00');
    $swiss = $home === 'CH';
    $test->tenant = Tenant::create([
        'name' => 'Estera '.strtoupper(substr(md5((string) microtime(true)), 0, 5)),
        'legal_name' => $swiss ? 'Estera AG' : 'Estera Srl',
        'country' => $home,
        'default_currency' => $swiss ? 'CHF' : 'EUR',
        'vat_number' => $swiss ? null : 'IT01234567890',
        'uid_number' => $swiss ? 'CHE-123.456.788' : null,
        'iban' => $swiss ? 'CH9300762011623852957' : 'IT60X0542811101000000123456',
    ]);
    $test->otherTenant = Tenant::create(['name' => 'Altra Srl', 'legal_name' => 'Altra Srl', 'country' => $home, 'default_currency' => $swiss ? 'CHF' : 'EUR']);

    // The global rows are written before any company context exists.
    (new ForeignVatRefundReferenceSeeder)->run();

    $test->actor = treasuryActor($test, $role);
    fvLedgerSetUp($test);
}

function fvLedgerSetUp(object $test): void
{
    $tenant = $test->tenant;
    FiscalPeriod::factory()->create(['tenant_id' => $tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'period_number' => 1, 'period_name' => '2026-2027']);
    $currency = $tenant->default_currency;
    $inputVat = treasuryAccount($tenant, '1170', 'Input VAT');
    $expense = treasuryAccount($tenant, '4000', 'Purchases');
    $bankGl = treasuryAccount($tenant, '1020', 'Bank');

    foreach (['input_vat' => $inputVat, 'purchase_expense' => $expense] as $role => $account) {
        DefaultPostingAccount::create(['tenant_id' => $tenant->id, 'role' => $role, 'account_id' => $account->id]);
    }

    $test->inputVat = $inputVat;
    $test->bankGl = $bankGl;
    $test->bank = treasuryBank($tenant, $currency === 'CHF' ? 'CH9300762011623852957' : 'IT60X0542811101000000123456', $bankGl, ['currency' => $currency]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fvManualPayload(array $overrides = []): array
{
    return array_merge([
        'document_number' => 'INV-'.random_int(1000, 9999),
        'document_date' => '2026-03-10',
        'supplier_name' => 'Hotel Berlin GmbH',
        'supplier_country_code' => 'DE',
        'expense_category_code' => 6,
        'net_amount' => 1000,
        'foreign_vat_rate' => 19,
        'foreign_vat_amount' => 190,
        'currency_code' => 'EUR',
        'vat_booked_on' => 'input_vat',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function fvManual(object $test, array $overrides = []): TestResponse
{
    return $test->postJson(FVR_API.'/ledger', fvManualPayload($overrides));
}

/**
 * A manual foreign VAT item, created through the API.
 *
 * @param  array<string, mixed>  $overrides
 */
function fvEntry(object $test, array $overrides = []): ForeignVatLedgerEntry
{
    $id = (string) fvManual($test, $overrides)->assertCreated()->json('data.id');

    return ForeignVatLedgerEntry::query()->findOrFail($id);
}

/**
 * A claim for 2026 from the eligible lines of a country (one German hotel of 190 euro by default).
 *
 * @param  array<string, mixed>  $payload
 */
function fvClaim(object $test, array $payload = []): VatRefundClaim
{
    $id = (string) $test->postJson(FVR_API.'/claims', array_merge(['target_country_code' => 'DE', 'fiscal_year' => 2026, 'period_type' => 'ANNUAL'], $payload))->assertCreated()->json('data.id');

    return VatRefundClaim::query()->findOrFail($id);
}

/**
 * Debit and credit totals and the lines of a journal entry, by account.
 *
 * @return array{debit: float, credit: float, lines: array<string, array{debit: float, credit: float}>}
 */
function fvJournal(?string $journalEntryId): array
{
    $result = ['debit' => 0.0, 'credit' => 0.0, 'lines' => []];

    foreach (JournalLine::withoutGlobalScopes()->where('journal_entry_id', $journalEntryId)->get() as $line) {
        $debit = (float) $line->debit_amount;
        $credit = (float) $line->credit_amount;
        $result['debit'] += $debit;
        $result['credit'] += $credit;
        $account = (string) $line->account_id;
        $result['lines'][$account] = ['debit' => ($result['lines'][$account]['debit'] ?? 0.0) + $debit, 'credit' => ($result['lines'][$account]['credit'] ?? 0.0) + $credit];
    }

    return $result;
}

function fvAccountByCode(Tenant $tenant, string $code): string
{
    return (string) ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('account_code', $code)->value('id');
}

function fvBank(object $test): BankAccount
{
    return $test->bank;
}
