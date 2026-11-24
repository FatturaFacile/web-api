<?php

declare(strict_types=1);

use App\Jobs\SendSubscriptionRenewalNotice;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\SubscriptionBilling\Contracts\RenewalServiceInterface;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Modules\SubscriptionBilling\Models\PricingTier;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionVersion;
use Tests\Feature\SubscriptionBilling\Support\SubscriptionScenario;

uses(RefreshDatabase::class);

/**
 * The contract endpoints of the subscription engine: creation (trial, alignment, price models), lifecycle, prorated
 * quantity changes, cancellations and renewals.
 */
beforeEach(function (): void {
    $this->travelTo('2026-03-15 09:00:00');
    $this->tenant = SubscriptionScenario::company();
    $this->user = SubscriptionScenario::actingAs($this->tenant);
    $this->customer = SubscriptionScenario::customer($this->tenant);
    $this->plan = SubscriptionScenario::plan($this->tenant);
});

function contractPayload(array $overrides = []): array
{
    return array_merge(['customer_id' => test()->customer->id, 'plan_id' => test()->plan->id, 'start_date' => '2026-03-15'], $overrides);
}

test('a contract is created with a number, a term and its plan item', function (): void {
    $response = $this->postJson('/api/v1/sub/contracts', contractPayload(['contract_term_months' => 12]))->assertCreated();

    expect($response->json('data.subscription_number'))->toBe('SUB-2026-0001')
        ->and($response->json('data.status'))->toBe('active')
        ->and($response->json('data.term_end_date'))->toBe('2027-03-14')
        ->and($response->json('data.current_period_end'))->toBe('2026-04-14')
        ->and($response->json('data.mrr'))->toBe('100.00')
        ->and($response->json('data.items.0.item_type'))->toBe('plan')
        ->and($response->json('data.versions.0.change_type'))->toBe('initial');

    $this->getJson('/api/v1/sub/contracts')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/sub/contracts?status=cancelled')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/sub/contracts/'.$response->json('data.id'))->assertOk()->assertJsonPath('data.plan.name', 'Cloud Basic');
});

test('creation validates the customer, the plan and the items of the company', function (): void {
    $other = SubscriptionScenario::company('Altra Srl');
    $foreignCustomer = SubscriptionScenario::customer($other);
    $foreignPlan = SubscriptionScenario::plan($other);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->postJson('/api/v1/sub/contracts', [])->assertUnprocessable()->assertJsonValidationErrors(['customer_id', 'plan_id', 'start_date']);
    $this->postJson('/api/v1/sub/contracts', contractPayload(['customer_id' => $foreignCustomer->id]))->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    $this->postJson('/api/v1/sub/contracts', contractPayload(['plan_id' => $foreignPlan->id]))->assertUnprocessable()->assertJsonValidationErrors('plan_id');
    $this->postJson('/api/v1/sub/contracts', contractPayload(['quantity' => 0, 'payment_method' => 'bitcoin']))->assertUnprocessable()->assertJsonValidationErrors(['quantity', 'payment_method']);
});

test('a trial is not billed and converts to active on its first billing date', function (): void {
    $id = $this->postJson('/api/v1/sub/contracts', contractPayload(['trial_days' => 14]))->assertCreated()
        ->assertJsonPath('data.status', 'trialing')
        ->assertJsonPath('data.trial_end_date', '2026-03-28')
        ->assertJsonPath('data.next_billing_date', '2026-03-29')
        ->assertJsonPath('data.mrr', '0.00')
        ->json('data.id');

    $this->postJson("/api/v1/sub/contracts/{$id}/bill")->assertOk()->assertJsonCount(0, 'data');

    $this->travelTo('2026-03-29 08:00:00');
    $this->postJson("/api/v1/sub/contracts/{$id}/bill")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/sub/contracts/{$id}")->assertJsonPath('data.status', 'active')->assertJsonPath('data.mrr', '100.00');
});

