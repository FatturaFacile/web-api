<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\SubscriptionBilling\Jobs\ProcessSubscriptionIndexationJob;
use Modules\SubscriptionBilling\Mail\SubscriptionIndexationNoticeMail;
use Modules\SubscriptionBilling\Models\IndexationAdjustment;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionVersion;
use Modules\SubscriptionBilling\Support\Money;
use Tests\Feature\SubscriptionBilling\Support\SubscriptionScenario;

uses(RefreshDatabase::class);

/**
 * Annual inflation indexation (ISTAT FOI, BFS LIK) of the recurring fees: index series, clause, recalculation sheet,
 * ratio, cap, no-decrease floor, idempotency, customer notice, billing of the indexed fee and churn reasons.
 */
beforeEach(function (): void {
    $this->travelTo('2026-11-01 09:00:00');
    $this->tenant = SubscriptionScenario::company();
    $this->user = SubscriptionScenario::actingAs($this->tenant);
    $this->customer = SubscriptionScenario::customer($this->tenant, 'cliente@example.test');
    $this->plan = SubscriptionScenario::plan($this->tenant, ['base_price' => '90.0000']);

    $this->record = fn (string $type, string $month, float $value) => $this->postJson('/api/v1/subscriptions/indexation/indices', ['index_type' => $type, 'reference_month' => $month, 'index_value' => $value])->assertCreated();
    $this->contract = fn (array $extra = [], string $start = '2025-11-01'): string => $this->postJson('/api/v1/sub/contracts', array_merge([
        'customer_id' => $this->customer->id,
        'plan_id' => $this->plan->id,
        'start_date' => $start,
        'indexation_type' => 'istat_foi',
        'indexation_base_month' => '2025-10',
    ], $extra))->assertCreated()->json('data.id');
});

test('the index series is recorded per month, corrected in place and validated', function (): void {
    ($this->record)('istat_foi', '2026-10', 120.4);
    ($this->record)('istat_foi', '2026-10', 120.5);
    ($this->record)('lik', '2026-10', 107.2);

    $this->getJson('/api/v1/subscriptions/indexation/indices?index_type=istat_foi')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference_month', '2026-10')
        ->assertJsonPath('data.0.index_value', '120.5000');

    $this->postJson('/api/v1/subscriptions/indexation/indices', ['index_type' => 'cpi', 'reference_month' => '2026-10', 'index_value' => 1])->assertUnprocessable()->assertJsonValidationErrors('index_type');
    $this->postJson('/api/v1/subscriptions/indexation/indices', ['index_type' => 'lik', 'reference_month' => '2026-13', 'index_value' => 0])->assertUnprocessable();
});

test('a contract is created with its indexation clause and a payment method qr_bill', function (): void {
    $id = ($this->contract)(['indexation_type' => 'lik', 'indexation_ratio_pct' => 75, 'indexation_cap_pct' => 3, 'payment_method' => 'qr_bill']);

    $this->getJson("/api/v1/sub/contracts/{$id}")->assertOk()
        ->assertJsonPath('data.indexation_type', 'lik')
        ->assertJsonPath('data.indexation_ratio_pct', '75.00')
        ->assertJsonPath('data.indexation_cap_pct', '3.000')
        ->assertJsonPath('data.next_indexation_date', '2026-11-01')
        ->assertJsonPath('data.payment_method', 'qr_bill');

    $this->putJson("/api/v1/sub/contracts/{$id}/indexation", ['indexation_type' => null])->assertOk()->assertJsonPath('data.indexation_type', null);
    $this->putJson("/api/v1/sub/contracts/{$id}/indexation", ['indexation_type' => 'cpi'])->assertUnprocessable();
    $this->putJson("/api/v1/sub/contracts/{$id}/indexation", [])->assertUnprocessable();
});

