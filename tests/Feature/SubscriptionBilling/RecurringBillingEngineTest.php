<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Contracts\RecurringBillingServiceInterface;
use Modules\SubscriptionBilling\DTOs\ExecuteBillingRunDTO;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->tenant);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->plan = SubscriptionPlan::factory()->create([
        'tenant_id' => $this->tenant->id,
        'billing_interval' => 'monthly',
        'base_price' => 120.00,
    ]);

    $this->billingService = app(RecurringBillingServiceInterface::class);
});

test('recurring billing engine executes batch run, generates invoice schedules, and advances period dates', function () {
    $customerId = (string) Str::uuid7();

    $periodStart = now()->subMonth();
    $periodEnd = now()->subDay();

    $subscription = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $customerId,
        'plan_id' => $this->plan->id,
        'status' => 'active',
        'current_period_start' => $periodStart->toDateString(),
        'current_period_end' => $periodEnd->toDateString(),
        'mrr' => 120.00,
        'arr' => 1440.00,
    ]);

    SubscriptionItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $subscription->id,
        'plan_id' => $this->plan->id,
        'item_type' => 'plan',
        'unit_price' => 120.00,
        'subtotal_recurring' => 120.00,
    ]);

    $dto = new ExecuteBillingRunDTO(
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        runDate: now()->toDateString(),
        lookaheadDays: 0,
        executedBy: (string) $this->user->id
    );

    $run = $this->billingService->executeBillingRun($dto);

    expect($run->status)->toBe('completed')
        ->and($run->total_subscriptions_evaluated)->toBeGreaterThanOrEqual(1)
        ->and($run->successful_invoices_count)->toBeGreaterThanOrEqual(1)
        ->and((float) $run->total_invoiced_amount)->toBeGreaterThanOrEqual(120.00);

    $schedule = InvoiceSchedule::where('subscription_id', $subscription->id)->first();
    expect($schedule)->not->toBeNull()
        ->and($schedule->status)->toBe('invoiced')
        ->and((float) $schedule->estimated_amount)->toBe(120.00);

    $refreshedSub = $subscription->fresh();
    expect($refreshedSub->current_period_start->toDateString())->toBe($periodEnd->copy()->addDay()->toDateString());
});
