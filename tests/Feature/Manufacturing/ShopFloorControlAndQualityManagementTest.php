<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgLaborTimeLog;
use Modules\Manufacturing\Models\MfgQualityNonConformance;

require_once __DIR__.'/ManufacturingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    mfgSetUp($this);
    $this->product = mfgProductSetUp($this);
    $this->orderId = (string) $this->postJson('/api/v1/manufacturing/production-orders', [
        'mfg_item_id' => $this->product['fg']->id,
        'target_quantity' => '10',
        'planned_start_date' => now()->toDateString(),
        'planned_end_date' => now()->addDays(5)->toDateString(),
    ])->json('data.id');
    $operations = collect($this->postJson("/api/v1/manufacturing/production-orders/{$this->orderId}/release")->assertOk()->json('data.operations'))->keyBy('sequence');
    $this->op10 = (string) $operations[10]['id'];
    $this->op20 = (string) $operations[20]['id'];
});

test('a job card confirms the elapsed time less downtime and feeds the OEE inputs and production KPIs', function (): void {
    $this->freezeSecond();
    $this->postJson('/api/v1/manufacturing/shop-floor/executions', ['production_order_operation_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['production_order_operation_id']);

    $jobId = $this->postJson('/api/v1/manufacturing/shop-floor/executions', ['production_order_operation_id' => $this->op10, 'operator_id' => $this->member->id])
        ->assertCreated()->assertJsonPath('data.status', 'active')->json('data.id');
    $this->getJson("/api/v1/manufacturing/production-orders/{$this->orderId}")->assertJsonPath('data.status', 'in_progress');

    $this->postJson("/api/v1/manufacturing/shop-floor/executions/{$jobId}/downtime", ['downtime_minutes' => '0', 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['downtime_minutes']);
    $this->postJson("/api/v1/manufacturing/shop-floor/executions/{$jobId}/downtime", ['downtime_minutes' => '5', 'reason' => 'Tool change'])->assertOk()->assertJsonPath('data.downtime_minutes', '5.00');

    $this->travel(80)->minutes();
    // 80 elapsed - 5 downtime - 15 setup = 60 run minutes; labour 75 minutes at 60/h, overhead 75 at 30/h.
    $this->postJson("/api/v1/manufacturing/shop-floor/executions/{$jobId}/complete", ['good_units' => '9', 'scrap_units' => '1', 'scrap_reason' => 'Crack', 'setup_minutes' => '15', 'is_final' => true])
        ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.good_units', '9.0000');
    $this->postJson("/api/v1/manufacturing/shop-floor/executions/{$jobId}/complete", ['good_units' => '1'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->postJson("/api/v1/manufacturing/shop-floor/executions/{$jobId}/downtime", ['downtime_minutes' => '5', 'reason' => 'Late'])->assertUnprocessable();

    $confirmations = $this->getJson("/api/v1/manufacturing/production-orders/{$this->orderId}/transactions")->assertOk()->json('data.confirmations');
    expect($confirmations[0]['run_minutes'])->toBe('60.00')
        ->and($confirmations[0]['labor_cost'])->toBe('75.0000')
        ->and($confirmations[0]['overhead_cost'])->toBe('37.5000')
        ->and($confirmations[0]['downtime_minutes'])->toBe('5.00')
        ->and($confirmations[0]['shop_floor_execution_id'])->toBe($jobId)
        ->and(MfgLaborTimeLog::query()->where('execution_id', $jobId)->value('labor_cost'))->toBe('75.0000');

    $this->postJson("/api/v1/manufacturing/production-orders/{$this->orderId}/operations/{$this->op20}/confirmations", ['run_minutes' => '30', 'good_quantity' => '9', 'is_final' => true])->assertCreated();
    $this->postJson('/api/v1/manufacturing/shop-floor/executions', ['production_order_operation_id' => $this->op20])->assertUnprocessable()->assertJsonValidationErrors(['production_order_operation_id']);
    $this->postJson("/api/v1/manufacturing/production-orders/{$this->orderId}/receipts", ['quantity' => '9'])->assertCreated();
    $this->postJson("/api/v1/manufacturing/production-orders/{$this->orderId}/complete")->assertOk();

    $this->getJson('/api/v1/manufacturing/shop-floor/executions?status=completed')->assertOk()->assertJsonCount(1, 'data');

    $today = now()->toDateString();
    $kpis = $this->getJson("/api/v1/manufacturing/kpis?from={$today}&to={$today}")->assertOk()->json('data');
    $center = collect($kpis['work_centers'])->firstWhere('work_center_id', $this->product['wc']->id);
    expect($kpis['orders']['completed'])->toBe(1)
        ->and($kpis['orders']['on_time_percent'])->toBe('100.00')
        ->and($kpis['orders']['average_lead_time_days'])->not->toBeNull()
        ->and($kpis['quality'])->toBe(['good_quantity' => '18.0000', 'scrap_quantity' => '1.0000', 'scrap_rate_percent' => '5.26'])
        ->and($center['operating_minutes'])->toBe('105.00')
        ->and($center['downtime_minutes'])->toBe('5.00')
        ->and($center['ideal_minutes'])->toBe('87.00')
        ->and($center['availability'])->toBe('0.9545')
        ->and($center['performance'])->toBe('0.9667')
        ->and($center['quality'])->toBe('0.9474')
        ->and($center['oee'])->toBe('0.8742')
        ->and($kpis['variances']['orders_closed'])->toBe(0);

    $this->getJson("/api/v1/manufacturing/kpis?from={$today}&to=".now()->subDay()->toDateString())->assertUnprocessable()->assertJsonValidationErrors(['to']);
    $empty = $this->getJson('/api/v1/manufacturing/kpis?from='.now()->subYear()->toDateString().'&to='.now()->subYear()->toDateString())->assertOk()->json('data');
    expect($empty['quality']['scrap_rate_percent'])->toBeNull()
        ->and(collect($empty['work_centers'])->first()['oee'])->toBeNull();
});

test('inspection plans evaluate measurements, fail critical deviations into non-conformances that are resolved', function (): void {
    $this->postJson('/api/v1/manufacturing/quality/plans', ['plan_code' => 'QP', 'name' => 'X', 'inspection_type' => 'audit'])->assertUnprocessable()->assertJsonValidationErrors(['inspection_type']);
    $plan = $this->postJson('/api/v1/manufacturing/quality/plans', [
        'plan_code' => 'QP-PUMP',
        'name' => 'Pump dimensions',
        'inspection_type' => 'final',
        'mfg_item_id' => $this->product['fg']->id,
        'acceptance_defect_limit' => 1,
        'characteristics' => [
            ['characteristic_name' => 'Bore', 'target_value' => '10', 'min_tolerance' => '9.9', 'max_tolerance' => '10.1', 'is_critical' => true],
            ['characteristic_name' => 'Finish', 'max_tolerance' => '1.6'],
        ],
    ])->assertCreated()->assertJsonCount(2, 'data.characteristics');
    $bore = collect($plan->json('data.characteristics'))->firstWhere('characteristic_name', 'Bore')['id'];
    $finish = collect($plan->json('data.characteristics'))->firstWhere('characteristic_name', 'Finish')['id'];
    $planId = $plan->json('data.id');
    $this->getJson('/api/v1/manufacturing/quality/plans')->assertOk()->assertJsonCount(1, 'data');

    // Receipt inspection: one minor deviation, within the limit of one defect.
    $this->postJson("/api/v1/manufacturing/production-orders/{$this->orderId}/operations/{$this->op10}/confirmations", ['run_minutes' => '10', 'good_quantity' => '10', 'is_final' => true])->assertCreated();
    $receiptId = $this->postJson("/api/v1/manufacturing/production-orders/{$this->orderId}/receipts", ['quantity' => '5'])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/manufacturing/quality/inspections', ['quality_plan_id' => $planId, 'inspection_point' => 'receipt', 'production_order_id' => $this->orderId, 'material_movement_id' => $receiptId, 'quantity_inspected' => '5', 'characteristic_results' => [['characteristic_id' => $bore, 'measured_value' => '10.05'], ['characteristic_id' => $finish, 'measured_value' => '2.0']]])
        ->assertCreated()->assertJsonPath('data.status', 'passed')->assertJsonPath('data.defect_count', 1)->assertJsonPath('data.quantity_accepted', '5.0000');

    // Operation inspection: the critical bore is out of tolerance.
    $failed = $this->postJson('/api/v1/manufacturing/quality/inspections', ['quality_plan_id' => $planId, 'inspection_point' => 'operation', 'production_order_id' => $this->orderId, 'production_order_operation_id' => $this->op20, 'quantity_inspected' => '4', 'characteristic_results' => [['characteristic_id' => $bore, 'measured_value' => '10.3']]])
        ->assertCreated()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.quantity_rejected', '4.0000')->assertJsonCount(1, 'data.non_conformances');
    $ncId = $failed->json('data.non_conformances.0.id');
    expect(MfgQualityNonConformance::query()->findOrFail($ncId)->severity)->toBe('critical');

    $this->postJson('/api/v1/manufacturing/quality/inspections', ['quality_plan_id' => $planId, 'inspection_point' => 'operation'])->assertUnprocessable()->assertJsonValidationErrors(['production_order_id', 'production_order_operation_id']);
    $this->postJson('/api/v1/manufacturing/quality/inspections', ['quality_plan_id' => $planId, 'inspection_point' => 'operation', 'production_order_id' => $this->orderId, 'production_order_operation_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['production_order_operation_id']);
    $this->postJson('/api/v1/manufacturing/quality/inspections', ['quality_plan_id' => $planId, 'inspection_point' => 'final', 'quantity_inspected' => '1', 'quantity_rejected' => '2'])->assertUnprocessable()->assertJsonValidationErrors(['quantity_rejected']);
    $this->postJson('/api/v1/manufacturing/quality/inspections', ['quality_plan_id' => $planId, 'inspection_point' => 'final', 'characteristic_results' => [['characteristic_id' => (string) Str::uuid(), 'measured_value' => '1']]])->assertUnprocessable()->assertJsonValidationErrors(['characteristic_results']);

    $this->getJson("/api/v1/manufacturing/quality/inspections?production_order_id={$this->orderId}&status=failed")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/manufacturing/quality/non-conformances?severity=critical')->assertOk()->assertJsonCount(1, 'data');

    $this->postJson("/api/v1/manufacturing/quality/non-conformances/{$ncId}/resolve", ['disposition' => 'investigating', 'corrective_action' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['disposition']);
    $this->postJson("/api/v1/manufacturing/quality/non-conformances/{$ncId}/resolve", ['disposition' => 'rework', 'corrective_action' => 'Re-bore and re-inspect'])
        ->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.disposition', 'rework')->assertJsonPath('data.resolved_by', $this->owner->id);
    $this->postJson("/api/v1/manufacturing/quality/non-conformances/{$ncId}/resolve", ['disposition' => 'scrap', 'corrective_action' => 'Again'])->assertUnprocessable()->assertJsonValidationErrors(['status']);

    $this->postJson('/api/v1/manufacturing/quality/non-conformances', ['severity' => 'grave'])->assertUnprocessable()->assertJsonValidationErrors(['severity', 'defect_type', 'description']);
    $this->postJson('/api/v1/manufacturing/quality/non-conformances', ['severity' => 'minor', 'defect_type' => 'scratch', 'description' => 'Cosmetic scratch on housing', 'production_order_id' => $this->orderId, 'quantity' => '1'])
        ->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.reported_by', $this->owner->id);
});
