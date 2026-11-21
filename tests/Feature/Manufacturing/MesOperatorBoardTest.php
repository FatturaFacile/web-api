<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLot;
use Modules\Manufacturing\Models\MfgMesMaterialConsumption;
use Modules\Manufacturing\Models\MfgMesPhase;
use Modules\Manufacturing\Models\MfgMesTimeLog;

require_once __DIR__.'/ManufacturingSupport.php';

uses(RefreshDatabase::class);

const MES = '/api/v1/manufacturing/mes';

/**
 * Body of a MES command with a fresh idempotency key.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function mesBody(array $extra = []): array
{
    return ['idempotency_key' => (string) Str::uuid(), ...$extra];
}

/**
 * Takes the operation over for the user and returns the phase id.
 *
 * @param  array<string, mixed>  $extra
 */
function mesTakeOver(object $test, string $operationId, ?string $operatorId = null, array $extra = []): string
{
    return (string) $test->postJson(MES.'/phases/take-over', mesBody(['production_order_operation_id' => $operationId, 'operator_id' => $operatorId ?? $test->member->id, ...$extra]))
        ->assertCreated()->assertJsonPath('data.status', 'assigned')->json('data.id');
}

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

test('a phase runs the whole lifecycle and its closing confirms man time, machine time and downtime from server timestamps', function (): void {
    $this->freezeSecond();
    $workCenterId = (string) $this->product['wc']->id;

    $board = $this->getJson(MES."/board?work_center_id={$workCenterId}")->assertOk();
    expect($board->json('data.phases'))->toBe([])
        ->and(collect($board->json('data.available'))->pluck('operation.sequence')->all())->toBe([10, 20])
        ->and($board->json('data.available.0.allowed_actions'))->toBe(['take_over']);

    $phaseId = mesTakeOver($this, $this->op10);
    $this->getJson("/api/v1/manufacturing/production-orders/{$this->orderId}")->assertJsonPath('data.status', 'in_progress');

    $this->postJson(MES."/phases/{$phaseId}/start-setup", mesBody())->assertOk()->assertJsonPath('data.status', 'setup')->assertJsonPath('data.version', 2);
    $this->travel(15)->minutes();
    $this->postJson(MES."/phases/{$phaseId}/start-production", mesBody())->assertOk()->assertJsonPath('data.status', 'running')->assertJsonPath('data.times.setup_minutes', '15.00');
    $this->travel(20)->minutes();
    $this->postJson(MES."/phases/{$phaseId}/suspend", mesBody(['reason' => 'machine_breakdown']))->assertOk()
        ->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.resume_to', 'running')->assertJsonPath('data.allowed_actions', ['take_over', 'resume', 'declare_production', 'consume_material', 'close']);
    $this->travel(10)->minutes();
    $this->getJson(MES."/board?work_center_id={$workCenterId}")->assertJsonPath('data.phases.0.times.downtime_seconds', 600)->assertJsonPath('data.phases.0.open_interval.suspension_reason', 'machine_breakdown');
    $this->postJson(MES."/phases/{$phaseId}/resume", mesBody())->assertOk()->assertJsonPath('data.status', 'running');
    $this->travel(10)->minutes();
    $this->postJson(MES."/phases/{$phaseId}/start-production", mesBody(['machine_mode' => 'unattended']))->assertOk()->assertJsonPath('data.machine_mode', 'unattended');
    $this->travel(30)->minutes();
    $this->postJson(MES."/phases/{$phaseId}/declarations", mesBody(['good_quantity' => '9', 'scrap_quantity' => '1', 'defect_reason' => 'surface']))->assertOk()
        ->assertJsonPath('data.declared.good_quantity', '9.0000')->assertJsonPath('data.declared.scrap_quantity', '1.0000');

    // Setup 15 + run 20 + 10 + 30 = 75 machine minutes; man = setup 15 + attended 30 = 45; downtime 10 never counts.
    $closed = $this->postJson(MES."/phases/{$phaseId}/close", mesBody())->assertOk()->assertJsonPath('data.status', 'closed');
    expect($closed->json('data.times.man_minutes'))->toBe('45.00')
        ->and($closed->json('data.times.machine_minutes'))->toBe('75.00')
        ->and($closed->json('data.times.run_minutes'))->toBe('60.00')
        ->and($closed->json('data.times.downtime_minutes'))->toBe('10.00')
        ->and($closed->json('data.allowed_actions'))->toBe([]);

    $confirmation = $this->getJson("/api/v1/manufacturing/production-orders/{$this->orderId}/transactions")->assertOk()->json('data.confirmations.0');
    expect($confirmation['setup_minutes'])->toBe('15.00')
        ->and($confirmation['run_minutes'])->toBe('60.00')
        ->and($confirmation['labor_minutes'])->toBe('45.00')
        ->and($confirmation['downtime_minutes'])->toBe('10.00')
        ->and($confirmation['labor_cost'])->toBe('45.0000')
        ->and($confirmation['overhead_cost'])->toBe('37.5000')
        ->and($confirmation['good_quantity'])->toBe('9.0000')
        ->and($confirmation['scrap_quantity'])->toBe('1.0000')
        ->and($confirmation['scrap_reason'])->toBe('surface')
        ->and($confirmation['is_final'])->toBeTrue();
    expect(MfgMesTimeLog::query()->where('phase_id', $phaseId)->whereNull('ended_at')->count())->toBe(0)
        ->and(mfgQuantities($this->product['seal'], $this->warehouse)['on_hand'])->toBe('90.0000');

    $this->postJson(MES.'/phases/take-over', mesBody(['production_order_operation_id' => $this->op10, 'operator_id' => $this->member->id]))->assertUnprocessable();
    $trace = $this->getJson(MES."/phases/{$phaseId}/traceability")->assertOk();
    expect(collect($trace->json('data.intervals'))->pluck('category')->all())->toBe(['setup', 'run', 'suspension', 'run', 'run'])
        ->and($trace->json('data.scraps.0.defect_reason'))->toBe('surface');
});

