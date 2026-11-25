<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/DebtCollectionSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    dcSetUp($this);
    $this->customer = dcCustomer($this, 'Muster AG');
    $this->item = dcItem($this, $this->customer, 'INV-1', '800.00', '2025-01-01');
});

it('requires a reason to exclude a customer or an item', function (): void {
    $this->postJson('/api/v1/debt-collection/exclusions', ['customer_id' => $this->customer->id, 'type' => 'dispute'])->assertUnprocessable();
    $this->postJson('/api/v1/debt-collection/exclusions', ['customer_id' => $this->customer->id, 'type' => 'unknown', 'reason' => 'x'])->assertUnprocessable();

    $this->postJson('/api/v1/debt-collection/exclusions', ['customer_id' => $this->customer->id, 'receivable_item_id' => $this->item->id, 'type' => 'dispute', 'reason' => 'Goods damaged'])
        ->assertCreated()->assertJsonPath('data.type', 'dispute')->assertJsonPath('data.customer_name', 'Muster AG');
});

it('refuses to exclude an item of another customer', function (): void {
    $other = dcCustomer($this, 'Altro AG');

    $this->postJson('/api/v1/debt-collection/exclusions', ['customer_id' => $other->id, 'receivable_item_id' => $this->item->id, 'type' => 'manual', 'reason' => 'x'])
        ->assertNotFound()->assertJsonPath('code', 'item_not_found');
});

it('releases an exclusion once and requires a note', function (): void {
    $id = $this->postJson('/api/v1/debt-collection/exclusions', ['customer_id' => $this->customer->id, 'type' => 'legal_hold', 'reason' => 'Lawyer'])->json('data.id');

    $this->postJson("/api/v1/debt-collection/exclusions/{$id}/release", [])->assertUnprocessable();
    $this->postJson("/api/v1/debt-collection/exclusions/{$id}/release", ['note' => 'Settled'])->assertOk();
    $this->postJson("/api/v1/debt-collection/exclusions/{$id}/release", ['note' => 'Again'])->assertUnprocessable()->assertJsonPath('code', 'exclusion_already_released');

    $this->getJson('/api/v1/debt-collection/exclusions?active=1')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/debt-collection/exclusions')->assertOk()->assertJsonCount(1, 'data');
});

it('records a payment plan that suspends dunning until it is completed', function (): void {
    $plan = $this->postJson('/api/v1/debt-collection/payment-plans', [
        'customer_id' => $this->customer->id, 'currency' => 'CHF', 'total_amount' => '800.00', 'start_date' => '2025-01-10', 'end_date' => '2025-03-10',
        'receivable_item_ids' => [$this->item->id],
        'installments' => [['due_date' => '2025-02-10', 'amount' => '400.00'], ['due_date' => '2025-03-10', 'amount' => '400.00']],
    ])->assertCreated()->assertJsonPath('data.status', 'active')->json('data');

    expect(dcProposal($this, '2025-01-20')['INV-1'])->toMatchArray(['status' => 'excluded', 'exclusion_reason' => 'payment_plan']);

    $this->postJson("/api/v1/debt-collection/payment-plans/{$plan['id']}/default")->assertOk()->assertJsonPath('data.status', 'defaulted');
    $this->postJson("/api/v1/debt-collection/payment-plans/{$plan['id']}/complete")->assertUnprocessable()->assertJsonPath('code', 'plan_not_active');

    expect(dcProposal($this, '2025-01-20')['INV-1']['status'])->toBe('proposed');
});

it('refuses a payment plan whose installments do not add up', function (): void {
    $this->postJson('/api/v1/debt-collection/payment-plans', [
        'customer_id' => $this->customer->id, 'currency' => 'CHF', 'total_amount' => '800.00', 'start_date' => '2025-01-10', 'end_date' => '2025-03-10',
        'installments' => [['due_date' => '2025-02-10', 'amount' => '300.00']],
    ])->assertUnprocessable()->assertJsonPath('code', 'plan_invalid_schedule');
});

it('shows the customer history: summary, notices, exclusions, plans and the chronological timeline', function (): void {
    $this->postJson('/api/v1/debt-collection/proposals/generate', [
        'groups' => [['customer_id' => $this->customer->id, 'currency' => 'CHF', 'level' => 0, 'receivable_item_ids' => [$this->item->id]]],
        'as_of' => '2025-01-16', 'channel' => 'registered_mail', 'dispatch' => true,
    ])->assertCreated();
    $this->postJson('/api/v1/debt-collection/exclusions', ['customer_id' => $this->customer->id, 'type' => 'dispute', 'reason' => 'Disputed quality'])->assertCreated();

    $history = $this->getJson("/api/v1/debt-collection/customers/{$this->customer->id}/history")->assertOk()->json('data');

    expect($history['customer'])->toMatchArray(['id' => $this->customer->id, 'name' => 'Muster AG', 'country' => 'CH'])
        ->and($history['summary'])->toMatchArray(['current_level' => 0, 'active_exclusions' => 1, 'active_plans' => 0])
        ->and($history['summary']['open_overdue'])->toBe([['currency' => 'CHF', 'amount' => '800.00']])
        ->and($history['notices'])->toHaveCount(1)
        ->and($history['notices'][0]['status'])->toBe('sent')
        ->and($history['exclusions'])->toHaveCount(1)
        ->and(array_column($history['timeline'], 'event'))->toContain('notice_generated', 'notice_sent', 'exclusion_created');
});

