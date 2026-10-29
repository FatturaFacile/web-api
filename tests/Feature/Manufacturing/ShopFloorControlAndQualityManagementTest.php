<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Manufacturing\Contracts\QualityManagementServiceInterface;
use Modules\Manufacturing\Contracts\ShopFloorServiceInterface;
use Modules\Manufacturing\DTOs\RecordQualityInspectionDTO;
use Modules\Manufacturing\DTOs\RecordShopFloorExecutionDTO;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgQualityCharacteristic;
use Modules\Manufacturing\Models\MfgQualityPlan;
use Modules\Manufacturing\Models\MfgWorkCenter;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->tenant);

    $this->shopFloorService = app(ShopFloorServiceInterface::class);
    $this->qmService = app(QualityManagementServiceInterface::class);

    $this->order = MfgProductionOrder::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->wc = MfgWorkCenter::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->op = MfgProductionOrderOperation::factory()->create([
        'tenant_id' => $this->tenant->id,
        'production_order_id' => $this->order->id,
        'work_center_id' => $this->wc->id,
    ]);
});

test('Shop floor service logs operator labor, machine time, downtime, and marks operation completed', function () {
    $execution = $this->shopFloorService->recordExecution(new RecordShopFloorExecutionDTO(
        tenantId: $this->tenant->id,
        productionOrderId: $this->order->id,
        productionOrderOperationId: $this->op->id,
        workCenterId: $this->wc->id,
        goodUnits: 15.0,
        scrapUnits: 1.0,
        laborLogs: [
            [
                'operator_id' => (string) Str::uuid7(),
                'start_time' => now()->subHours(2)->toDateTimeString(),
                'end_time' => now()->toDateTimeString(),
                'duration_minutes' => 120.0,
                'hourly_rate' => 30.0,
            ],
        ],
        machineLogs: [
            [
                'machine_id' => (string) Str::uuid7(),
                'start_time' => now()->subHours(2)->toDateTimeString(),
                'end_time' => now()->toDateTimeString(),
                'run_minutes' => 105.0,
                'downtime_minutes' => 15.0,
                'hourly_rate' => 50.0,
            ],
        ]
    ));

    expect($execution->status)->toBe('active')
        ->and($execution->laborLogs)->toHaveCount(1)
        ->and((float) $execution->laborLogs->first()->labor_cost)->toBe(60.0)
        ->and($execution->machineLogs)->toHaveCount(1);

    // Record downtime
    $paused = $this->shopFloorService->recordDowntime($execution->id, 20.0, 'Blade recalibration');
    expect($paused->status)->toBe('paused')
        ->and($paused->downtime_reason)->toBe('Blade recalibration');

    // Complete job card
    $completed = $this->shopFloorService->completeExecution($execution->id, 15.0, 1.0);
    expect($completed->status)->toBe('completed');

    $this->op->refresh();
    expect($this->op->status)->toBe('completed')
        ->and((float) $this->op->completed_quantity)->toBe(15.0);
});

test('Quality management service evaluates characteristics and auto-generates non-conformance on failure', function () {
    $plan = MfgQualityPlan::factory()->create([
        'tenant_id' => $this->tenant->id,
        'inspection_type' => 'final',
    ]);

    $char1 = MfgQualityCharacteristic::create([
        'tenant_id' => $this->tenant->id,
        'quality_plan_id' => $plan->id,
        'characteristic_name' => 'Bore Diameter',
        'target_value' => 50.00,
        'min_tolerance' => 49.95,
        'max_tolerance' => 50.05,
        'uom' => 'MM',
        'is_critical' => true,
    ]);

    // 1. Passing inspection
    $passInspection = $this->qmService->executeInspection(new RecordQualityInspectionDTO(
        tenantId: $this->tenant->id,
        qualityPlanId: $plan->id,
        productionOrderId: $this->order->id,
        sampleSize: 5,
        defectCount: 0,
        characteristicResults: [
            ['characteristic_id' => $char1->id, 'measured_value' => 50.01, 'is_conforming' => true],
        ]
    ));

    expect($passInspection->status)->toBe('passed')
        ->and($passInspection->defect_count)->toBe(0);

    // 2. Failing inspection with critical defect
    $failInspection = $this->qmService->executeInspection(new RecordQualityInspectionDTO(
        tenantId: $this->tenant->id,
        qualityPlanId: $plan->id,
        productionOrderId: $this->order->id,
        sampleSize: 5,
        defectCount: 0,
        characteristicResults: [
            ['characteristic_id' => $char1->id, 'measured_value' => 50.15, 'is_conforming' => false],
        ]
    ));

    expect($failInspection->status)->toBe('failed')
        ->and($failInspection->defect_count)->toBe(1)
        ->and($failInspection->nonConformances)->toHaveCount(1);

    // 3. Resolve NCR
    $ncr = $failInspection->nonConformances->first();
    expect($ncr->severity)->toBe('critical')
        ->and($ncr->status)->toBe('open');

    $resolverId = (string) Str::uuid7();
    $resolvedNcr = $this->qmService->resolveNonConformance($ncr->id, 'Reground bearing bore to within nominal tolerance', $resolverId);
    expect($resolvedNcr->status)->toBe('resolved')
        ->and($resolvedNcr->resolved_at)->not->toBeNull();
});