test('the state machine refuses every action the current state does not allow', function (): void {
    $phaseId = mesTakeOver($this, $this->op10);

    foreach (['resume', 'suspend', 'close'] as $action) {
        $body = $action === 'suspend' ? mesBody(['reason' => 'break']) : mesBody();
        $this->postJson(MES."/phases/{$phaseId}/{$action}", $body)->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }
    $this->postJson(MES."/phases/{$phaseId}/declarations", mesBody(['good_quantity' => '1']))->assertUnprocessable()->assertJsonValidationErrors(['status']);

    $this->postJson(MES."/phases/{$phaseId}/start-setup", mesBody())->assertOk();
    $this->postJson(MES."/phases/{$phaseId}/start-setup", mesBody())->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->postJson(MES."/phases/{$phaseId}/start-production", mesBody())->assertOk();
    $this->postJson(MES."/phases/{$phaseId}/start-production", mesBody())->assertUnprocessable()->assertJsonValidationErrors(['machine_mode']);
    $this->postJson(MES."/phases/{$phaseId}/suspend", mesBody())->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson(MES."/phases/{$phaseId}/suspend", mesBody(['reason' => 'lunch']))->assertUnprocessable()->assertJsonValidationErrors(['reason']);
});

test('scrapped pieces need a defect reason and a declaration needs a piece', function (): void {
    $phaseId = mesTakeOver($this, $this->op10);
    $this->postJson(MES."/phases/{$phaseId}/start-production", mesBody())->assertOk();

    $this->postJson(MES."/phases/{$phaseId}/declarations", mesBody(['good_quantity' => '5', 'scrap_quantity' => '2']))->assertUnprocessable()->assertJsonValidationErrors(['defect_reason']);
    $this->postJson(MES."/phases/{$phaseId}/declarations", mesBody(['good_quantity' => '5', 'scrap_quantity' => '2', 'defect_reason' => 'nope']))->assertUnprocessable()->assertJsonValidationErrors(['defect_reason']);
    $this->postJson(MES."/phases/{$phaseId}/declarations", mesBody(['good_quantity' => '0', 'scrap_quantity' => '0']))->assertUnprocessable()->assertJsonValidationErrors(['good_quantity']);
    $this->postJson(MES."/phases/{$phaseId}/declarations", mesBody(['good_quantity' => '-1']))->assertUnprocessable()->assertJsonValidationErrors(['good_quantity']);
    $this->postJson(MES."/phases/{$phaseId}/declarations", mesBody(['good_quantity' => '5', 'scrap_quantity' => '2', 'defect_reason' => 'tool_wear']))->assertOk()->assertJsonPath('data.declared.scrap_quantity', '2.0000');
    $this->postJson(MES."/phases/{$phaseId}/declarations", mesBody(['good_quantity' => '3']))->assertOk()->assertJsonPath('data.declared.good_quantity', '8.0000');
});

