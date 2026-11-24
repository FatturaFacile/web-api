<?php

declare(strict_types=1);

use App\Models\LegalCase;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PracticeManagement\Models\PracticeExpense;
use Modules\PracticeManagement\Models\PracticeTrustTransaction;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-06-26 10:00:00');
    $this->tenant = verticalTenant();
    $this->client = verticalContact($this->tenant, 'Alfa Holding SA');
    $this->other = verticalContact($this->tenant, 'Beta Immobiliare SA');
    $this->partner = verticalMember($this->tenant, 'admin');

    app(TenantContext::class)->setTenant($this->tenant);
    $this->case = LegalCase::create(['client_id' => $this->client->id, 'case_number' => 'F-1', 'title' => 'Contract dispute', 'status' => 'open', 'jurisdiction' => 'CH']);
    $this->otherCase = LegalCase::create(['client_id' => $this->other->id, 'case_number' => 'F-2', 'title' => 'Lease', 'status' => 'open', 'jurisdiction' => 'CH']);

    verticalApi($this->partner, $this->tenant);
    $this->trust = fn (array $overrides = []) => $this->postJson('/api/v1/practice/trust-ledger', [
        'type' => 'deposit', 'client_id' => $this->client->id, 'occurred_on' => '2027-06-26', 'amount' => '1000.00', ...$overrides,
    ]);
});

test('the client funds ledger refuses to go below zero per client and per matter', function (): void {
    ($this->trust)()->assertCreated()->assertJsonPath('data.balance_after', '1000.00');
    ($this->trust)(['legal_case_id' => $this->case->id, 'amount' => '300.00'])->assertCreated()->assertJsonPath('data.balance_after', '300.00');

    ($this->trust)(['type' => 'withdrawal', 'amount' => '1000.01'])->assertUnprocessable()
        ->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'only 1000.00'));
    ($this->trust)(['type' => 'fee_transfer', 'legal_case_id' => $this->case->id, 'amount' => '300.01'])->assertUnprocessable();
    ($this->trust)(['type' => 'fee_transfer', 'legal_case_id' => $this->case->id, 'amount' => '300.00'])->assertCreated()->assertJsonPath('data.balance_after', '0.00');
    ($this->trust)(['type' => 'withdrawal', 'amount' => '400.00'])->assertCreated()->assertJsonPath('data.balance_after', '600.00');

    ($this->trust)(['legal_case_id' => $this->otherCase->id])->assertUnprocessable()
        ->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'another client'));
    ($this->trust)(['amount' => '0'])->assertUnprocessable()->assertJsonValidationErrors('amount');

    $balances = collect($this->getJson('/api/v1/practice/trust-ledger/balances')->assertOk()->json('data'))->keyBy('client_id');
    expect($balances[$this->client->id]['total'])->toBe('600.00');
    $this->getJson('/api/v1/practice/trust-ledger?client_id='.$this->client->id)->assertOk()->assertJsonCount(4, 'data');
});

test('a trust row is reversed once with a counter row and is never edited', function (): void {
    $deposit = ($this->trust)()->assertCreated()->json('data.id');
    $withdrawal = ($this->trust)(['type' => 'withdrawal', 'amount' => '600.00'])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/practice/trust-ledger/{$deposit}/reverse")->assertUnprocessable()
        ->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'below zero'));
    $this->postJson("/api/v1/practice/trust-ledger/{$withdrawal}/reverse", ['description' => 'Wrong client'])->assertCreated()
        ->assertJsonPath('data.type', 'reversal')->assertJsonPath('data.balance_after', '1000.00');
    $this->postJson("/api/v1/practice/trust-ledger/{$withdrawal}/reverse")->assertUnprocessable();
    $reversal = PracticeTrustTransaction::query()->where('type', 'reversal')->firstOrFail();
    $this->postJson("/api/v1/practice/trust-ledger/{$reversal->id}/reverse")->assertUnprocessable();

    expect(fn () => PracticeTrustTransaction::query()->findOrFail($deposit)->update(['amount' => '1.00']))->toThrow(LogicException::class);
    expect(fn () => PracticeTrustTransaction::query()->findOrFail($deposit)->delete())->toThrow(LogicException::class);
});

test('billable expenses are recorded on a matter and a billed one is locked', function (): void {
    $id = $this->postJson('/api/v1/practice/expenses', [
        'legal_case_id' => $this->case->id, 'expense_date' => '2027-06-20', 'category' => 'court_fee', 'description' => 'Filing fee', 'amount' => '85.50',
    ])->assertCreated()->assertJsonPath('data.amount', '85.50')->assertJsonPath('data.is_billable', true)->json('data.id');

    $this->postJson('/api/v1/practice/expenses', ['legal_case_id' => $this->case->id, 'expense_date' => '2027-06-20', 'category' => 'bogus', 'description' => 'x', 'amount' => '1'])
        ->assertUnprocessable()->assertJsonValidationErrors('category');
    $this->putJson("/api/v1/practice/expenses/{$id}", ['expense_date' => '2027-06-21', 'category' => 'travel', 'description' => 'Train', 'amount' => '40.00', 'is_billable' => false])
        ->assertOk()->assertJsonPath('data.category', 'travel');
    $this->getJson('/api/v1/practice/expenses?legal_case_id='.$this->case->id.'&is_billed=0')->assertOk()->assertJsonCount(1, 'data');

    PracticeExpense::query()->whereKey($id)->update(['is_billed' => true]);
    $this->putJson("/api/v1/practice/expenses/{$id}", ['expense_date' => '2027-06-21', 'category' => 'travel', 'description' => 'Train', 'amount' => '41.00'])->assertUnprocessable();
    $this->deleteJson("/api/v1/practice/expenses/{$id}")->assertUnprocessable();
});

