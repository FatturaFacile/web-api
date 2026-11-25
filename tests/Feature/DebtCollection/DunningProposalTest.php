<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\DebtCollection\Models\PaymentPlan;
use Modules\Invoicing\Models\ReceivableItem;

require_once __DIR__.'/DebtCollectionSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    dcSetUp($this);
    $this->customer = dcCustomer($this, 'Muster AG');
});

it('proposes the courtesy reminder first for an item that is overdue and has never been dunned', function (): void {
    dcItem($this, $this->customer, 'INV-1', '500.00', '2025-01-01');

    $item = dcProposal($this, '2025-01-16')['INV-1'];

    expect($item)->toMatchArray(['status' => 'proposed', 'proposed_level' => 0, 'days_overdue' => 15, 'jurisdiction' => 'CH', 'interest_amount' => '0.00', 'fee_amount' => '0.00']);
});

it('does not propose an item that is not yet overdue enough for any level', function (): void {
    dcItem($this, $this->customer, 'INV-2', '500.00', '2025-01-10');

    expect(dcProposal($this, '2025-01-05'))->not->toHaveKey('INV-2');
});

it('waits for the deadline and the waiting days after the previous notice before proposing the next level', function (): void {
    $item = dcItem($this, $this->customer, 'INV-3', '500.00', '2025-01-01');
    dcCase($this, $item, 0, '2025-01-10', '2025-01-17');

    $waiting = dcProposal($this, '2025-01-18')['INV-3'];
    $due = dcProposal($this, '2025-01-21')['INV-3'];

    expect($waiting)->toMatchArray(['status' => 'waiting', 'waiting_until' => '2025-01-20'])
        ->and($due)->toMatchArray(['status' => 'proposed', 'proposed_level' => 1]);
});

it('prices level 2 with exact interest on the actual days and the administrative fee', function (): void {
    $item = dcItem($this, $this->customer, 'INV-4', '1000.00', '2025-01-01');
    dcCase($this, $item, 1, '2025-01-20', '2025-01-30');

    $response = $this->getJson('/api/v1/debt-collection/proposals?as_of=2025-02-15')->assertOk();
    $row = $response->json('data.items.0');
    $group = $response->json('data.groups.0');

    expect($row)->toMatchArray(['proposed_level' => 2, 'interest_days' => 45, 'interest_amount' => '6.16', 'fee_amount' => '20.00', 'interest_rate_percent' => '5.0000'])
        ->and($group)->toMatchArray(['level' => 2, 'principal' => '1000.00', 'interest' => '6.16', 'fee' => '20.00', 'total' => '1026.16', 'new_due_date' => '2025-02-25']);
});

it('lists, flagged and never proposed, the items in dispute, under a payment plan, on legal hold or below the threshold', function (): void {
    $disputed = dcItem($this, $this->customer, 'INV-D', '300.00', '2025-01-01', 'CHF', ['status' => ReceivableItem::StatusDisputed]);
    dcItem($this, $this->customer, 'INV-P', '300.00', '2025-01-01');
    $other = dcCustomer($this, 'Hold GmbH');
    dcItem($this, $other, 'INV-H', '300.00', '2025-01-01');

    treasuryInTenant($this->tenant, fn () => PaymentPlan::create(['customer_id' => $this->customer->id, 'currency' => 'CHF', 'total_amount' => '300.00', 'start_date' => '2025-01-01', 'end_date' => '2025-06-01', 'status' => 'active', 'receivable_item_ids' => [], 'installments' => []]));
    $this->postJson('/api/v1/debt-collection/exclusions', ['customer_id' => $other->id, 'type' => 'legal_hold', 'reason' => 'Lawyer instructed'])->assertCreated();

    $rows = dcProposal($this, '2025-01-16');

    expect($rows['INV-D'])->toMatchArray(['status' => 'excluded', 'exclusion_reason' => 'dispute'])
        ->and($rows['INV-P'])->toMatchArray(['status' => 'excluded', 'exclusion_reason' => 'payment_plan'])
        ->and($rows['INV-H'])->toMatchArray(['status' => 'excluded', 'exclusion_reason' => 'legal_hold']);

    $visible = collect($this->getJson('/api/v1/debt-collection/proposals?as_of=2025-01-16')->assertOk()->json('data.items'));

    expect($visible)->toHaveCount(0);
});

it('stops excluding an item once the exclusion is released with a note by an owner', function (): void {
    dcItem($this, $this->customer, 'INV-R', '300.00', '2025-01-01');
    $id = $this->postJson('/api/v1/debt-collection/exclusions', ['customer_id' => $this->customer->id, 'type' => 'manual', 'reason' => 'Key account call'])->assertCreated()->json('data.id');

    expect(dcProposal($this, '2025-01-16')['INV-R']['status'])->toBe('excluded');

    treasuryActor($this, 'owner');
    $this->postJson("/api/v1/debt-collection/exclusions/{$id}/release", ['note' => 'Call done'])->assertOk()->assertJsonPath('data.type', 'manual');

    expect(dcProposal($this, '2025-01-16')['INV-R']['status'])->toBe('proposed');
});

it('treats a debtor in Italy under the Italian policy and interest rate', function (): void {
    $italian = dcCustomer($this, 'Rossi Srl', 'IT');
    $item = dcItem($this, $italian, 'INV-IT', '5000.00', '2024-06-01', 'EUR');
    dcCase($this, $item, 1, '2024-06-20', '2024-06-30');

    $row = dcProposal($this, '2024-09-01')['INV-IT'];

    // 29 days at 12.5% (ECB 4.50 + 8) and 63 days at 12.25% (ECB 4.25 + 8): 49.6575 + 105.7192 = 155.38
    expect($row)->toMatchArray(['jurisdiction' => 'IT', 'proposed_level' => 2, 'interest_days' => 92, 'interest_amount' => '155.38', 'fee_amount' => '20.00']);
});

it('explains the interest period by period', function (): void {
    $this->postJson('/api/v1/debt-collection/calculate', ['amount' => '5000.00', 'due_date' => '2024-06-01', 'currency' => 'EUR', 'jurisdiction' => 'IT', 'as_of' => '2024-09-01', 'level' => 2])
        ->assertOk()
        ->assertJsonPath('data.interest', '155.38')
        ->assertJsonPath('data.interest_days', 92)
        ->assertJsonPath('data.fee', '20.00')
        ->assertJsonCount(2, 'data.segments')
        ->assertJsonPath('data.segments.0.rate_percent', '12.5000')
        ->assertJsonPath('data.segments.1.rate_percent', '12.2500');
});