it('keeps another company out of the module', function (): void {
    $other = Tenant::create(['name' => 'Altra SA', 'legal_name' => 'Altra SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $id = $this->postJson('/api/v1/debt-collection/exclusions', ['customer_id' => $this->customer->id, 'type' => 'manual', 'reason' => 'x'])->json('data.id');

    treasuryActor($this, 'owner', $other);

    $this->getJson('/api/v1/debt-collection/exclusions')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/debt-collection/exclusions/{$id}/release", ['note' => 'x'])->assertNotFound();
});

it('opens the module to the finance and sales-manager roles to read and to finance to write', function (string $role, int $read, int $write): void {
    treasuryActor($this, $role);

    foreach (['lookups', 'dashboard', 'proposals', 'notices', 'batches', 'policies', 'interest-rates', 'templates', 'exclusions', 'payment-plans', 'dossiers'] as $path) {
        $this->getJson("/api/v1/debt-collection/{$path}")->assertStatus($read);
    }

    $this->postJson('/api/v1/debt-collection/exclusions', ['customer_id' => $this->customer->id, 'type' => 'manual', 'reason' => 'x'])->assertStatus($write);
})->with([
    'owner' => ['owner', 200, 201],
    'admin' => ['admin', 200, 201],
    'accountant' => ['accountant', 200, 201],
    'read-only accountant' => ['accountant_readonly', 200, 403],
    'sales manager' => ['sales_manager', 200, 403],
    'sales' => ['sales', 403, 403],
    'plain member' => ['member', 403, 403],
]);

it('requires authentication', function (): void {
    $this->refreshApplication();

    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id)->getJson('/api/v1/debt-collection/proposals')->assertUnauthorized();
});

it('shows the dashboard with the overdue positions, the proposals by level and the exclusions', function (): void {
    dcItem($this, $this->customer, 'INV-2', '200.00', '2025-01-05', 'CHF', ['status' => 'disputed']);

    $dashboard = $this->getJson('/api/v1/debt-collection/dashboard')->assertOk()->json('data');

    expect($dashboard)->toHaveKeys(['overdue', 'proposals', 'excluded', 'notices_30d', 'enforcement_open', 'pec_awaiting_receipts', 'interest_accrued', 'batches_running'])
        ->and($dashboard['overdue'][0])->toMatchArray(['currency' => 'CHF', 'jurisdiction' => 'CH', 'items' => 2, 'amount' => '1000.00'])
        ->and($dashboard['excluded'])->toBe(1);
});

it('lets a company override a seeded interest rate period with its own and keeps the statutory ones read-only', function (): void {
    $global = $this->getJson('/api/v1/debt-collection/interest-rates?jurisdiction=CH')->assertOk()->json('data.0');

    expect($global['is_global'])->toBeTrue();
    $this->putJson("/api/v1/debt-collection/interest-rates/{$global['id']}", ['jurisdiction' => 'CH', 'base_rate_percent' => '6', 'effective_from' => '1912-01-01'])
        ->assertStatus(403)->assertJsonPath('code', 'global_readonly');

    $this->postJson('/api/v1/debt-collection/interest-rates', ['jurisdiction' => 'CH', 'base_rate_percent' => '6', 'effective_from' => '2000-01-01'])->assertCreated()->assertJsonPath('data.is_global', false);

    // From now on the company rows replace the global ones: 6% applies from 2000.
    $this->postJson('/api/v1/debt-collection/calculate', ['amount' => '1000.00', 'due_date' => '2025-01-01', 'currency' => 'CHF', 'jurisdiction' => 'CH', 'as_of' => '2025-01-31'])
        ->assertOk()->assertJsonPath('data.interest', '4.93'); // 1000 x 6% x 30 / 365
});

it('creates the standard policies on first use and validates a policy edited by the user', function (): void {
    $policies = $this->getJson('/api/v1/debt-collection/policies')->assertOk()->json('data');

    expect(array_column($policies, 'jurisdiction'))->toBe(['CH', 'IT'])
        ->and($policies[0]['levels'])->toHaveCount(4);

    $payload = ['name' => 'CH strict', 'jurisdiction' => 'CH', 'is_default' => true, 'interest_basis' => 'actual_360', 'levels' => [
        ['level' => 0, 'min_days_overdue' => 5, 'deadline_days' => 7, 'channels' => ['email']],
        ['level' => 1, 'min_days_overdue' => 3],
    ]];

    $this->postJson('/api/v1/debt-collection/policies', $payload)->assertUnprocessable()->assertJsonPath('code', 'levels_not_ascending');

    $payload['levels'][1]['min_days_overdue'] = 20;
    $this->postJson('/api/v1/debt-collection/policies', $payload)->assertCreated()->assertJsonPath('data.interest_basis', 'actual_360')->assertJsonCount(2, 'data.levels');
});

it('overrides a seeded text with a copy of the company', function (): void {
    $template = $this->getJson('/api/v1/debt-collection/templates?code=level_1&locale=it')->assertOk()->json('data.0');

    expect($template['is_global'])->toBeTrue();

    $copy = $this->putJson("/api/v1/debt-collection/templates/{$template['id']}", ['subject' => 'Sollecito personalizzato', 'body' => 'Testo {{customer_name}}'])->assertOk()->json('data');

    expect($copy['is_global'])->toBeFalse()->and($copy['id'])->not->toBe($template['id'])->and($copy['subject'])->toBe('Sollecito personalizzato');
});
