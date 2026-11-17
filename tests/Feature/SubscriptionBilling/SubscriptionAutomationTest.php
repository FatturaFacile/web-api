<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Contracts\RecurringBillingServiceInterface;
use Modules\SubscriptionBilling\Contracts\SubscriptionLifecycleServiceInterface;
use Modules\SubscriptionBilling\DTOs\CancelSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\CreateSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\ExecuteBillingRunDTO;
use Modules\SubscriptionBilling\Mail\SubscriptionIndexationNoticeMail;
use Modules\SubscriptionBilling\Models\IndexationAdjustment;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Modules\SubscriptionBilling\Models\PricingTier;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;
use Modules\SubscriptionBilling\Services\IndexationService;
use Modules\SubscriptionBilling\Services\SubscriptionMetricsService;
use Modules\SubscriptionBilling\Support\BillingCycle;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->tenant);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->plan = SubscriptionPlan::factory()->create([
        'tenant_id' => $this->tenant->id,
        'billing_interval' => 'monthly',
        'pricing_model' => 'flat_fee',
        'base_price' => 90.00,
    ]);

    $this->lifecycle = app(SubscriptionLifecycleServiceInterface::class);
    $this->billing = app(RecurringBillingServiceInterface::class);
    $this->indexation = app(IndexationService::class);

    $this->make = fn (array $overrides = [], ?SubscriptionPlan $plan = null): Subscription => $this->lifecycle->createSubscription(new CreateSubscriptionDTO(
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        customerId: $overrides['customerId'] ?? (string) Str::uuid7(),
        planId: ($plan ?? $this->plan)->id,
        startDate: $overrides['startDate'] ?? '2026-11-01',
        billingTiming: $overrides['billingTiming'] ?? 'arrears',
        billingAlignment: $overrides['billingAlignment'] ?? 'anniversary',
        seats: $overrides['seats'] ?? 1,
        indexationType: $overrides['indexationType'] ?? null,
        indexationRatioPct: $overrides['indexationRatioPct'] ?? 100.0,
        indexationCapPct: $overrides['indexationCapPct'] ?? null,
        indexationBaseMonth: $overrides['indexationBaseMonth'] ?? null,
    ));

    $this->run = fn (string $date, int $lookahead = 0) => $this->billing->executeBillingRun(new ExecuteBillingRunDTO(
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        runDate: $date,
        lookaheadDays: $lookahead,
        executedBy: null,
    ));
});

// ---------------------------------------------------------------- pro-rata ----

test('daily pro-rata is computed on actual calendar days of the cycle', function () {
    $calc = BillingCycle::prorate(
        120.00,
        Carbon::parse('2026-11-18'),
        Carbon::parse('2026-11-30'),
        Carbon::parse('2026-11-01'),
        Carbon::parse('2026-11-30'),
    );

    expect($calc['service_days'])->toBe(13)
        ->and($calc['cycle_days'])->toBe(30)
        ->and($calc['amount'])->toBe(52.00);

    // February of a leap year has 29 days
    $feb = BillingCycle::prorate(290.00, Carbon::parse('2028-02-16'), Carbon::parse('2028-02-29'), Carbon::parse('2028-02-01'), Carbon::parse('2028-02-29'));
    expect($feb['cycle_days'])->toBe(29)->and($feb['amount'])->toBe(140.00);
});

test('calendar bucket aligns to month, quarter, half and year', function () {
    $d = Carbon::parse('2026-08-17');

    expect(BillingCycle::calendarBucket($d, 'monthly')[1]->toDateString())->toBe('2026-08-31')
        ->and(BillingCycle::calendarBucket($d, 'quarterly')[1]->toDateString())->toBe('2026-09-30')
        ->and(BillingCycle::calendarBucket($d, 'semiannual')[1]->toDateString())->toBe('2026-12-31')
        ->and(BillingCycle::calendarBucket($d, 'annual')[0]->toDateString())->toBe('2026-01-01')
        ->and(BillingCycle::periodEnd(Carbon::parse('2026-01-31'), 'monthly')->toDateString())->toBe('2026-02-27');
});

