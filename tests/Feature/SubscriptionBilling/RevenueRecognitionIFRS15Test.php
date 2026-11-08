<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SubscriptionBilling\Contracts\RevenueRecognitionServiceInterface;
use Modules\SubscriptionBilling\DTOs\RecognizeRevenueDTO;
use Modules\SubscriptionBilling\Models\PerformanceObligation;
use Modules\SubscriptionBilling\Models\RevenueRecognitionEntry;
use Modules\SubscriptionBilling\Models\RevenueSchedule;
use Modules\SubscriptionBilling\Models\Subscription;
use Tests\Feature\SubscriptionBilling\Support\SubscriptionScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-03-01 09:00:00');
    $this->tenant = SubscriptionScenario::company();
    SubscriptionScenario::actingAs($this->tenant);
    $this->customer = SubscriptionScenario::customer($this->tenant);
    $this->plan = SubscriptionScenario::plan($this->tenant, ['billing_interval' => 'annual', 'base_price' => '1200.0000', 'setup_fee' => '300.00']);
    $this->revenue = app(RevenueRecognitionServiceInterface::class);
    $id = $this->postJson('/api/v1/sub/contracts', ['customer_id' => $this->customer->id, 'plan_id' => $this->plan->id, 'start_date' => '2026-03-01'])->json('data.id');
    $this->postJson("/api/v1/sub/contracts/{$id}/bill")->assertOk();
    $this->subscription = Subscription::query()->findOrFail($id);
});

test('an issued annual invoice becomes obligations spread monthly, the setup fee at a point in time', function (): void {
    $contract = $this->revenue->createRevenueContract($this->subscription);
    $obligations = PerformanceObligation::query()->where('revenue_contract_id', $contract->id)->get()->keyBy('recognition_method');

    expect((string) $contract->total_transaction_price)->toBe('1500.00')
        ->and((string) $obligations['straight_line_over_time']->allocated_transaction_price)->toBe('1200.00')
        ->and((string) $obligations['point_in_time']->allocated_transaction_price)->toBe('300.00')
        ->and(RevenueSchedule::query()->where('performance_obligation_id', $obligations['straight_line_over_time']->id)->count())->toBe(12)
        ->and((string) RevenueSchedule::query()->where('performance_obligation_id', $obligations['straight_line_over_time']->id)->sum('scheduled_amount'))->toBe('1200.00');
});

test('recognition posts the due slices once, records entries and lowers the deferred balance', function (): void {
    $this->travelTo('2026-05-02 09:00:00');
    $dto = new RecognizeRevenueDTO(tenantId: (string) $this->tenant->id, periodDate: CarbonImmutable::today()->toDateString());

    $result = $this->revenue->recognizeRevenue($dto);

    // Setup fee (March) + March + April of the annual fee, split by days.
    expect($result['months'])->toBe(['2026-03-31', '2026-04-30'])
        ->and($result['journal_entry_ids'])->toHaveCount(2)
        ->and(bcadd($result['total_amount'], '0', 2))->toBe(bcadd('300', bcadd(RevenueSchedule::query()->whereDate('period_date', '<=', '2026-04-30')->whereHas('performanceObligation', fn ($q) => $q->where('recognition_method', 'straight_line_over_time'))->sum('scheduled_amount'), '0', 2), 2))
        ->and(RevenueRecognitionEntry::query()->count())->toBe(3)
        ->and($this->revenue->recognizeRevenue($dto)['entries_count'])->toBe(0);

    $annual = PerformanceObligation::query()->where('recognition_method', 'straight_line_over_time')->firstOrFail();
    expect(bcadd((string) $annual->recognized_revenue_to_date, (string) $annual->deferred_revenue_balance, 2))->toBe('1200.00')
        ->and(PerformanceObligation::query()->where('recognition_method', 'point_in_time')->value('status'))->toBe('satisfied');
});
