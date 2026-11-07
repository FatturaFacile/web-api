<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Customs\Enums\LedgerPostingStatus;
use Modules\Customs\Exceptions\CustomsException;
use Modules\Customs\Models\CustomsLedgerPosting;
use Modules\Customs\Models\CustomsSettings;
use Modules\Customs\Services\CustomsLedgerService;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Services\Tax\VatAccountResolver;
use Modules\GeneralLedger\Services\Tax\VatLedgerReader;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

/**
 * @return array{duty: string, payable: string, vat: string}
 */
function customsLedgerSetup(Tenant $tenant): array
{
    app(TenantContext::class)->setTenant($tenant);
    app(OpenFiscalYearAction::class)->execute($tenant->id, 2026);

    $accounts = [
        'duty' => customsLedgerAccount($tenant, '4200', 'expense'),
        'payable' => customsLedgerAccount($tenant, '2230', 'liability'),
        'vat' => customsLedgerAccount($tenant, '1170', 'asset'),
    ];

    CustomsSettings::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->update([
        'duty_expense_account_id' => $accounts['duty'],
        'customs_payable_account_id' => $accounts['payable'],
    ]);

    return $accounts;
}

function customsLineTotal(string $accountId, string $column): string
{
    return number_format((float) JournalLine::withoutGlobalScopes()->where('account_id', $accountId)->sum($column), 2, '.', '');
}