test('calendar-aligned subscription activated mid-month gets a pro-rata first invoice then full cycles', function () {
    $sub = ($this->make)(['startDate' => '2026-11-18', 'billingAlignment' => 'calendar']);

    expect($sub->current_period_end->toDateString())->toBe('2026-11-30');

    $run = ($this->run)('2026-11-30');
    expect($run->successful_invoices_count)->toBe(1)
        ->and((float) $run->total_invoiced_amount)->toBe(39.00); // 90 x 13/30

    $schedule = InvoiceSchedule::where('subscription_id', $sub->id)->firstOrFail();
    expect($schedule->invoice_type)->toBe('initial_prorata')
        ->and($schedule->service_days)->toBe(13)
        ->and($schedule->cycle_days)->toBe(30)
        ->and((float) $schedule->estimated_amount)->toBe(39.00);

    $sub->refresh();
    expect($sub->current_period_start->toDateString())->toBe('2026-12-01')
        ->and($sub->current_period_end->toDateString())->toBe('2026-12-31');

    $second = ($this->run)('2026-12-31');
    expect((float) $second->total_invoiced_amount)->toBe(90.00);
});

test('arrears cancellation mid-period issues a final pro-rata invoice and tracks the reason', function () {
    $sub = ($this->make)(['startDate' => '2026-11-01']);

    $cancelled = $this->lifecycle->cancelSubscription(new CancelSubscriptionDTO(
        subscriptionId: $sub->id,
        reason: 'Too expensive',
        cancellationDate: '2026-11-10',
        category: 'price',
        feedback: 'Found a cheaper alternative',
    ));

    expect($cancelled->cancellation_category)->toBe('price')
        ->and($cancelled->cancellation_feedback)->toBe('Found a cheaper alternative');

    $final = InvoiceSchedule::where('subscription_id', $sub->id)->where('invoice_type', 'final_prorata')->firstOrFail();
    expect($final->service_days)->toBe(10)
        ->and($final->cycle_days)->toBe(30)
        ->and((float) $final->estimated_amount)->toBe(30.00); // 90 x 10/30
});

test('advance billing bills the cycle upfront and credits unused days on early cancellation', function () {
    $sub = ($this->make)(['startDate' => '2026-11-01', 'billingTiming' => 'advance']);

    // First run bills the current cycle in advance (it has not ended yet)
    $run = ($this->run)('2026-11-01');
    expect($run->successful_invoices_count)->toBe(1)
        ->and((float) $run->total_invoiced_amount)->toBe(90.00);

    $sub->refresh();
    expect($sub->current_period_end->toDateString())->toBe('2026-11-30')
        ->and($sub->last_billed_period_end->toDateString())->toBe('2026-11-30');

    // Running again the same day does not bill twice
    expect(($this->run)('2026-11-01')->successful_invoices_count)->toBe(0);

    // Cancelling on Nov 10 credits the 20 unused days
    $this->lifecycle->cancelSubscription(new CancelSubscriptionDTO(subscriptionId: $sub->id, reason: 'Closing business', cancellationDate: '2026-11-10'));

    $credit = InvoiceSchedule::where('subscription_id', $sub->id)->where('status', 'credit_pending')->firstOrFail();
    expect((float) $credit->estimated_amount)->toBe(-60.00)->and($credit->service_days)->toBe(20);
});

test('advance subscription rolls to the next cycle and bills it before it starts', function () {
    $sub = ($this->make)(['startDate' => '2026-11-01', 'billingTiming' => 'advance']);
    ($this->run)('2026-11-01');

    // Nov 30 is the last service day: the December cycle is invoiced in advance
    $run = ($this->run)('2026-11-30');
    expect($run->successful_invoices_count)->toBe(1);

    $sub->refresh();
    expect($sub->current_period_start->toDateString())->toBe('2026-12-01')
        ->and($sub->last_billed_period_end->toDateString())->toBe('2026-12-31');
});

test('billing run issues a real electronic invoice when the customer exists in the CRM', function () {
    $contact = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'country' => 'IT']);
    $sub = ($this->make)(['startDate' => '2026-10-01', 'customerId' => $contact->id]);

    $run = ($this->run)('2026-11-05');
    expect($run->successful_invoices_count)->toBe(1);

    $schedule = InvoiceSchedule::where('subscription_id', $sub->id)->firstOrFail();
    $invoice = Invoice::with('items')->find($schedule->invoice_id);

    expect($invoice)->not->toBeNull()
        ->and($invoice->contact_id)->toBe($contact->id)
        ->and((float) $invoice->total_net)->toBe(90.00)
        ->and((float) $invoice->total_tax)->toBe(19.80)
        ->and($invoice->items)->toHaveCount(1);
});