test('calendar alignment bills the first partial month pro rata, then whole months', function (): void {
    $plan = SubscriptionScenario::plan($this->tenant, ['billing_alignment' => 'calendar', 'base_price' => '310.0000']);
    $id = $this->postJson('/api/v1/sub/contracts', contractPayload(['plan_id' => $plan->id, 'start_date' => '2026-03-15']))->assertCreated()
        ->assertJsonPath('data.current_period_end', '2026-03-31')->json('data.id');

    $this->postJson("/api/v1/sub/contracts/{$id}/bill")->assertOk();
    // 17 of 31 days of March.
    expect((string) InvoiceSchedule::query()->where('subscription_id', $id)->value('net_amount'))->toBe('170.00');

    $this->travelTo('2026-04-01 08:00:00');
    $this->postJson("/api/v1/sub/contracts/{$id}/bill")->assertOk();
    $april = InvoiceSchedule::query()->where('subscription_id', $id)->whereDate('service_period_start', '2026-04-01')->firstOrFail();
    expect($april->service_period_end->toDateString())->toBe('2026-04-30')->and((string) $april->net_amount)->toBe('310.00');
});

test('per seat, tiered and volume plans price the quantity', function (string $model, array $tiers, string $quantity, string $expected): void {
    $plan = SubscriptionScenario::plan($this->tenant, ['pricing_model' => $model, 'base_price' => '10.0000']);

    foreach ($tiers as $index => $tier) {
        PricingTier::create(['tenant_id' => $this->tenant->id, 'plan_id' => $plan->id, 'tier_number' => $index + 1, ...$tier]);
    }

    $id = $this->postJson('/api/v1/sub/contracts', contractPayload(['plan_id' => $plan->id, 'quantity' => $quantity]))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/sub/contracts/{$id}/bill")->assertOk();

    expect((string) InvoiceSchedule::query()->where('subscription_id', $id)->value('net_amount'))->toBe($expected);
})->with([
    'per seat' => ['per_unit', [], '7', '70.00'],
    'tiered' => ['tiered', [['from_quantity' => '0', 'to_quantity' => '10', 'unit_price' => '5', 'flat_fee' => '0'], ['from_quantity' => '10', 'to_quantity' => null, 'unit_price' => '3', 'flat_fee' => '20']], '15', '85.00'],
    'volume' => ['volume', [['from_quantity' => '0', 'to_quantity' => '10', 'unit_price' => '5', 'flat_fee' => '0'], ['from_quantity' => '10', 'to_quantity' => null, 'unit_price' => '3', 'flat_fee' => '20']], '15', '65.00'],
]);

