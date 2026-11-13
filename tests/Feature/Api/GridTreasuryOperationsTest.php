<?php

declare(strict_types=1);

use App\Models\BankReconciliationRule;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    treasurySetUp($this);
    $this->intesa = treasuryBank($this->tenant, 'IT60X0542811101000000123456', $this->accounts['1001']);
    $this->sella = treasuryBank($this->tenant, 'IT42L1234512345123456789012', $this->accounts['1003'], ['bank_name' => 'Banca Sella']);
    $this->today = now()->toDateString();
});

/**
 * @param  list<array{value: string|null, count: int}>  $values
 * @return array<string, int>
 */
function gridCounts(array $values): array
{
    return collect($values)->mapWithKeys(fn (array $row): array => [(string) $row['value'] => $row['count']])->all();
}

test('the reconciliation rules grid filters, sorts and lists the values of the calculated activity state', function (): void {
    foreach ([['Alpha', true], ['Bravo', false], ['Charlie', true]] as $index => [$name, $active]) {
        BankReconciliationRule::query()->create(['tenant_id' => $this->tenant->id, 'name' => $name, 'priority' => 10 + $index, 'is_active' => $active, 'description_contains' => 'X', 'category' => 'bank_expense']);
    }

    $values = $this->getJson('/api/v1/banking/reconciliation-rules?grid_values=activity_state')->assertOk();
    expect(gridCounts($values->json('data')))->toBe(['active' => 2, 'inactive' => 1]);

    $filtered = $this->getJson('/api/v1/banking/reconciliation-rules?filter[activity_state][]=inactive')->assertOk();
    expect($filtered->json('meta.total'))->toBe(1)
        ->and($filtered->json('data.0.name'))->toBe('Bravo');

    $sorted = $this->getJson('/api/v1/banking/reconciliation-rules?sort_by=activity_state&sort_dir=asc')->assertOk();
    expect($sorted->json('data.0.is_active'))->toBeTrue()
        ->and($sorted->json('data.2.name'))->toBe('Bravo');

    $page = $this->getJson('/api/v1/banking/reconciliation-rules?per_page=2')->assertOk();
    expect($page->json('data'))->toHaveCount(2)
        ->and($page->json('meta.total'))->toBe(3)
        ->and($page->json('meta.last_page'))->toBe(2);
});

test('the treasury movements grid sorts and filters by the bank account name and lists its values', function (): void {
    $transfer = fn (string $from, string $to, string $key): mixed => $this->postJson('/api/v1/treasury/transfers', ['from_bank_account_id' => $from, 'to_bank_account_id' => $to, 'amount' => '10.00', 'transfer_date' => $this->today, 'idempotency_key' => $key])->assertCreated();
    $transfer($this->intesa->id, $this->sella->id, 'G-1');
    $transfer($this->intesa->id, $this->sella->id, 'G-2');
    $transfer($this->sella->id, $this->intesa->id, 'G-3');
    $this->postJson("/api/v1/treasury/bank-accounts/{$this->intesa->id}/charges", ['movement_type' => 'bank_fee', 'amount' => '5.00', 'movement_date' => $this->today, 'idempotency_key' => 'G-4'])->assertCreated();

    $types = $this->getJson('/api/v1/treasury/movements?grid_values=movement_type')->assertOk();
    expect(gridCounts($types->json('data')))->toBe(['bank_fee' => 1, 'transfer' => 3]);

    $banks = $this->getJson('/api/v1/treasury/movements?grid_values=bankAccount.bank_name')->assertOk();
    expect(gridCounts($banks->json('data')))->toBe([$this->intesa->bank_name => 3, 'Banca Sella' => 1]);

    $filtered = $this->getJson('/api/v1/treasury/movements?filter[bankAccount.bank_name][]=Banca%20Sella')->assertOk();
    expect($filtered->json('meta.total'))->toBe(1);

    $empty = $this->getJson('/api/v1/treasury/movements?filter[counterBankAccount.bank_name][]=__empty__')->assertOk();
    expect($empty->json('meta.total'))->toBe(1);

    $sorted = $this->getJson('/api/v1/treasury/movements?sort_by=bankAccount.bank_name&sort_dir=desc&per_page=4')->assertOk();
    expect($sorted->json('data.0.bank_account_id'))->toBe($this->sella->id);
});

test('the cash register lists page, sort and filter on the server', function (): void {
    foreach (['B-CASH', 'A-CASH', 'C-CASH'] as $code) {
        $this->postJson('/api/v1/treasury/cash-registers', ['code' => $code, 'name' => "Register {$code}", 'currency' => 'EUR', 'gl_account_id' => $this->accounts['1010']->id, 'opening_balance' => '100.00'])->assertCreated();
    }

    $sorted = $this->getJson('/api/v1/treasury/cash-registers?sort_by=code&sort_dir=desc&per_page=2')->assertOk();
    expect(collect($sorted->json('data'))->pluck('code')->all())->toBe(['C-CASH', 'B-CASH'])
        ->and($sorted->json('meta.total'))->toBe(3)
        ->and($sorted->json('meta.last_page'))->toBe(2);

    $register = $sorted->json('data.0.id');
    foreach (['in' => '50.00', 'out' => '20.00'] as $direction => $amount) {
        $this->postJson("/api/v1/treasury/cash-registers/{$register}/movements", ['movement_date' => $this->today, 'direction' => $direction, 'amount' => $amount, 'counter_account_id' => $this->accounts['1090']->id, 'description' => "Cash {$direction}", 'idempotency_key' => "GC-{$direction}"])->assertCreated();
    }

    $directions = $this->getJson("/api/v1/treasury/cash-registers/{$register}/movements?grid_values=direction")->assertOk();
    expect(gridCounts($directions->json('data')))->toBe(['in' => 1, 'out' => 1]);

    $outgoing = $this->getJson("/api/v1/treasury/cash-registers/{$register}/movements?filter[direction][]=out")->assertOk();
    expect($outgoing->json('meta.total'))->toBe(1)
        ->and($outgoing->json('data.0.amount'))->toBe('20.00');

    $this->getJson("/api/v1/treasury/cash-registers/{$register}/closings?per_page=5")->assertOk()->assertJsonPath('meta.total', 0);
});
