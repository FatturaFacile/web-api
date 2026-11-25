<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ExpenseManagement\Models\CorporateCardAccount;
use Modules\ExpenseManagement\Models\ExpenseLine;

require_once __DIR__.'/ExpenseManagementSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    exmSetUp($this);
    $this->account = treasuryInTenant($this->tenant, fn (): CorporateCardAccount => CorporateCardAccount::create([
        'tenant_id' => $this->tenant->id, 'holder_name' => 'Mario Rossi', 'card_last4' => '4242', 'currency' => 'EUR',
    ]));
    $this->report = exmCreateReport($this);
});

/**
 * @return string the id of the card transaction
 */
function exmCardTransaction(object $test, string $amount, string $externalId): string
{
    exmActAs($test, 'accountant');
    $imported = $test->postJson('/api/v1/expense-management/card-transactions/import', [
        'card_account_id' => $test->account->id,
        'transactions' => [['external_id' => $externalId, 'merchant_name' => 'Amazon', 'transaction_date' => now()->subDay()->toDateString(), 'amount' => $amount]],
    ])->assertCreated();

    return (string) $imported->json('data.0.id');
}

function exmCardLine(object $test, string $amount): string
{
    treasuryActAs($test, $test->employee);

    return (string) exmAddLine($test, $test->report, 'supplies', ['original_amount' => $amount, 'merchant_name' => 'Amazon '.$amount])->assertCreated()->json('data.id');
}

it('keeps card statements to managers and the import and reconciliation to the finance roles', function (): void {
    $this->getJson('/api/v1/expense-management/card-transactions')->assertForbidden();

    exmActAs($this, 'hr');
    $this->getJson('/api/v1/expense-management/card-transactions')->assertOk();
    $this->postJson('/api/v1/expense-management/card-transactions/import', ['card_account_id' => $this->account->id, 'transactions' => [['merchant_name' => 'X', 'transaction_date' => now()->toDateString(), 'amount' => '1']]])->assertForbidden();
});

it('imports a statement once per external id', function (): void {
    exmCardTransaction($this, '40.00', 'EXT-1');
    exmCardTransaction($this, '40.00', 'EXT-1');

    $this->getJson('/api/v1/expense-management/card-transactions?status=unmatched')->assertOk()->assertJsonCount(1, 'data');
});

it('matches a card transaction with a line: the line stops being reimbursable and the transaction is matched', function (): void {
    $transaction = exmCardTransaction($this, '40.00', 'EXT-2');
    $line = exmCardLine($this, '40.00');

    exmActAs($this, 'accountant');
    $this->postJson("/api/v1/expense-management/card-transactions/{$transaction}/reconcile", ['action' => 'match', 'matches' => [['expense_line_id' => $line]]])
        ->assertOk()->assertJsonPath('data.status', 'matched')->assertJsonPath('data.remaining_amount', '0.00');

    $report = $this->getJson("/api/v1/expense-management/reports/{$this->report}")->json('data');
    expect($report['reimbursable_amount'])->toBe('0.00')->and($report['non_reimbursable_amount'])->toBe('40.00')
        ->and(ExpenseLine::query()->find($line)->payment_method->value)->toBe('corporate_card');

    $this->getJson('/api/v1/expense-management/card-transactions?status=unmatched')->assertOk()->assertJsonCount(0, 'data');
});

it('splits a transaction over several lines and keeps it unmatched until fully covered', function (): void {
    $transaction = exmCardTransaction($this, '100.00', 'EXT-3');
    $first = exmCardLine($this, '60.00');
    $second = exmCardLine($this, '40.00');

    exmActAs($this, 'accountant');
    $this->postJson("/api/v1/expense-management/card-transactions/{$transaction}/reconcile", ['action' => 'match', 'matches' => [['expense_line_id' => $first]]])
        ->assertOk()->assertJsonPath('data.status', 'unmatched')->assertJsonPath('data.matched_amount', '60.00')->assertJsonPath('data.remaining_amount', '40.00');

    $this->postJson("/api/v1/expense-management/card-transactions/{$transaction}/reconcile", ['action' => 'match', 'matches' => [['expense_line_id' => $second]]])
        ->assertOk()->assertJsonPath('data.status', 'matched')->assertJsonCount(2, 'data.matched_lines');
});

it('refuses to match more than the transaction amount or a line that is already matched elsewhere', function (): void {
    $small = exmCardTransaction($this, '30.00', 'EXT-4');
    $other = exmCardTransaction($this, '50.00', 'EXT-5');
    $line = exmCardLine($this, '50.00');

    exmActAs($this, 'accountant');
    $this->postJson("/api/v1/expense-management/card-transactions/{$small}/reconcile", ['action' => 'match', 'matches' => [['expense_line_id' => $line]]])->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_transaction');

    $this->postJson("/api/v1/expense-management/card-transactions/{$other}/reconcile", ['action' => 'match', 'matches' => [['expense_line_id' => $line]]])->assertOk();
    $this->postJson("/api/v1/expense-management/card-transactions/{$small}/reconcile", ['action' => 'match', 'matches' => [['expense_line_id' => $line, 'amount' => '20']]])->assertStatus(422)->assertJsonPath('code', 'card_transaction_already_matched');
});

it('unmatches, ignores and disputes a transaction', function (): void {
    $transaction = exmCardTransaction($this, '40.00', 'EXT-6');
    $line = exmCardLine($this, '40.00');

    exmActAs($this, 'accountant');
    $url = "/api/v1/expense-management/card-transactions/{$transaction}/reconcile";
    $this->postJson($url, ['action' => 'match', 'matches' => [['expense_line_id' => $line]]])->assertOk();
    $this->postJson($url, ['action' => 'ignore'])->assertStatus(422)->assertJsonPath('code', 'card_transaction_already_matched');

    $this->postJson($url, ['action' => 'unmatch'])->assertOk()->assertJsonPath('data.status', 'unmatched')->assertJsonPath('data.matched_amount', '0.00');
    expect(ExpenseLine::query()->find($line)->payment_method->value)->toBe('employee_out_of_pocket');

    $this->postJson($url, ['action' => 'dispute'])->assertOk()->assertJsonPath('data.status', 'disputed');
});