// ------------------------------------------------------------ pricing plans ----

test('tiered per-seat pricing sums graduated tiers into the recurring fee', function () {
    $tiered = SubscriptionPlan::factory()->create([
        'tenant_id' => $this->tenant->id,
        'billing_interval' => 'monthly',
        'pricing_model' => 'tiered',
        'base_price' => 20.00,
    ]);
    PricingTier::factory()->create(['tenant_id' => $this->tenant->id, 'plan_id' => $tiered->id, 'tier_number' => 1, 'from_quantity' => 1, 'to_quantity' => 10, 'unit_price' => 10.00]);
    PricingTier::factory()->create(['tenant_id' => $this->tenant->id, 'plan_id' => $tiered->id, 'tier_number' => 2, 'from_quantity' => 11, 'to_quantity' => null, 'unit_price' => 8.00]);

    $sub = ($this->make)(['seats' => 15], $tiered);

    // 20 base + 10 x 10 + 5 x 8 = 160
    expect((float) $sub->mrr)->toBe(160.00);

    $perUnit = SubscriptionPlan::factory()->create(['tenant_id' => $this->tenant->id, 'pricing_model' => 'per_unit', 'base_price' => 12.50]);
    expect((float) ($this->make)(['seats' => 4], $perUnit)->mrr)->toBe(50.00);
});

test('qr_bill is an accepted payment method on contract creation', function () {
    $response = $this->postJson('/api/v1/sub/contracts', [
        'customer_id' => (string) Str::uuid7(),
        'plan_id' => $this->plan->id,
        'start_date' => '2026-11-01',
        'payment_method' => 'qr_bill',
        'billing_timing' => 'advance',
        'billing_alignment' => 'calendar',
        'indexation_type' => 'lik',
        'indexation_base_month' => '2026-10',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.payment_method', 'qr_bill')
        ->assertJsonPath('data.billing_timing', 'advance')
        ->assertJsonPath('data.indexation_type', 'lik');
});

// -------------------------------------------------------------- indexation ----

test('annual ISTAT indexation applies ratio, produces the recalculation sheet and notifies the customer', function () {
    Mail::fake();

    $contact = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'cliente@example.test']);
    $sub = ($this->make)([
        'startDate' => '2025-11-01',
        'customerId' => $contact->id,
        'indexationType' => 'istat_foi',
        'indexationRatioPct' => 75.0,
        'indexationBaseMonth' => '2025-10-01',
    ]);

    $this->indexation->recordIndex('istat_foi', '2025-10-01', 120.0, 'Dec 2020=100', 'ISTAT');
    $this->indexation->recordIndex('istat_foi', '2026-10-01', 124.8, 'Dec 2020=100', 'ISTAT'); // +4.0%

    $effective = Carbon::parse('2026-11-01');
    $sheet = $this->indexation->preview($sub, $effective);

    expect($sheet['variation_pct'])->toBe(4.0)
        ->and($sheet['applied_pct'])->toBe(3.0) // 75% of 4%
        ->and($sheet['old_recurring_amount'])->toBe(90.0)
        ->and($sheet['new_recurring_amount'])->toBe(92.7);

    $adjustment = $this->indexation->apply($sub, $effective);

    expect($adjustment->status)->toBe('applied')
        ->and((float) $adjustment->new_recurring_amount)->toBe(92.7)
        ->and($adjustment->calculation_sheet['items'])->toHaveCount(1)
        ->and($adjustment->notified_at)->not->toBeNull();

    $sub->refresh();
    expect((float) $sub->mrr)->toBe(92.7)
        ->and((float) $sub->arr)->toBe(1112.4)
        ->and($sub->indexation_base_month->toDateString())->toBe('2026-10-01')
        ->and($sub->next_indexation_date->toDateString())->toBe('2027-11-01')
        ->and((float) $sub->items()->first()->subtotal_recurring)->toBe(92.7);

    Mail::assertSent(SubscriptionIndexationNoticeMail::class, fn ($mail) => $mail->hasTo('cliente@example.test'));

    // Idempotent for the same cycle
    $again = $this->indexation->apply($sub->fresh(), $effective, notify: false);
    expect($again->id)->toBe($adjustment->id)
        ->and(IndexationAdjustment::where('subscription_id', $sub->id)->count())->toBe(1);
});