test('the preview applies the contractual ratio to the variation and shows the fee before and after', function (): void {
    ($this->record)('istat_foi', '2025-10', 120.0);
    ($this->record)('istat_foi', '2026-10', 124.8); // +4.0%
    $id = ($this->contract)(['indexation_ratio_pct' => 75]);

    $this->getJson("/api/v1/sub/contracts/{$id}/indexation/preview")->assertOk()
        ->assertJsonPath('data.variation_pct', '4.0000')
        ->assertJsonPath('data.applied_pct', '3.0000')
        ->assertJsonPath('data.old_recurring_amount', '90.00')
        ->assertJsonPath('data.new_recurring_amount', '92.70')
        ->assertJsonPath('data.base_month', '2025-10-01')
        ->assertJsonPath('data.reference_month', '2026-10-01');

    expect(IndexationAdjustment::query()->count())->toBe(0);
});

test('applying the indexation updates the fee and the MRR, versions the change, notifies the customer and is idempotent', function (): void {
    Mail::fake();
    ($this->record)('istat_foi', '2025-10', 120.0);
    ($this->record)('istat_foi', '2026-10', 124.8);
    $id = ($this->contract)(['indexation_ratio_pct' => 75]);

    $this->postJson("/api/v1/sub/contracts/{$id}/indexation/apply", ['effective_date' => '2026-11-01'])->assertCreated()
        ->assertJsonPath('data.applied_pct', '3.0000')
        ->assertJsonPath('data.new_recurring_amount', '92.70')
        ->assertJsonPath('data.calculation_sheet.items.0.new_amount', '92.70');

    $subscription = Subscription::query()->findOrFail($id);
    expect(Money::round2((string) $subscription->getRawOriginal('mrr')))->toBe('92.70')
        ->and(Money::round2((string) $subscription->getRawOriginal('arr')))->toBe('1112.40')
        ->and($subscription->indexation_base_month->toDateString())->toBe('2026-10-01')
        ->and($subscription->next_indexation_date->toDateString())->toBe('2027-11-01');

    $version = SubscriptionVersion::query()->where('subscription_id', $id)->where('change_type', 'indexation')->firstOrFail();
    expect(Money::round2((string) $version->getRawOriginal('mrr_before')))->toBe('90.00')
        ->and(Money::round2((string) $version->getRawOriginal('mrr_after')))->toBe('92.70')
        ->and(Money::round2((string) $version->getRawOriginal('mrr_delta')))->toBe('2.70');

    Mail::assertSent(SubscriptionIndexationNoticeMail::class, 1);
    Mail::assertSent(SubscriptionIndexationNoticeMail::class, fn ($mail): bool => $mail->hasTo('cliente@example.test'));
    expect(IndexationAdjustment::query()->firstOrFail()->notified_at)->not->toBeNull();

    // The same cycle is never indexed twice, nor notified twice.
    $this->postJson("/api/v1/sub/contracts/{$id}/indexation/apply", ['effective_date' => '2026-11-01'])->assertCreated();
    expect(IndexationAdjustment::query()->count())->toBe(1);
    Mail::assertSent(SubscriptionIndexationNoticeMail::class, 1);

    $this->getJson("/api/v1/subscriptions/indexation/adjustments?subscription_id={$id}")->assertOk()->assertJsonCount(1, 'data');
});

test('the contractual cap limits the increase and a falling index never lowers the fee', function (): void {
    ($this->record)('lik', '2025-10', 107.0);
    ($this->record)('lik', '2026-10', 110.21); // +3.0%
    $capped = ($this->contract)(['indexation_type' => 'lik', 'indexation_cap_pct' => 2]);

    $this->getJson("/api/v1/sub/contracts/{$capped}/indexation/preview")->assertOk()
        ->assertJsonPath('data.variation_pct', '3.0000')
        ->assertJsonPath('data.applied_pct', '2.0000')
        ->assertJsonPath('data.cap_applied', true)
        ->assertJsonPath('data.new_recurring_amount', '91.80');

    ($this->record)('lik', '2026-10', 105.0); // deflation
    $this->getJson("/api/v1/sub/contracts/{$capped}/indexation/preview")->assertOk()
        ->assertJsonPath('data.applied_pct', '0.0000')
        ->assertJsonPath('data.new_recurring_amount', '90.00');
});

