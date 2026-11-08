<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\SubscriptionBilling\Contracts\RecurringBillingServiceInterface;
use Modules\SubscriptionBilling\DTOs\ExecuteBillingRunDTO;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Tests\Feature\SubscriptionBilling\Support\SubscriptionScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-03-15 09:00:00');
    $this->tenant = SubscriptionScenario::company();
    $this->plan = SubscriptionScenario::plan($this->tenant, ['base_price' => '120.0000']);
    $this->billingService = app(RecurringBillingServiceInterface::class);
});

test('a billing run issues a real invoice per due period, links it to the schedule and advances the period', function (): void {
    $customer = SubscriptionScenario::customer($this->tenant);
    $subscription = Subscription::create([
        'tenant_id' => $this->tenant->id, 'subscription_number' => 'SUB-RUN-1', 'customer_id' => $customer->id, 'plan_id' => $this->plan->id,
        'status' => 'active', 'start_date' => '2026-02-15', 'billing_anchor_date' => '2026-02-15',
        'current_period_start' => '2026-02-15', 'current_period_end' => '2026-03-14', 'currency' => 'EUR', 'payment_terms_days' => 30,
    ]);
    SubscriptionItem::create(['tenant_id' => $this->tenant->id, 'subscription_id' => $subscription->id, 'plan_id' => $this->plan->id, 'item_type' => 'plan', 'description' => 'Plan', 'quantity' => '1', 'unit_price' => '120', 'price_factor' => '1']);

    $run = $this->billingService->executeBillingRun(new ExecuteBillingRunDTO(tenantId: (string) $this->tenant->id));

    expect($run->status)->toBe('completed')
        ->and($run->total_subscriptions_evaluated)->toBe(1)
        ->and($run->successful_invoices_count)->toBe(2)
        ->and((string) $run->total_invoiced_amount)->toBe('292.80');

    $schedules = InvoiceSchedule::query()->where('subscription_id', $subscription->id)->orderBy('service_period_start')->get();
    expect($schedules->pluck('status')->unique()->all())->toBe(['invoiced'])
        ->and(SalesInvoice::query()->whereIn('id', $schedules->pluck('invoice_id'))->where('status', 'posted')->count())->toBe(2)
        ->and((string) $schedules->first()->net_amount)->toBe('120.00')
        ->and($subscription->fresh()->current_period_start->toDateString())->toBe('2026-04-15');
});

test('a run with nothing due creates no invoice', function (): void {
    $run = $this->billingService->executeBillingRun(new ExecuteBillingRunDTO(tenantId: (string) $this->tenant->id));

    expect($run->status)->toBe('completed')->and($run->successful_invoices_count)->toBe(0)->and(SalesInvoice::query()->count())->toBe(0);
});
