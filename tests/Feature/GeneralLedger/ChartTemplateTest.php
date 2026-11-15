<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\ChartTemplates\ChartTemplateCatalog;

require_once __DIR__.'/../Compliance/ComplianceHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = complianceTenant();
    actingAsMember($this->tenant, 'accountant');
});

test('the catalog lists the Swiss SME, Italian, French and Spanish charts', function (): void {
    $data = $this->getJson('/api/v1/general-ledger/account-templates')->assertOk()->json('data');

    expect(collect($data)->pluck('key')->all())->toBe(['ch_kmu_pme', 'it_general_ledger', 'fr_pcg', 'es_pgc'])
        ->and(collect($data)->firstWhere('key', 'ch_kmu_pme'))->toMatchArray(['country' => 'CH', 'currency' => 'CHF'])
        ->and(collect($data)->firstWhere('key', 'it_general_ledger'))->toMatchArray(['country' => 'IT', 'currency' => 'EUR'])
        ->and(collect($data)->firstWhere('key', 'fr_pcg'))->toMatchArray(['country' => 'FR', 'currency' => 'EUR'])
        ->and(collect($data)->firstWhere('key', 'es_pgc'))->toMatchArray(['country' => 'ES', 'currency' => 'EUR']);
});

test('every template has unique codes, existing parents and parents of the same side', function (string $key): void {
    $accounts = ChartTemplateCatalog::all()[$key]['accounts'];
    $codes = array_column($accounts, 0);
    $byCode = array_column($accounts, null, 0);

    expect($codes)->toHaveCount(count(array_unique($codes)));

    foreach ($accounts as [$code, , $type, , $parent]) {
        if ($parent === null) {
            continue;
        }

        expect($byCode)->toHaveKey($parent)
            ->and(array_search($parent, $codes, true))->toBeLessThan(array_search($code, $codes, true));
    }
})->with(['ch_kmu_pme', 'it_general_ledger', 'fr_pcg', 'es_pgc']);

test('a template is imported with its hierarchy and standard, and importing again creates nothing', function (): void {
    $first = $this->postJson('/api/v1/general-ledger/account-templates/ch_kmu_pme/import')->assertOk()->json('data');
    $total = count(ChartTemplateCatalog::all()['ch_kmu_pme']['accounts']);

    expect($first)->toBe(['template' => 'ch_kmu_pme', 'created' => $total, 'skipped' => 0]);

    $bank = ChartOfAccount::where('account_code', '1020')->firstOrFail();
    expect($bank->standard)->toBe('ch_kmu_pme')
        ->and($bank->currency)->toBe('CHF')
        ->and($bank->reconciliation_type)->toBe('bank')
        ->and($bank->allows_manual_posting)->toBeTrue()
        ->and($bank->parentAccount->account_code)->toBe('100')
        ->and(ChartOfAccount::where('account_code', '100')->value('allows_manual_posting'))->toBeFalse();

    $second = $this->postJson('/api/v1/general-ledger/account-templates/ch_kmu_pme/import')->assertOk()->json('data');
    expect($second)->toBe(['template' => 'ch_kmu_pme', 'created' => 0, 'skipped' => $total])
        ->and(ChartOfAccount::count())->toBe($total);
});

test('an existing account is kept and an unknown template is refused', function (): void {
    $this->postJson('/api/v1/general-ledger/accounts', ['account_code' => '22.01', 'name' => 'My bank', 'account_type' => 'asset', 'normal_balance' => 'debit'])->assertCreated();
    $result = $this->postJson('/api/v1/general-ledger/account-templates/it_general_ledger/import')->assertOk()->json('data');

    expect($result['skipped'])->toBe(1)
        ->and(ChartOfAccount::where('account_code', '22.01')->value('name'))->toBe('My bank');

    $this->postJson('/api/v1/general-ledger/account-templates/unknown/import')->assertNotFound();
});