test('a retry with the same idempotency key changes nothing, and a key cannot be reused by another command', function (): void {
    $phaseId = mesTakeOver($this, $this->op10);
    $setup = mesBody();
    $this->postJson(MES."/phases/{$phaseId}/start-setup", $setup)->assertOk()->assertJsonPath('data.version', 2);
    $this->postJson(MES."/phases/{$phaseId}/start-setup", $setup)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.status', 'setup');
    expect(MfgMesTimeLog::query()->where('phase_id', $phaseId)->count())->toBe(1);

    $this->postJson(MES."/phases/{$phaseId}/start-production", $setup)->assertUnprocessable()->assertJsonValidationErrors(['idempotency_key']);
    $this->postJson(MES."/phases/{$phaseId}/start-production", ['good' => 1])->assertUnprocessable()->assertJsonValidationErrors(['idempotency_key']);

    $declare = mesBody(['good_quantity' => '2']);
    $this->postJson(MES."/phases/{$phaseId}/start-production", mesBody())->assertOk();
    $this->postJson(MES."/phases/{$phaseId}/declarations", $declare)->assertOk();
    $this->postJson(MES."/phases/{$phaseId}/declarations", $declare)->assertOk()->assertJsonPath('data.declared.good_quantity', '2.0000');

    $headerKey = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $headerKey)->postJson(MES."/phases/{$phaseId}/suspend", ['reason' => 'break'])->assertOk();
    $this->withHeader('Idempotency-Key', $headerKey)->postJson(MES."/phases/{$phaseId}/suspend", ['reason' => 'break'])->assertOk()->assertJsonPath('data.status', 'suspended');
    expect(MfgMesTimeLog::query()->where('phase_id', $phaseId)->where('category', 'suspension')->count())->toBe(1);
});

test('a stale phase version is refused and the client must reload', function (): void {
    $phaseId = mesTakeOver($this, $this->op10);
    $this->postJson(MES."/phases/{$phaseId}/start-setup", mesBody(['expected_version' => 5]))->assertUnprocessable()->assertJsonValidationErrors(['version']);
    $this->postJson(MES."/phases/{$phaseId}/start-setup", mesBody(['expected_version' => 1]))->assertOk()->assertJsonPath('data.version', 2);
    $this->postJson(MES."/phases/{$phaseId}/start-production", mesBody(['expected_version' => 1]))->assertUnprocessable()->assertJsonValidationErrors(['version']);
});

test('an offline client replays its actions with the time they happened, never in the future or before the last event', function (): void {
    $this->freezeSecond();
    $phaseId = mesTakeOver($this, $this->op10, extra: ['occurred_at' => now()->subMinutes(60)->toIso8601String()]);
    $this->postJson(MES."/phases/{$phaseId}/start-setup", mesBody(['occurred_at' => now()->subMinutes(50)->toIso8601String()]))->assertOk();
    $this->postJson(MES."/phases/{$phaseId}/start-production", mesBody(['occurred_at' => now()->subMinutes(30)->toIso8601String()]))->assertOk();
    $this->postJson(MES."/phases/{$phaseId}/suspend", mesBody(['reason' => 'break', 'occurred_at' => now()->subMinutes(40)->toIso8601String()]))->assertUnprocessable()->assertJsonValidationErrors(['occurred_at']);

    $future = $this->postJson(MES."/phases/{$phaseId}/suspend", mesBody(['reason' => 'break', 'occurred_at' => now()->addHours(3)->toIso8601String()]))->assertOk();
    expect($future->json('data.times.setup_minutes'))->toBe('20.00')
        ->and($future->json('data.times.run_minutes'))->toBe('30.00')
        ->and($future->json('data.times.downtime_seconds'))->toBe(0);
});

