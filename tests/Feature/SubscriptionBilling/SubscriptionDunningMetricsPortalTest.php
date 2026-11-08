<?php

declare(strict_types=1);

use App\Jobs\SendPaymentReminderEmail;
use App\Services\Portal\PortalTokenService;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\SubscriptionBilling\Models\DunningRecord;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Tests\Feature\SubscriptionBilling\Support\SubscriptionScenario;

uses(RefreshDatabase::class);

/**
 * Dunning (failed collections, retries, escalation through the payment reminders), the dunning policy, billing runs,
 * revenue contracts, metrics, forecast and the read-only customer portal.
 */
beforeEach(function (): void {
    Queue::fake();
    $this->travelTo('2026-03-01 09:00:00');
    $this->tenant = SubscriptionScenario::company();
    $this->user = SubscriptionScenario::actingAs($this->tenant);
    $this->customer = SubscriptionScenario::customer($this->tenant, 'ap@example.it');
    $this->plan = SubscriptionScenario::plan($this->tenant);
    $this->contract = $this->postJson('/api/v1/sub/contracts', ['customer_id' => $this->customer->id, 'plan_id' => $this->plan->id, 'start_date' => '2026-03-01', 'payment_terms_days' => 10])->json('data.id');
    $this->postJson("/api/v1/sub/contracts/{$this->contract}/bill")->assertOk();
    $this->invoiceId = (string) InvoiceSchedule::query()->where('subscription_id', $this->contract)->value('invoice_id');
});

test('a failed collection opens a dunning case, schedules a retry and marks the contract past due', function (): void {
    $this->postJson("/api/v1/sub/contracts/{$this->contract}/collection-failures", [])->assertUnprocessable()->assertJsonValidationErrors('invoice_id');
    $this->postJson("/api/v1/sub/contracts/{$this->contract}/collection-failures", ['invoice_id' => (string) Str::uuid()])->assertUnprocessable();

    $this->postJson("/api/v1/sub/contracts/{$this->contract}/collection-failures", ['invoice_id' => $this->invoiceId, 'reason' => 'SEPA R01 insufficient funds'])->assertCreated()
        ->assertJsonPath('data.retry_count', 1)
        ->assertJsonPath('data.last_failure_reason', 'SEPA R01 insufficient funds');
    $this->getJson("/api/v1/sub/contracts/{$this->contract}")->assertJsonPath('data.status', 'past_due');

    // The retry is announced to Treasury once it is due.
    $this->travelTo('2026-03-05 09:00:00');
    $this->postJson('/api/v1/subscriptions/dunning/run')->assertOk()->assertJsonPath('data.retries_announced', 1);
    expect(TransactionalOutbox::query()->where('event_type', 'subscription.collection.retry_due')->count())->toBe(1);

    $this->getJson("/api/v1/sub/contracts/{$this->contract}/dunning")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.next_retry_at', null);
});

test('overdue invoices escalate to reminders, suspension and cancellation, and payment resolves the case', function (): void {
    $this->putJson('/api/v1/subscriptions/dunning-policy', ['policy_name' => 'Default', 'soft_reminder_days' => 10, 'formal_notice_days' => 5, 'suspension_days' => 20, 'termination_days' => 30])
        ->assertUnprocessable()->assertJsonValidationErrors('formal_notice_days');
    $this->putJson('/api/v1/subscriptions/dunning-policy', ['policy_name' => 'Default', 'soft_reminder_days' => 3, 'formal_notice_days' => 10, 'suspension_days' => 20, 'termination_days' => 30])->assertOk()
        ->assertJsonPath('data.suspension_days', 20);
    $this->getJson('/api/v1/subscriptions/dunning-policy')->assertOk()->assertJsonPath('data.termination_days', 30);

    // Due 2026-03-11: 4 days late -> reminder through the payment reminders of the invoicing.
    $this->travelTo('2026-03-15 09:00:00');
    $this->postJson('/api/v1/subscriptions/dunning/run')->assertOk()->assertJsonPath('data.escalations', 1);
    Queue::assertPushed(SendPaymentReminderEmail::class, 1);
    $this->postJson('/api/v1/subscriptions/dunning/run')->assertOk()->assertJsonPath('data.escalations', 0);

    $this->travelTo('2026-04-01 09:00:00');
    $this->postJson('/api/v1/subscriptions/dunning/run')->assertOk();
    $this->getJson("/api/v1/sub/contracts/{$this->contract}")->assertJsonPath('data.status', 'suspended');
    expect(DunningRecord::query()->value('dunning_level'))->toBe(3);

    SalesInvoice::query()->whereKey($this->invoiceId)->update(['payment_status' => 'paid']);
    $this->postJson('/api/v1/subscriptions/dunning/run')->assertOk()->assertJsonPath('data.resolved', 1);
    $this->getJson("/api/v1/sub/contracts/{$this->contract}")->assertJsonPath('data.status', 'active');
});