test('indexation honours the contractual cap and never decreases the fee on deflation', function () {
    $capped = ($this->make)(['startDate' => '2025-11-01', 'indexationType' => 'lik', 'indexationBaseMonth' => '2025-10-01', 'indexationCapPct' => 2.0]);
    $deflation = ($this->make)(['startDate' => '2025-11-01', 'indexationType' => 'lik', 'indexationBaseMonth' => '2025-10-01']);

    $this->indexation->recordIndex('lik', '2025-10-01', 107.0, 'Dec 2020=100', 'BFS');
    $this->indexation->recordIndex('lik', '2026-10-01', 110.21, 'Dec 2020=100', 'BFS'); // +3.0%

    $sheet = $this->indexation->preview($capped, Carbon::parse('2026-11-01'));
    expect($sheet['variation_pct'])->toBe(3.0)->and($sheet['applied_pct'])->toBe(2.0)->and($sheet['cap_applied'])->toBeTrue();

    $this->indexation->recordIndex('lik', '2026-10-01', 105.0); // deflation
    $flat = $this->indexation->preview($deflation, Carbon::parse('2026-11-01'));
    expect($flat['applied_pct'])->toBe(0.0)->and($flat['new_recurring_amount'])->toBe(90.0);
});

test('nightly indexation processes due contracts and skips those without a published index', function () {
    $due = ($this->make)(['startDate' => '2025-11-01', 'indexationType' => 'istat_foi', 'indexationBaseMonth' => '2025-10-01']);
    $missing = ($this->make)(['startDate' => '2025-11-01', 'indexationType' => 'lik', 'indexationBaseMonth' => '2025-10-01']);

    $this->indexation->recordIndex('istat_foi', '2025-10-01', 100.0);
    $this->indexation->recordIndex('istat_foi', '2026-10-01', 102.0);

    $result = $this->indexation->processDueIndexations($this->tenant->id, Carbon::parse('2026-11-01'));

    expect($result['applied'])->toBe(1)
        ->and($result['skipped'])->toHaveCount(1)
        ->and($result['skipped'][0]['subscription_id'])->toBe($missing->id)
        ->and((float) $due->fresh()->mrr)->toBe(91.8);
});

test('indexation API exposes indices, preview and apply', function () {
    $this->postJson('/api/v1/subscriptions/indexation/indices', ['index_type' => 'istat_foi', 'reference_month' => '2025-10', 'index_value' => 100])->assertCreated();
    $this->postJson('/api/v1/subscriptions/indexation/indices', ['index_type' => 'istat_foi', 'reference_month' => '2026-10', 'index_value' => 103])->assertCreated();

    $sub = ($this->make)(['startDate' => '2025-11-01', 'indexationType' => 'istat_foi', 'indexationBaseMonth' => '2025-10-01']);

    $this->getJson("/api/v1/subscriptions/{$sub->id}/indexation/preview?effective_date=2026-11-01")
        ->assertOk()->assertJsonPath('data.applied_pct', 3);

    $this->postJson("/api/v1/subscriptions/{$sub->id}/indexation/apply", ['effective_date' => '2026-11-01'])->assertCreated();
    $this->getJson("/api/v1/subscriptions/indexation/adjustments?subscription_id={$sub->id}")->assertOk();
    $this->getJson('/api/v1/subscriptions/indexation/indices?index_type=istat_foi')->assertOk()->assertJsonCount(2, 'data');
});

// ----------------------------------------------------------- SaaS metrics ----