beforeEach(function (): void {
    $this->travelTo('2026-11-07 10:00:00');
    Storage::fake('private');
    $this->tenant = customsTenant();
    $this->accountant = customsMember($this->tenant, 'accountant');
    customsSettings($this->tenant);
    $this->accounts = customsLedgerSetup($this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('a duty decision is posted as cost against the customs payable', function (): void {
    $decision = customsArchive($this->tenant, customsDutyDecisionXml());

    customsApi($this->accountant, $this->tenant)->postJson("/api/v1/customs/assessment-decisions/{$decision->id}/post")->assertOk()->assertJsonPath('data.status', 'posted');

    $entry = JournalEntry::withoutGlobalScopes()->where('source_document_id', $decision->id)->sole();

    expect($entry->source_document_type)->toBe(CustomsLedgerService::SOURCE)
        ->and($entry->document_number)->toBe('26CHEI000000000001')
        ->and((string) $entry->posting_date)->toContain('2026-10-15')
        ->and(customsLineTotal($this->accounts['duty'], 'debit_amount'))->toBe('250.00')
        ->and(customsLineTotal($this->accounts['payable'], 'credit_amount'))->toBe('250.00');
});

test('import VAT lands on the input VAT account and the Swiss VAT flow reads it', function (): void {
    $decision = customsArchive($this->tenant, customsVatDecisionXml());

    customsApi($this->accountant, $this->tenant)->postJson("/api/v1/customs/assessment-decisions/{$decision->id}/post")->assertOk();

    $accounts = app(VatAccountResolver::class)->accountsFor($this->tenant->id, 'CH');
    $snapshot = app(VatLedgerReader::class)->read($this->tenant->id, 'CH', CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-12-31'), $accounts);

    expect($accounts->inputAccountIds)->toContain($this->accounts['vat'])
        ->and($snapshot->purchases)->toHaveCount(1)
        ->and(bccomp($snapshot->purchases[0]->deductibleVat, '17.30', 2))->toBe(0)
        ->and($snapshot->warnings)->toBe([])
        ->and(customsLineTotal($this->accounts['payable'], 'credit_amount'))->toBe('17.30');
});

test('posting twice writes one entry', function (): void {
    $decision = customsArchive($this->tenant, customsDutyDecisionXml());
    customsApi($this->accountant, $this->tenant);

    $this->postJson("/api/v1/customs/assessment-decisions/{$decision->id}/post")->assertOk();
    $this->postJson("/api/v1/customs/assessment-decisions/{$decision->id}/post")->assertOk();

    expect(JournalEntry::withoutGlobalScopes()->where('source_document_id', $decision->id)->count())->toBe(1);
});

test('a refund reverses the sides', function (): void {
    $decision = customsArchive($this->tenant, customsDutyDecisionXml(type: 'refundCustomsDuties'));

    app(CustomsLedgerService::class)->post($decision, null);

    expect(customsLineTotal($this->accounts['duty'], 'credit_amount'))->toBe('250.00')
        ->and(customsLineTotal($this->accounts['payable'], 'debit_amount'))->toBe('250.00');
});

test('a VAT refund, which holds no goods items, is posted on the stated total', function (): void {
    $xml = str_replace('taxationDecisionVAT', 'refundVAT', (string) preg_replace('#<goodsItem>.*</goodsItem>#s', '', customsVatDecisionXml()));
    $decision = customsArchive($this->tenant, $xml);

    expect(app(CustomsLedgerService::class)->post($decision, null)->status)->toBe(LedgerPostingStatus::Posted)
        ->and(customsLineTotal($this->accounts['vat'], 'credit_amount'))->toBe('17.30');
});

test('a corrected version posts only the difference', function (): void {
    app(CustomsLedgerService::class)->post(customsArchive($this->tenant, customsDutyDecisionXml(duty: '250.00')), null);

    $second = app(CustomsLedgerService::class)->post(customsArchive($this->tenant, customsDutyDecisionXml(version: 2, duty: '300.00')), null);
    $third = app(CustomsLedgerService::class)->post(customsArchive($this->tenant, customsDutyDecisionXml(version: 3, duty: '280.00')), null);

    expect($second->status)->toBe(LedgerPostingStatus::Posted)
        ->and($third->status)->toBe(LedgerPostingStatus::Posted)
        ->and(customsLineTotal($this->accounts['duty'], 'debit_amount'))->toBe('300.00')
        ->and(customsLineTotal($this->accounts['duty'], 'credit_amount'))->toBe('20.00');
});

test('a correction that changes nothing writes no entry', function (): void {
    app(CustomsLedgerService::class)->post(customsArchive($this->tenant, customsDutyDecisionXml()), null);
    $posting = app(CustomsLedgerService::class)->post(customsArchive($this->tenant, customsDutyDecisionXml(version: 2)), null);

    expect($posting->status)->toBe(LedgerPostingStatus::Posted)
        ->and($posting->journal_entry_id)->toBeNull()
        ->and(JournalEntry::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('source_document_type', CustomsLedgerService::SOURCE)->count())->toBe(1);
});

test('an older version cannot be posted over a newer one', function (): void {
    app(CustomsLedgerService::class)->post(customsArchive($this->tenant, customsDutyDecisionXml(version: 2)), null);

    expect(fn () => app(CustomsLedgerService::class)->post(customsArchive($this->tenant, customsDutyDecisionXml(version: 1)), null))
        ->toThrow(CustomsException::class, 'already posted');
});

test('a missing account or closed period refuses with the reason kept on the posting', function (): void {
    DB::table('customs_settings')->where('tenant_id', $this->tenant->id)->update(['customs_payable_account_id' => null]);
    $decision = customsArchive($this->tenant, customsDutyDecisionXml());

    customsApi($this->accountant, $this->tenant)->postJson("/api/v1/customs/assessment-decisions/{$decision->id}/post")->assertUnprocessable()
        ->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'customs payable'));

    $posting = CustomsLedgerPosting::query()->where('assessment_decision_id', $decision->id)->sole();

    expect($posting->status)->toBe(LedgerPostingStatus::Pending)->and($posting->failure_reason)->toContain('customs payable');
});

test('a decision in a year without a fiscal period waits', function (): void {
    $decision = customsArchive($this->tenant, customsDutyDecisionXml(date: '2015-07-07'));

    customsApi($this->accountant, $this->tenant)->postJson("/api/v1/customs/assessment-decisions/{$decision->id}/post")->assertUnprocessable();

    expect(JournalEntry::withoutGlobalScopes()->where('source_document_id', $decision->id)->exists())->toBeFalse();
});

test('a VAT account outside the VAT settings is refused, so no tax is lost', function (): void {
    $other = customsLedgerAccount($this->tenant, '1999', 'asset');
    DB::table('customs_settings')->where('tenant_id', $this->tenant->id)->update(['import_vat_account_id' => $other]);
    $decision = customsArchive($this->tenant, customsVatDecisionXml());

    customsApi($this->accountant, $this->tenant)->postJson("/api/v1/customs/assessment-decisions/{$decision->id}/post")->assertUnprocessable()
        ->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'input VAT'));
});