test('an invoice unpaid past the termination days cancels the contract for non-payment', function (): void {
    $this->travelTo('2026-05-01 09:00:00');
    $this->postJson('/api/v1/subscriptions/dunning/run')->assertOk();

    $this->getJson("/api/v1/sub/contracts/{$this->contract}")->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancellation_reason_code', 'non_payment');
});

test('billing runs and revenue contracts are listed and shown', function (): void {
    $run = $this->postJson('/api/v1/subscriptions/billing-runs', ['run_date' => '2026-03-01'])->assertOk()->json('data.id');
    $this->postJson('/api/v1/subscriptions/billing-runs', ['run_date' => '2030-01-01', 'lookahead_days' => 500])->assertUnprocessable()->assertJsonValidationErrors(['run_date', 'lookahead_days']);

    $this->getJson('/api/v1/subscriptions/billing-runs')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson("/api/v1/subscriptions/billing-runs/{$run}")->assertOk()->assertJsonPath('data.status', 'completed');

    $contracts = $this->getJson('/api/v1/subscriptions/revenue-contracts')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/subscriptions/revenue-contracts/'.$contracts->json('data.0.id'))->assertOk()
        ->assertJsonPath('data.total_transaction_price', '100.00')
        ->assertJsonPath('data.performance_obligations.0.source_document_id', $this->invoiceId)
        ->assertJsonCount(1, 'data.performance_obligations.0.schedules');
    $this->postJson('/api/v1/subscriptions/revenue-recognition/recognize', ['period_date' => '2099-01-01'])->assertUnprocessable()->assertJsonValidationErrors('period_date');
});

test('metrics report MRR, ARR, movements, churn and LTV from the versions', function (): void {
    $second = SubscriptionScenario::customer($this->tenant);
    $other = $this->postJson('/api/v1/sub/contracts', ['customer_id' => $second->id, 'plan_id' => $this->plan->id, 'start_date' => '2026-03-01'])->json('data.id');
    $this->postJson("/api/v1/sub/contracts/{$other}/cancel", ['reason' => 'Closing', 'reason_code' => 'business_closed', 'refund' => false])->assertOk();

    $metrics = $this->getJson('/api/v1/subscriptions/metrics?window_days=30')->assertOk();
    expect($metrics->json('data.mrr'))->toBe('100.00')
        ->and($metrics->json('data.arr'))->toBe('1200.00')
        ->and($metrics->json('data.active_subscriptions'))->toBe(1)
        ->and($metrics->json('data.mrr_movements.new'))->toBe('200.00')
        ->and($metrics->json('data.mrr_movements.churn'))->toBe('-100.00')
        ->and($metrics->json('data.customers_lost'))->toBe(1)
        ->and($metrics->json('data.arpa'))->toBe('100.00');

    $this->getJson('/api/v1/subscriptions/metrics?window_days=0')->assertUnprocessable()->assertJsonValidationErrors('window_days');
});

test('the forecast projects the invoices of the live contracts at their current prices', function (): void {
    $forecast = $this->getJson('/api/v1/subscriptions/forecast?months=3')->assertOk();

    expect($forecast->json('data.monthly_breakdown'))->toBe(['2026-03' => '0.00', '2026-04' => '100.00', '2026-05' => '100.00'])
        ->and($forecast->json('data.projected_cash_inflow'))->toBe('200.00');
    $this->getJson('/api/v1/subscriptions/forecast?months=99')->assertUnprocessable();
});

test('the customer portal lists the subscriptions of the customer of the link only', function (): void {
    $intruder = SubscriptionScenario::customer($this->tenant);
    $this->postJson('/api/v1/sub/contracts', ['customer_id' => $intruder->id, 'plan_id' => $this->plan->id, 'start_date' => '2026-03-01'])->assertCreated();
    $token = app(PortalTokenService::class)->createToken($this->customer)['token'];
    $scoped = app(PortalTokenService::class)->createToken($this->customer, 'dashboard', SalesInvoice::query()->findOrFail($this->invoiceId))['token'];

    $this->app['auth']->forgetGuards();
    $response = $this->getJson("/api/v1/portal/subscriptions/{$token}")->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.id'))->toBe($this->contract)
        ->and($response->json('data.0.invoices.0.total_gross'))->toBe('122.00');

    $this->getJson("/api/v1/portal/subscriptions/{$scoped}")->assertForbidden();
    $this->getJson('/api/v1/portal/subscriptions/not-a-token')->assertNotFound();
});