test('SaaS metrics report MRR, ARR, churn rate, LTV and cancellation reasons', function () {
    $stayA = ($this->make)(['startDate' => '2026-09-01']);
    $stayB = ($this->make)(['startDate' => '2026-09-01']);
    $leaver = ($this->make)(['startDate' => '2026-09-01']);
    $leaver2 = ($this->make)(['startDate' => '2026-09-01']);

    $this->lifecycle->cancelSubscription(new CancelSubscriptionDTO($leaver->id, 'Too expensive', '2026-11-05', category: 'price'));
    $this->lifecycle->cancelSubscription(new CancelSubscriptionDTO($leaver2->id, 'Switched', '2026-11-20', category: 'competitor'));

    $metrics = app(SubscriptionMetricsService::class)->summary($this->tenant->id, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));

    expect($metrics['mrr'])->toBe(180.0)
        ->and($metrics['arr'])->toBe(2160.0)
        ->and($metrics['active_customers'])->toBe(2)
        ->and($metrics['arpa'])->toBe(90.0)
        ->and($metrics['customers_start_of_period'])->toBe(4)
        ->and($metrics['churned_customers'])->toBe(2)
        ->and($metrics['customer_churn_rate_pct'])->toBe(50.0)
        ->and($metrics['mrr_movements']['churned'])->toBe(180.0)
        ->and($metrics['revenue_churn_rate_pct'])->toBe(50.0)
        ->and($metrics['net_revenue_retention_pct'])->toBe(50.0)
        // LTV = ARPA / monthly churn (50% over a 30-day month) = 90 / 0.5
        ->and($metrics['ltv'])->toBe(180.0)
        ->and(collect($metrics['churn_reasons'])->pluck('category')->sort()->values()->all())->toBe(['competitor', 'price'])
        ->and($metrics['churn_reasons'][0]['lost_mrr'])->toBe(90.0);

    expect($stayA->id)->not->toBe($stayB->id);
});

test('metrics API serves summary, trend and churn reasons', function () {
    $sub = ($this->make)(['startDate' => '2026-09-01']);
    $this->lifecycle->cancelSubscription(new CancelSubscriptionDTO($sub->id, 'No longer needed', now()->toDateString(), category: 'no_longer_needed'));

    $this->getJson('/api/v1/subscriptions/metrics')->assertOk()->assertJsonStructure(['data' => ['mrr', 'arr', 'customer_churn_rate_pct', 'ltv', 'churn_reasons']]);
    $this->getJson('/api/v1/subscriptions/metrics/trend?months=3')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson('/api/v1/subscriptions/metrics/churn-reasons')->assertOk()->assertJsonPath('data.reasons.0.category', 'no_longer_needed');
});

test('cancel API accepts a reason category and feedback', function () {
    $sub = ($this->make)(['startDate' => '2026-11-01']);

    $this->postJson("/api/v1/sub/contracts/{$sub->id}/cancel", [
        'reason' => 'Missing integration',
        'category' => 'missing_features',
        'feedback' => 'Need SAP connector',
        'cancellation_date' => '2026-11-15',
    ])->assertOk()->assertJsonPath('data.cancellation_category', 'missing_features');

    $this->postJson('/api/v1/sub/contracts/'.$sub->id.'/cancel', ['reason' => 'x', 'category' => 'bogus'])->assertUnprocessable();
});

// ------------------------------------------------------------- automation ----

test('nightly automation command indexes, bills and renews without human intervention', function () {
    $sub = ($this->make)(['startDate' => '2025-11-01', 'indexationType' => 'istat_foi', 'indexationBaseMonth' => '2025-10-01']);
    $sub->update(['current_period_start' => '2026-10-01', 'current_period_end' => '2026-10-31', 'next_indexation_date' => '2026-11-01']);

    $this->indexation->recordIndex('istat_foi', '2025-10-01', 100.0);
    $this->indexation->recordIndex('istat_foi', '2026-10-01', 104.0);

    $this->artisan('subscriptions:run-automation', ['--date' => '2026-11-01', '--renewal-lookahead' => 0])->assertSuccessful();

    expect(IndexationAdjustment::where('subscription_id', $sub->id)->count())->toBe(1);

    $schedule = InvoiceSchedule::where('subscription_id', $sub->id)->firstOrFail();
    expect((float) $schedule->estimated_amount)->toBe(93.6) // indexed fee billed in the same night
        ->and($sub->fresh()->current_period_start->toDateString())->toBe('2026-11-01');
});