test('a quantity increase is invoiced pro rata and a decrease credited', function (): void {
    $plan = SubscriptionScenario::plan($this->tenant, ['pricing_model' => 'per_unit', 'base_price' => '10.0000']);
    $id = $this->postJson('/api/v1/sub/contracts', contractPayload(['plan_id' => $plan->id, 'quantity' => 10, 'start_date' => '2026-03-01']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/sub/contracts/{$id}/bill")->assertOk();

    $this->postJson("/api/v1/sub/contracts/{$id}/quantity", ['quantity' => 0])->assertUnprocessable()->assertJsonValidationErrors('quantity');
    // 17 days of 31 left: 50 x 17 / 31 = 27.42.
    $this->postJson("/api/v1/sub/contracts/{$id}/quantity", ['quantity' => 15])->assertOk()->assertJsonPath('data.mrr', '150.00');
    expect((string) InvoiceSchedule::query()->where('subscription_id', $id)->where('billing_kind', 'proration')->value('net_amount'))->toBe('27.42');

    $this->postJson("/api/v1/sub/contracts/{$id}/quantity", ['quantity' => 12])->assertOk()->assertJsonPath('data.mrr', '120.00');
    expect((string) InvoiceSchedule::query()->where('subscription_id', $id)->where('billing_kind', 'credit')->value('net_amount'))->toBe('-16.45')
        ->and(SubscriptionVersion::query()->where('subscription_id', $id)->pluck('change_type')->all())->toContain('quantity_increase', 'quantity_decrease');
});

test('upgrades and downgrades refuse a plan in the wrong direction or of another interval', function (): void {
    $id = $this->postJson('/api/v1/sub/contracts', contractPayload())->assertCreated()->json('data.id');
    $cheaper = SubscriptionScenario::plan($this->tenant, ['base_price' => '50.0000']);
    $yearly = SubscriptionScenario::plan($this->tenant, ['base_price' => '1000.0000', 'billing_interval' => 'annual']);

    $this->postJson("/api/v1/sub/contracts/{$id}/upgrade", ['new_plan_id' => $cheaper->id])->assertUnprocessable()->assertJsonValidationErrors('new_plan_id');
    $this->postJson("/api/v1/sub/contracts/{$id}/upgrade", ['new_plan_id' => $yearly->id])->assertUnprocessable()->assertJsonValidationErrors('new_plan_id');
    $this->postJson("/api/v1/sub/contracts/{$id}/downgrade", [])->assertUnprocessable()->assertJsonValidationErrors('new_plan_id');
    $this->postJson("/api/v1/sub/contracts/{$id}/downgrade", ['new_plan_id' => $cheaper->id])->assertOk()->assertJsonPath('data.plan_id', $cheaper->id);
});

test('pause, suspend, reactivate and activate follow the status rules', function (): void {
    $id = $this->postJson('/api/v1/sub/contracts', contractPayload(['start_date' => '2026-03-01']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/sub/contracts/{$id}/bill")->assertOk();

    $this->postJson("/api/v1/sub/contracts/{$id}/pause", ['resume_on' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors('resume_on');
    $this->postJson("/api/v1/sub/contracts/{$id}/pause", ['reason' => 'Holiday', 'resume_on' => '2026-06-10'])->assertOk()
        ->assertJsonPath('data.status', 'paused')->assertJsonPath('data.mrr', '0.00');
    $this->postJson("/api/v1/sub/contracts/{$id}/pause")->assertUnprocessable();
    $this->postJson("/api/v1/sub/contracts/{$id}/activate")->assertUnprocessable();

    // The pause ends by itself: April and May are skipped, June is billed.
    $this->travelTo('2026-06-10 08:00:00');
    $this->postJson("/api/v1/sub/contracts/{$id}/bill")->assertOk()->assertJsonCount(1, 'data');
    expect(InvoiceSchedule::query()->where('subscription_id', $id)->where('status', 'invoiced')->orderBy('service_period_start')->get()->map(fn ($s) => $s->service_period_start->toDateString())->all())
        ->toBe(['2026-03-01', '2026-06-01']);

    $this->postJson("/api/v1/sub/contracts/{$id}/suspend", ['reason' => 'Audit'])->assertOk()->assertJsonPath('data.status', 'paused');
    $this->postJson("/api/v1/sub/contracts/{$id}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');
    $this->postJson("/api/v1/sub/contracts/{$id}/reactivate")->assertUnprocessable();

    Subscription::query()->whereKey($id)->update(['status' => 'pending']);
    $this->postJson("/api/v1/sub/contracts/{$id}/activate")->assertOk()->assertJsonPath('data.status', 'active');
});

test('an immediate cancellation refunds the unused days with a credit note and records the reason', function (): void {
    $id = $this->postJson('/api/v1/sub/contracts', contractPayload(['start_date' => '2026-03-01']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/sub/contracts/{$id}/bill")->assertOk();

    $this->postJson("/api/v1/sub/contracts/{$id}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/sub/contracts/{$id}/cancel", ['reason' => 'x', 'reason_code' => 'bored'])->assertUnprocessable()->assertJsonValidationErrors('reason_code');

    $this->postJson("/api/v1/sub/contracts/{$id}/cancel", ['reason' => 'Moving to another vendor', 'reason_code' => 'switched_competitor'])->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancellation_reason_code', 'switched_competitor')
        ->assertJsonPath('data.mrr', '0.00')
        ->assertJsonPath('data.next_billing_date', null);

    // 17 days of 31 left of 100.
    $credit = InvoiceSchedule::query()->where('subscription_id', $id)->where('billing_kind', 'credit')->firstOrFail();
    expect((string) $credit->net_amount)->toBe('-54.84')
        ->and(SalesInvoice::query()->findOrFail($credit->credit_note_id)->invoice_type)->toBe('credit_note');

    $this->postJson("/api/v1/sub/contracts/{$id}/cancel", ['reason' => 'again'])->assertUnprocessable();
    $this->travelTo('2026-05-01 08:00:00');
    $this->postJson("/api/v1/sub/contracts/{$id}/bill")->assertOk()->assertJsonCount(0, 'data');
});

test('a cancellation at the end of the term respects the notice period of an automatic renewal', function (): void {
    $early = $this->postJson('/api/v1/sub/contracts', contractPayload(['start_date' => '2026-01-01', 'contract_term_months' => 12, 'cancellation_notice_days' => 30]))->json('data.id');
    $this->postJson("/api/v1/sub/contracts/{$early}/cancel", ['reason' => 'Budget', 'cancellation_type' => 'end_of_term', 'reason_code' => 'too_expensive'])->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.cancel_at_period_end', true)
        ->assertJsonPath('data.cancellation_effective_date', '2027-01-01');

    $late = $this->postJson('/api/v1/sub/contracts', contractPayload(['start_date' => '2025-04-01', 'contract_term_months' => 12, 'cancellation_notice_days' => 30]))->json('data.id');
    // Term ends 2026-03-31, the notice deadline (2026-03-01) has passed: one more term.
    $this->postJson("/api/v1/sub/contracts/{$late}/cancel", ['reason' => 'Late', 'cancellation_type' => 'end_of_term'])->assertOk()
        ->assertJsonPath('data.cancellation_effective_date', '2027-04-01');

    $this->postJson("/api/v1/sub/contracts/{$early}/cancel", ['reason' => 'x', 'cancellation_type' => 'end_of_term', 'cancellation_date' => '2026-05-01'])->assertUnprocessable()->assertJsonValidationErrors('cancellation_date');
});

test('a term renews with the price increase of the plan or expires without automatic renewal', function (): void {
    $plan = SubscriptionScenario::plan($this->tenant, ['renewal_price_increase_percent' => '5']);
    $renewing = $this->postJson('/api/v1/sub/contracts', contractPayload(['plan_id' => $plan->id, 'start_date' => '2025-03-01', 'contract_term_months' => 12]))->json('data.id');
    $expiring = $this->postJson('/api/v1/sub/contracts', contractPayload(['start_date' => '2025-03-01', 'contract_term_months' => 12, 'auto_renew' => false]))->json('data.id');
    Subscription::query()->whereKey([$renewing, $expiring])->update(['current_period_start' => '2026-03-01', 'current_period_end' => '2026-03-31']);

    $result = app(RenewalServiceInterface::class)->processExpiringSubscriptions((string) $this->tenant->id);

    expect($result['auto_renewed_count'])->toBe(1)->and($result['expired_count'])->toBe(1);
    $this->getJson("/api/v1/sub/contracts/{$renewing}")->assertJsonPath('data.term_end_date', '2027-02-28')->assertJsonPath('data.mrr', '105.00');
    $this->getJson("/api/v1/sub/contracts/{$expiring}")->assertJsonPath('data.status', 'expired');
});

test('the renewal notice is recorded once inside the notice window', function (): void {
    Queue::fake();
    $customer = SubscriptionScenario::customer($this->tenant, 'billing@example.it');
    $this->postJson('/api/v1/sub/contracts', contractPayload(['customer_id' => $customer->id, 'start_date' => '2025-04-01', 'contract_term_months' => 12, 'renewal_notice_days' => 30]))->assertCreated();

    app(RenewalServiceInterface::class)->processExpiringSubscriptions((string) $this->tenant->id);
    app(RenewalServiceInterface::class)->processExpiringSubscriptions((string) $this->tenant->id);

    Queue::assertPushed(SendSubscriptionRenewalNotice::class, 1);
});

test('the invoices endpoint lists the billed periods with their documents', function (): void {
    $id = $this->postJson('/api/v1/sub/contracts', contractPayload(['start_date' => '2026-02-15']))->json('data.id');
    $this->postJson("/api/v1/sub/contracts/{$id}/bill")->assertOk();

    $this->getJson("/api/v1/sub/contracts/{$id}/invoices")->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.billing_kind', 'recurring')
        ->assertJsonPath('data.0.invoice_number', 'INV-2026-0001')
        ->assertJsonPath('data.1.service_period_start', '2026-03-15')
        ->assertJsonPath('data.1.payment_status', 'unpaid');

    expect(CarbonImmutable::parse(SalesInvoice::query()->where('invoice_number', 'INV-2026-0001')->value('due_date'))->toDateString())->toBe('2026-03-17');
});