test('a missing index is reported as a validation error and the nightly job skips the contract until it is published', function (): void {
    $id = ($this->contract)();
    $this->getJson("/api/v1/sub/contracts/{$id}/indexation/preview")->assertUnprocessable();

    $this->postJson('/api/v1/subscriptions/indexation/process', ['as_of' => '2026-11-01'])->assertOk()
        ->assertJsonPath('data.applied', 0)
        ->assertJsonCount(1, 'data.skipped')
        ->assertJsonPath('data.skipped.0.subscription_id', $id);

    ($this->record)('istat_foi', '2025-10', 100.0);
    ($this->record)('istat_foi', '2026-10', 102.0);

    $this->postJson('/api/v1/subscriptions/indexation/process', ['as_of' => '2026-11-01'])->assertOk()->assertJsonPath('data.applied', 1);
    expect(Money::round2((string) Subscription::query()->findOrFail($id)->getRawOriginal('mrr')))->toBe('91.80');
});

test('the nightly job indexes before the billing run so the same night invoices the indexed fee', function (): void {
    Mail::fake();
    ($this->record)('istat_foi', '2025-10', 100.0);
    ($this->record)('istat_foi', '2026-10', 104.0);
    // First indexation due today, the first cycle of the contract is billed after it.
    $id = ($this->contract)(['indexation_base_month' => '2025-10'], '2026-11-01');
    $this->putJson("/api/v1/sub/contracts/{$id}/indexation", ['indexation_type' => 'istat_foi', 'indexation_base_month' => '2025-10', 'next_indexation_date' => '2026-11-01'])->assertOk();

    dispatch_sync(new ProcessSubscriptionIndexationJob((string) $this->tenant->id));
    $this->postJson("/api/v1/sub/contracts/{$id}/bill")->assertOk();

    $schedule = InvoiceSchedule::query()->where('subscription_id', $id)->firstOrFail();
    expect(Money::round2((string) $schedule->getRawOriginal('net_amount')))->toBe('93.60');
});

test('the indexation job is scheduled before the billing run', function (): void {
    $events = collect(app(Schedule::class)->events());
    $indexation = $events->first(fn ($event): bool => $event->description === 'subscriptions:indexation');
    $billing = $events->first(fn ($event): bool => $event->description === 'subscriptions:billing-run');

    expect($indexation)->not->toBeNull()
        ->and($billing)->not->toBeNull()
        ->and($indexation->expression)->toBe('55 1 * * *');
});

test('the metrics break the cancellations of the window down by reason code with the MRR lost', function (): void {
    $price = ($this->contract)(['indexation_type' => null], '2026-09-01');
    $competitor = ($this->contract)(['indexation_type' => null], '2026-09-01');
    $stays = ($this->contract)(['indexation_type' => null], '2026-09-01');

    $this->postJson("/api/v1/sub/contracts/{$price}/cancel", ['reason' => 'Too costly', 'reason_code' => 'too_expensive'])->assertOk();
    $this->postJson("/api/v1/sub/contracts/{$competitor}/cancel", ['reason' => 'Moved', 'reason_code' => 'switched_competitor'])->assertOk();

    $metrics = $this->getJson('/api/v1/subscriptions/metrics?window_days=30')->assertOk()->json('data');

    expect($metrics['churn_reasons'])->toHaveCount(2)
        ->and(collect($metrics['churn_reasons'])->pluck('reason_code')->sort()->values()->all())->toBe(['switched_competitor', 'too_expensive'])
        ->and($metrics['churn_reasons'][0]['subscriptions'])->toBe(1)
        ->and($metrics['churn_reasons'][0]['lost_mrr'])->toBe('90.00')
        ->and($metrics['churn_reasons'][0]['share'])->toBe('0.5000')
        ->and($stays)->not->toBeEmpty();
});

test('the indexation endpoints are tenant scoped and need the subscriptions write access', function (): void {
    ($this->record)('istat_foi', '2025-10', 100.0);
    $other = SubscriptionScenario::company('Altra Srl');
    SubscriptionScenario::actingAs($other);

    $this->getJson('/api/v1/subscriptions/indexation/indices')->assertOk()->assertJsonCount(0, 'data');

    $viewer = SubscriptionScenario::actingAs($other, 'viewer');
    $this->postJson('/api/v1/subscriptions/indexation/indices', ['index_type' => 'lik', 'reference_month' => '2026-10', 'index_value' => 100])->assertForbidden();
    expect($viewer)->not->toBeNull();
});