test('a shift handover moves the phase to another operator while suspended and splits the man time', function (): void {
    $this->freezeSecond();
    $phaseId = mesTakeOver($this, $this->op10);
    $this->postJson(MES."/phases/{$phaseId}/start-production", mesBody())->assertOk();
    $this->travel(60)->minutes();
    $this->postJson(MES."/phases/{$phaseId}/suspend", mesBody(['reason' => 'shift_handover']))->assertOk();
    $this->postJson(MES.'/phases/take-over', mesBody(['production_order_operation_id' => $this->op10, 'operator_id' => $this->production->id]))->assertOk()
        ->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.operator_id', $this->production->id);
    $this->travel(5)->minutes();
    $this->postJson(MES."/phases/{$phaseId}/resume", mesBody())->assertOk();
    $this->travel(30)->minutes();

    $times = $this->getJson(MES."/board?work_center_id={$this->product['wc']->id}")->json('data.phases.0.times');
    expect($times['man_seconds_by_operator'])->toBe([$this->member->id => 3600, $this->production->id => 1800])
        ->and($times['downtime_seconds_by_reason'])->toBe(['shift_handover' => 300]);

    $this->getJson(MES."/board?work_center_id={$this->product['wc']->id}&operator_id={$this->member->id}")->assertJsonCount(0, 'data.phases');
});

test('an operator works one phase at a time, while an unattended run leaves them free', function (): void {
    $first = mesTakeOver($this, $this->op10);
    $second = mesTakeOver($this, $this->op20);
    $this->postJson(MES."/phases/{$first}/start-production", mesBody())->assertOk();

    $this->postJson(MES."/phases/{$second}/start-setup", mesBody())->assertUnprocessable()->assertJsonValidationErrors(['operator_id']);
    $this->postJson(MES."/phases/{$first}/start-production", mesBody(['machine_mode' => 'unattended']))->assertOk();
    $this->postJson(MES."/phases/{$second}/start-setup", mesBody())->assertOk();
    $this->postJson(MES."/phases/{$first}/start-production", mesBody(['machine_mode' => 'attended']))->assertUnprocessable()->assertJsonValidationErrors(['operator_id']);
});

