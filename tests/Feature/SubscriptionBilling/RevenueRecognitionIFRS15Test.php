<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\SubscriptionBilling\Contracts\RevenueRecognitionServiceInterface;
use Modules\SubscriptionBilling\DTOs\RecognizeRevenueDTO;
use Modules\SubscriptionBilling\Models\PerformanceObligation;
use Modules\SubscriptionBilling\Models\RevenueContract;
use Modules\SubscriptionBilling\Models\RevenueRecognitionEntry;
use Modules\SubscriptionBilling\Models\RevenueSchedule;
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
        'billing_interval' => 'annual',
        'base_price' => 1200.00,
    ]);

    $this->subscription = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'start_date' => now()->startOfYear()->toDateString(),
        'contract_term_months' => 12,
        'status' => 'active',
        'mrr' => 100.00,
        'arr' => 1200.00,
    ]);

    SubscriptionItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $this->subscription->id,
        'plan_id' => $this->plan->id,
        'item_type' => 'plan',
        'unit_price' => 1200.00,
        'subtotal_recurring' => 1200.00,
    ]);

    $this->revRecService = app(RevenueRecognitionServiceInterface::class);
});

test('IFRS 15 revenue recognition creates contract, allocates SSP, and generates monthly schedules', function () {
    $contract = $this->revRecService->createRevenueContract($this->subscription);

    expect($contract)->toBeInstanceOf(RevenueContract::class)
        ->and((float) $contract->total_transaction_price)->toBe(1200.00)
        ->and($contract->status)->toBe('active');

    $pobs = PerformanceObligation::where('revenue_contract_id', $contract->id)->get();
    expect($pobs->count())->toBe(1);

    $pob = $pobs->first();
    expect((float) $pob->allocated_transaction_price)->toBe(1200.00)
        ->and((float) $pob->deferred_revenue_balance)->toBe(1200.00);

    $schedules = RevenueSchedule::where('performance_obligation_id', $pob->id)->get();
    expect($schedules->count())->toBe(12)
        ->and((float) $schedules->first()->scheduled_amount)->toBe(100.00);
});

test('recognizeRevenue processes due schedules, records accounting entries, and decrements deferred balance', function () {
    $contract = $this->revRecService->createRevenueContract($this->subscription);
    $pob = PerformanceObligation::where('revenue_contract_id', $contract->id)->first();

    $firstSchedule = RevenueSchedule::where('performance_obligation_id', $pob->id)->first();

    $dto = new RecognizeRevenueDTO(
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        periodDate: $firstSchedule->period_date->toDateString(),
        executedBy: (string) $this->user->id
    );

    $result = $this->revRecService->recognizeRevenue($dto);

    expect($result['entries_count'])->toBeGreaterThanOrEqual(1)
        ->and((float) $result['total_amount'])->toBeGreaterThanOrEqual(100.00);

    $freshPob = $pob->fresh();
    expect((float) $freshPob->recognized_revenue_to_date)->toBe(100.00)
        ->and((float) $freshPob->deferred_revenue_balance)->toBe(1100.00);

    $entry = RevenueRecognitionEntry::where('performance_obligation_id', $pob->id)->first();
    expect($entry)->not->toBeNull()
        ->and((float) $entry->amount)->toBe(100.00)
        ->and($entry->audit_hash)->not->toBeEmpty();
});