test('an account of another company is refused by the settings', function (): void {
    $other = customsTenant();
    $foreign = customsLedgerAccount($other, '4200', 'expense');

    customsApi($this->accountant, $this->tenant)->putJson('/api/v1/customs/settings', ['duty_expense_account_id' => $foreign])
        ->assertUnprocessable()->assertJsonValidationErrors(['duty_expense_account_id']);
});

test('a company not in CHF is refused', function (): void {
    $tenant = customsTenant('IT');
    customsSettings($tenant);
    customsLedgerSetup($tenant);
    $decision = customsArchive($tenant, customsDutyDecisionXml());

    expect(fn () => app(CustomsLedgerService::class)->post($decision, null))->toThrow(CustomsException::class, 'CHF');
});

test('what fails its signature or its hash is not posted', function (): void {
    $invalid = customsArchive($this->tenant, customsVatDecisionXml(), customsSignedEnvelope(customsVatDecisionXml(), customsTestPki()));
    $altered = customsArchive($this->tenant, customsDutyDecisionXml('26CHEI000000000002'));
    Storage::disk('private')->put($altered->xml_path, 'changed');

    expect(fn () => app(CustomsLedgerService::class)->post($invalid, null))->toThrow(CustomsException::class, 'signature')
        ->and(fn () => app(CustomsLedgerService::class)->post($altered, null))->toThrow(CustomsException::class, 'hash');
});

test('export decisions are not posted', function (): void {
    $decision = customsArchive($this->tenant, customsOfficialFixture('edecReceiptResponse_taxationDecisionExport.xml'));

    customsApi($this->accountant, $this->tenant)->postJson("/api/v1/customs/assessment-decisions/{$decision->id}/post")->assertUnprocessable();
});

test('the customs role can work the module but not the ledger', function (string $role, array $permissions, int $status): void {
    $decision = customsArchive($this->tenant, customsDutyDecisionXml());
    customsApi(customsMember($this->tenant, $role, $permissions), $this->tenant);

    expect($this->postJson("/api/v1/customs/assessment-decisions/{$decision->id}/post")->status())->toBe($status);
})->with([
    'customs agent' => ['customs', [], 403],
    'warehouse' => ['warehouse', [], 403],
    'with the ledger grant' => ['customs', ['customs.ledger'], 200],
    'admin' => ['admin', [], 200],
]);

test('the reconciliation compares each document with the payable account', function (): void {
    $posted = customsArchive($this->tenant, customsDutyDecisionXml('26CHEI000000000001'));
    app(CustomsLedgerService::class)->post($posted, null);
    app(CustomsLedgerService::class)->post(customsArchive($this->tenant, customsDutyDecisionXml('26CHEI000000000001', 2, duty: '300.00')), null);
    customsArchive($this->tenant, customsVatDecisionXml('26CHEI000000000002'));
    customsArchive($this->tenant, customsOfficialFixture('edecReceiptResponse_taxationDecisionExport.xml'));

    $data = customsApi($this->accountant, $this->tenant)->getJson('/api/v1/customs/ledger/reconciliation?date_from=2026-10-01&date_to=2026-10-31')->assertOk()->json('data');

    expect($data['assessed_total'])->toBe('317.30')
        ->and($data['ledger_total'])->toBe('300.00')
        ->and($data['difference'])->toBe('17.30')
        ->and($data['lines'])->toHaveCount(2);

    $byMrn = collect($data['lines'])->keyBy('mrn');

    expect($byMrn['26CHEI000000000001']['matches'])->toBeTrue()
        ->and($byMrn['26CHEI000000000002']['matches'])->toBeFalse();
});

test('the reconciliation is closed to those outside finance and to other companies', function (): void {
    customsApi(customsMember($this->tenant, 'customs'), $this->tenant)->getJson('/api/v1/customs/ledger/reconciliation?date_from=2026-10-01&date_to=2026-10-31')->assertForbidden();

    $decision = customsArchive($this->tenant, customsDutyDecisionXml());
    $other = customsTenant();
    customsApi(customsMember($other, 'owner'), $other)->postJson("/api/v1/customs/assessment-decisions/{$decision->id}/post")->assertNotFound();
});