test('lots are validated, issued from stock and traced; over-consumption needs confirmation', function (): void {
    $lotItem = mfgItem($this, 'RM-ALLOY', 'raw_material', '5.00', tracking: 'lot');
    $fg = mfgItem($this, 'FG-ALLOY', 'finished_good');
    $lot = StockLot::create(['tenant_id' => $this->tenant->id, 'stock_item_id' => $lotItem->stock_item_id, 'lot_number' => 'AL-2026-001', 'received_at' => now()->toDateString()]);
    StockLot::create(['tenant_id' => $this->tenant->id, 'stock_item_id' => $lotItem->stock_item_id, 'lot_number' => 'AL-BAD', 'received_at' => now()->toDateString(), 'is_quarantined' => true]);
    mfgStock($lotItem, $this->warehouse, '50', '5.00', (string) $lot->id);

    $bomId = $this->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $fg->id, 'name' => 'Alloy', 'components' => [['component_item_id' => $lotItem->id, 'quantity_per' => '2']]])->json('data.id');
    $this->postJson("/api/v1/manufacturing/boms/{$bomId}/activate")->assertOk();
    $this->postJson('/api/v1/manufacturing/routings', ['mfg_item_id' => $fg->id, 'name' => 'Alloy routing', 'operations' => [['operation_code' => 'OP10', 'name' => 'Cast', 'work_center_id' => $this->product['wc']->id, 'setup_time_minutes' => '0', 'run_time_minutes_per_unit' => '1']]])->assertCreated();
    $orderId = (string) $this->postJson('/api/v1/manufacturing/production-orders', ['mfg_item_id' => $fg->id, 'target_quantity' => '5', 'planned_start_date' => now()->toDateString(), 'planned_end_date' => now()->toDateString()])->json('data.id');
    $operationId = (string) $this->postJson("/api/v1/manufacturing/production-orders/{$orderId}/release")->assertOk()->json('data.operations.0.id');
    $phaseId = mesTakeOver($this, $operationId);
    $this->postJson(MES."/phases/{$phaseId}/start-production", mesBody())->assertOk();

    $consume = fn (array $extra) => $this->postJson(MES."/phases/{$phaseId}/material-consumptions", mesBody(['mfg_item_id' => $lotItem->id, ...$extra]));
    $consume(['quantity' => '2'])->assertUnprocessable()->assertJsonValidationErrors(['lot_code']);
    $consume(['quantity' => '2', 'lot_code' => 'UNKNOWN-1'])->assertUnprocessable()->assertJsonValidationErrors(['lot_code']);
    $consume(['quantity' => '2', 'lot_code' => '  bad lot!  '])->assertUnprocessable()->assertJsonValidationErrors(['lot_code']);
    $consume(['quantity' => '2', 'lot_code' => 'AL-BAD'])->assertUnprocessable()->assertJsonValidationErrors(['lot_code']);
    $consume(['quantity' => '0', 'lot_code' => 'AL-2026-001'])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
    $consume(['quantity' => '2', 'lot_code' => 'AL-2026-001', 'mfg_item_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['mfg_item_id']);
    $consume(['quantity' => '51', 'lot_code' => 'AL-2026-001', 'over_consumption_confirmed' => true])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
    $consume(['quantity' => '11', 'lot_code' => 'AL-2026-001'])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);

    $consume(['quantity' => '6', 'lot_code' => ' al-2026-001 '])->assertOk()->assertJsonPath('data.consumptions_count', 1);
    $consume(['quantity' => '5', 'lot_code' => 'AL-2026-001', 'over_consumption_confirmed' => true])->assertOk()->assertJsonPath('data.consumptions_count', 2);
    expect(mfgQuantities($lotItem, $this->warehouse)['on_hand'])->toBe('39.0000')
        ->and(MfgMesMaterialConsumption::query()->where('phase_id', $phaseId)->pluck('lot_code')->unique()->values()->all())->toBe(['AL-2026-001']);

    $trace = $this->getJson(MES."/phases/{$phaseId}/traceability")->assertOk();
    expect($trace->json('data.materials.0.lot_code'))->toBe('AL-2026-001')
        ->and($trace->json('data.materials.0.item_code'))->toBe('RM-ALLOY');

    $this->postJson(MES."/phases/{$phaseId}/material-consumptions", mesBody(['mfg_item_id' => $this->product['steel']->id, 'quantity' => '1', 'lot_code' => 'X1']))->assertUnprocessable()->assertJsonValidationErrors(['mfg_item_id']);
});

test('lots registered on a backflushed component are not issued a second time when the phase closes', function (): void {
    $phaseId = mesTakeOver($this, $this->op10);
    $this->postJson(MES."/phases/{$phaseId}/start-production", mesBody())->assertOk();
    $this->postJson(MES."/phases/{$phaseId}/material-consumptions", mesBody(['mfg_item_id' => $this->product['seal']->id, 'quantity' => '4']))->assertOk();
    expect(mfgQuantities($this->product['seal'], $this->warehouse)['on_hand'])->toBe('96.0000');

    $this->postJson(MES."/phases/{$phaseId}/declarations", mesBody(['good_quantity' => '10']))->assertOk();
    $this->postJson(MES."/phases/{$phaseId}/close", mesBody())->assertOk();
    expect(mfgQuantities($this->product['seal'], $this->warehouse)['on_hand'])->toBe('90.0000');
});

test('records and roles are enforced: other companies see nothing and readers cannot act', function (): void {
    $phaseId = mesTakeOver($this, $this->op10);

    mfgActAs($this, $this->reader);
    $this->getJson(MES."/board?work_center_id={$this->product['wc']->id}")->assertOk();
    $this->postJson(MES."/phases/{$phaseId}/start-setup", mesBody())->assertForbidden();

    $stranger = mfgUser($this, 'owner', $this->otherTenant);
    mfgActAs($this, $stranger, $this->otherTenant);
    $this->postJson(MES."/phases/{$phaseId}/start-setup", mesBody())->assertNotFound();
    $this->getJson(MES."/phases/{$phaseId}/traceability")->assertNotFound();
    $this->getJson(MES."/board?work_center_id={$this->product['wc']->id}")->assertUnprocessable()->assertJsonValidationErrors(['work_center_id']);
    expect(MfgMesPhase::withoutGlobalScopes()->count())->toBe(1);
});