test('a conflicts check finds opposing and related parties and gates the acceptance of a proposal', function (): void {
    $this->postJson('/api/v1/practice/case-parties', ['legal_case_id' => $this->case->id, 'name' => 'Gamma Trading SA', 'role' => 'opposing_party'])->assertCreated();

    $this->postJson('/api/v1/practice/conflict-checks', ['name' => 'Delta Services', 'role' => 'client'])->assertCreated()->assertJsonPath('data.result', 'clear');
    $this->postJson('/api/v1/practice/conflict-checks', ['name' => 'gamma trading', 'role' => 'opposing_party'])->assertCreated()->assertJsonPath('data.result', 'review');
    $conflict = $this->postJson('/api/v1/practice/conflict-checks', ['name' => 'Gamma Trading', 'role' => 'client'])->assertCreated()
        ->assertJsonPath('data.result', 'conflict')->assertJsonPath('data.matches.0.case_number', 'F-1')->json('data.id');
    $this->postJson('/api/v1/practice/conflict-checks', ['name' => 'Alfa Holding', 'role' => 'opposing_party'])->assertCreated()->assertJsonPath('data.result', 'conflict');
    $this->getJson('/api/v1/practice/conflict-checks')->assertOk()->assertJsonCount(4, 'data');

    $proposal = $this->postJson('/api/v1/practice/fee-proposals', [
        'client_id' => $this->other->id, 'title' => 'Lease review', 'fee_type' => 'hourly', 'hourly_rate' => '250.00', 'estimated_hours' => '12.50', 'valid_until' => '2027-07-31',
    ])->assertCreated()->assertJsonPath('data.number', 'FP-2027-0001')->assertJsonPath('data.estimated_total', '3125.00')->json('data.id');
    $this->postJson('/api/v1/practice/fee-proposals', ['client_id' => $this->other->id, 'title' => 'Bad', 'fee_type' => 'hourly'])->assertUnprocessable()->assertJsonValidationErrors(['hourly_rate', 'estimated_hours']);
    $this->putJson("/api/v1/practice/fee-proposals/{$proposal}", ['fee_type' => 'fixed', 'fixed_fee' => '2800.00'])->assertOk()
        ->assertJsonPath('data.estimated_total', '2800.00')->assertJsonPath('data.hourly_rate', null);

    $this->postJson("/api/v1/practice/fee-proposals/{$proposal}/accept", ['conflict_check_id' => $conflict])->assertUnprocessable()
        ->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'Only a sent'));
    $this->postJson("/api/v1/practice/fee-proposals/{$proposal}/send")->assertOk()->assertJsonPath('data.status', 'sent');
    $this->putJson("/api/v1/practice/fee-proposals/{$proposal}", ['title' => 'Changed'])->assertUnprocessable();

    $this->postJson("/api/v1/practice/fee-proposals/{$proposal}/accept", ['conflict_check_id' => $conflict])->assertUnprocessable()
        ->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'reason for proceeding'));

    $clear = $this->postJson('/api/v1/practice/conflict-checks', ['name' => 'Beta Immobiliare SA', 'role' => 'client', 'contact_id' => $this->other->id])->assertCreated()->json('data.id');
    $caseId = $this->postJson("/api/v1/practice/fee-proposals/{$proposal}/accept", ['conflict_check_id' => $clear])->assertOk()
        ->assertJsonPath('data.status', 'accepted')->json('data.legal_case_id');
    $opened = LegalCase::query()->findOrFail($caseId);
    expect($opened->case_number)->toBe('M-2027-0001')->and((string) $opened->client_id)->toBe((string) $this->other->id);
    $this->getJson('/api/v1/practice/case-parties?legal_case_id='.$caseId)->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/practice/fee-proposals/{$proposal}/decline")->assertUnprocessable();
});

test('an expired proposal cannot be accepted and a sent one can be declined', function (): void {
    $make = fn (string $validUntil) => $this->postJson('/api/v1/practice/fee-proposals', ['client_id' => $this->client->id, 'title' => 'Retainer', 'fee_type' => 'fixed', 'fixed_fee' => '900.00', 'valid_until' => $validUntil])->assertCreated()->json('data.id');
    $check = $this->postJson('/api/v1/practice/conflict-checks', ['name' => 'Nobody Here', 'role' => 'client'])->assertCreated()->json('data.id');

    $expired = $make('2027-06-01');
    $this->postJson("/api/v1/practice/fee-proposals/{$expired}/send")->assertOk();
    $this->postJson("/api/v1/practice/fee-proposals/{$expired}/accept", ['conflict_check_id' => $check])->assertUnprocessable()
        ->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'expired'));

    $open = $make('2027-12-01');
    $this->postJson("/api/v1/practice/fee-proposals/{$open}/send")->assertOk();
    $this->postJson("/api/v1/practice/fee-proposals/{$open}/decline", ['notes' => 'Too expensive'])->assertOk()->assertJsonPath('data.status', 'declined');
    $this->getJson('/api/v1/practice/fee-proposals?status=declined')->assertOk()->assertJsonCount(1, 'data');
});

test('the practice tools stay inside the active company', function (): void {
    ($this->trust)()->assertCreated();
    $stranger = verticalTenant();
    $intruder = verticalMember($stranger, 'admin');
    verticalApi($intruder, $stranger);

    $this->getJson('/api/v1/practice/trust-ledger')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/practice/trust-ledger', ['type' => 'deposit', 'client_id' => $this->client->id, 'occurred_on' => '2027-06-26', 'amount' => '5.00'])->assertUnprocessable();
});
