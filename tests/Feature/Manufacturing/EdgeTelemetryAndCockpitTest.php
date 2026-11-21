<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgWipTransaction;
use Modules\Manufacturing\Services\EdgeTelemetryService;

require_once __DIR__.'/ManufacturingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    mfgSetUp($this);
    $this->product = mfgProductSetUp($this);
    $this->machine = MfgMachineCenter::create([
        'tenant_id' => $this->tenant->id,
        'machine_code' => 'CNC-NUM-01',
        'name' => 'NUM CNC milling centre',
        'work_center_id' => $this->product['wc']->id,
        'hourly_rate' => '95',
        'power_kw' => '22',
        'status' => 'active',
    ]);
});

/**
 * Posts a body to the edge endpoint signed with the secret, as an edge gateway does.
 *
 * @param  array<string, mixed>  $body
 */
function mfgEdgePost(object $test, string $gatewayId, string $secret, array $body, ?string $signature = null): TestResponse
{
    $raw = (string) json_encode($body);

    return $test->call('POST', '/api/v1/manufacturing/edge/telemetry', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_GATEWAY_ID' => $gatewayId,
        'HTTP_X_SIGNATURE' => $signature ?? EdgeTelemetryService::sign($secret, $raw),
    ], $raw);
}

/**
 * A released order of 10 pumps with its line and operation ids.
 *
 * @return array{id: string, steel_line: string, op10: string, op20: string}
 */
function mfgCockpitReleasedOrder(object $test): array
{
    $orderId = (string) $test->postJson('/api/v1/manufacturing/production-orders', [
        'mfg_item_id' => $test->product['fg']->id,
        'target_quantity' => '10',
        'planned_start_date' => now()->toDateString(),
        'planned_end_date' => now()->addDays(5)->toDateString(),
    ])->assertCreated()->json('data.id');

    $released = $test->postJson("/api/v1/manufacturing/production-orders/{$orderId}/release")->assertOk();
    $lines = collect($released->json('data.lines'))->keyBy('mfg_item_id');
    $operations = collect($released->json('data.operations'))->keyBy('sequence');

    return ['id' => $orderId, 'steel_line' => (string) $lines[$test->product['steel']->id]['id'], 'op10' => (string) $operations[10]['id'], 'op20' => (string) $operations[20]['id']];
}

test('edge telemetry is authenticated by HMAC, idempotent by event uuid and keeps the newest machine state', function (): void {
    $gateway = $this->postJson('/api/v1/manufacturing/edge/gateways', ['name' => 'Machining hall'])->assertCreated();
    $gatewayId = (string) $gateway->json('data.id');
    $secret = (string) $gateway->json('data.secret');
    $this->getJson('/api/v1/manufacturing/edge/gateways')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.secret');

    $newer = ['event_uuid' => (string) Str::uuid(), 'machine_id' => (string) $this->machine->id, 'state' => 'FAULT', 'occurred_at' => now()->toIso8601String()];
    $older = ['event_uuid' => (string) Str::uuid(), 'machine_id' => (string) $this->machine->id, 'state' => 'RUNNING', 'occurred_at' => now()->subMinutes(10)->toIso8601String(), 'payload' => ['spindle_rpm' => 8000]];
    $unknown = ['event_uuid' => (string) Str::uuid(), 'machine_id' => (string) Str::uuid(), 'state' => 'IDLE', 'occurred_at' => now()->toIso8601String()];
    $body = ['events' => [$newer, $older, $unknown]];

    mfgEdgePost($this, $gatewayId, $secret, $body, 'deadbeef')->assertUnauthorized();
    mfgEdgePost($this, (string) Str::uuid(), $secret, $body)->assertUnauthorized();
    mfgEdgePost($this, $gatewayId, $secret, ['events' => [['state' => 'BROKEN']]])->assertUnprocessable()->assertJsonValidationErrors(['events.0.event_uuid', 'events.0.state']);

    mfgEdgePost($this, $gatewayId, $secret, $body)->assertOk()
        ->assertJsonPath('data.accepted', 2)->assertJsonPath('data.duplicates', 0)->assertJsonPath('data.rejected.0', $unknown['event_uuid']);
    // The older event arrived in the same batch but must not overwrite the newer state; a replay stores nothing.
    expect($this->machine->refresh()->current_state)->toBe('FAULT');
    mfgEdgePost($this, $gatewayId, $secret, $body)->assertOk()->assertJsonPath('data.accepted', 0)->assertJsonPath('data.duplicates', 2);
});

test('the cockpit aggregates strategies, machine alerts and WIP, and the WIP ledger equals the order WIP balance', function (): void {
    $this->machine->forceFill(['current_state' => 'FAULT', 'state_since' => now()])->save();

    $order = mfgCockpitReleasedOrder($this);
    $id = $order['id'];
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/issues", ['lines' => [['line_id' => $order['steel_line'], 'quantity' => '20']]])->assertCreated();
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/operations/{$order['op10']}/confirmations", ['setup_minutes' => '30', 'run_minutes' => '66', 'good_quantity' => '10', 'is_final' => true])->assertCreated();

    $cockpit = $this->getJson('/api/v1/manufacturing/cockpit')->assertOk()
        ->assertJsonPath('data.orders.open', 1)
        ->assertJsonPath('data.orders.by_strategy.MTS', 1)
        ->assertJsonPath('data.orders.by_strategy.MTO', 0)
        ->assertJsonPath('data.machines.by_state.FAULT', 1)
        ->assertJsonPath('data.machines.alerts.0.machine_code', 'CNC-NUM-01');

    $wip = (string) MfgWipTransaction::query()->where('production_order_id', $id)->sum('amount');
    expect(bcadd($wip, '0', 2))->toBe(bcadd((string) $cockpit->json('data.wip.valuation'), '0', 2))
        ->and(bcadd($wip, '0', 2))->toBe(bcadd(mfgLedgerBalance((string) $this->tenant->id, '1210'), '0', 2));

    // Closing the order settles WIP: the ledger of the order nets to zero.
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/operations/{$order['op20']}/confirmations", ['run_minutes' => '30', 'good_quantity' => '10', 'is_final' => true])->assertCreated();
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/receipts", ['quantity' => '10'])->assertCreated();
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/complete")->assertOk();
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/close")->assertOk();
    expect(bcadd((string) MfgWipTransaction::query()->where('production_order_id', $id)->sum('amount'), '0', 2))->toBe('0.00');

    $this->getJson("/api/v1/manufacturing/production-orders/{$id}/genealogy")->assertOk()->assertJsonPath('data.production_order_id', $id)->assertJsonStructure(['data' => ['output_lots', 'operations', 'machine_events']]);
    $this->getJson('/api/v1/manufacturing/production-orders/'.Str::uuid().'/genealogy')->assertNotFound();
});
