<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgMachineReading;
use Modules\Manufacturing\Models\MfgMachineStateEvent;
use Modules\Manufacturing\Services\EdgeTelemetryService;

require_once __DIR__.'/ManufacturingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    mfgSetUp($this);
    $this->product = mfgProductSetUp($this);
    $this->machine = MfgMachineCenter::create([
        'tenant_id' => $this->tenant->id,
        'machine_code' => 'CNC-IOT-01',
        'name' => 'Machining centre on the IoT gateway',
        'work_center_id' => $this->product['wc']->id,
        'hourly_rate' => '95',
        'power_kw' => '22',
        'status' => 'active',
    ]);
    $gateway = $this->postJson('/api/v1/manufacturing/edge/gateways', ['name' => 'Protocol gateway'])->assertCreated();
    $this->gatewayId = (string) $gateway->json('data.id');
    $this->secret = (string) $gateway->json('data.secret');
});

/**
 * Posts raw protocol messages signed with the gateway secret, as a gateway or MQTT bridge does.
 *
 * @param  array<string, mixed>  $body
 */
function mfgGatewayMessages(object $test, array $body, ?string $signature = null): TestResponse
{
    $raw = (string) json_encode($body);

    return $test->call('POST', '/api/v1/manufacturing/edge/gateway-messages', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_GATEWAY_ID' => $test->gatewayId,
        'HTTP_X_SIGNATURE' => $signature ?? EdgeTelemetryService::sign($test->secret, $raw),
    ], $raw);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function mfgSignalMap(object $test, array $attributes): TestResponse
{
    return $test->postJson('/api/v1/manufacturing/machine-signal-maps', ['machine_id' => (string) $test->machine->id] + $attributes);
}

test('signal mappings are validated per protocol, unique per machine and closed to other companies', function (): void {
    mfgSignalMap($this, ['protocol' => 'modbus', 'source_key' => 'register-7', 'signal' => 'state'])
        ->assertUnprocessable()->assertJsonValidationErrors(['source_key']);
    mfgSignalMap($this, ['protocol' => 'modbus', 'source_key' => 'holding:20', 'signal' => 'piece_count'])
        ->assertUnprocessable()->assertJsonValidationErrors(['data_type']);
    mfgSignalMap($this, ['protocol' => 'mqtt', 'source_key' => 'plant/#/state', 'signal' => 'state'])
        ->assertUnprocessable()->assertJsonValidationErrors(['source_key']);
    mfgSignalMap($this, ['protocol' => 'opcua', 'source_key' => 'ns=2;s=Count', 'signal' => 'piece_count', 'state_map' => ['1' => 'RUNNING']])
        ->assertUnprocessable()->assertJsonValidationErrors(['state_map']);
    mfgSignalMap($this, ['protocol' => 'opcua', 'source_key' => 'ns=2;s=State', 'signal' => 'state', 'state_map' => ['1' => 'BROKEN']])
        ->assertUnprocessable()->assertJsonValidationErrors(['state_map.1']);

    $created = mfgSignalMap($this, ['protocol' => 'opcua', 'source_key' => 'ns=2;s=State', 'signal' => 'state', 'state_map' => ['1' => 'RUNNING']])->assertCreated();
    mfgSignalMap($this, ['protocol' => 'opcua', 'source_key' => 'ns=2;s=State', 'signal' => 'state'])->assertUnprocessable()->assertJsonValidationErrors(['source_key']);
    $this->putJson('/api/v1/manufacturing/machine-signal-maps/'.$created->json('data.id'), ['machine_id' => (string) $this->machine->id, 'protocol' => 'opcua', 'source_key' => 'ns=2;s=State', 'signal' => 'state', 'state_map' => ['1' => 'RUNNING', '2' => 'FAULT']])
        ->assertOk()->assertJsonPath('data.state_map.2', 'FAULT');
    $this->getJson('/api/v1/manufacturing/machine-signal-maps?machine_id='.$this->machine->id)->assertOk()->assertJsonCount(1, 'data');

    $foreign = MfgMachineCenter::create(['tenant_id' => $this->otherTenant->id, 'machine_code' => 'OTHER-1', 'name' => 'Other company machine', 'work_center_id' => $this->product['wc']->id, 'hourly_rate' => '1', 'power_kw' => '1', 'status' => 'active']);
    $this->postJson('/api/v1/manufacturing/machine-signal-maps', ['machine_id' => (string) $foreign->id, 'protocol' => 'mqtt', 'source_key' => 'x/y', 'signal' => 'state'])
        ->assertUnprocessable()->assertJsonValidationErrors(['machine_id']);

    $this->deleteJson('/api/v1/manufacturing/machine-signal-maps/'.$created->json('data.id'))->assertNoContent();
    $this->getJson('/api/v1/manufacturing/machine-signal-maps')->assertOk()->assertJsonCount(0, 'data');
});

test('Modbus registers become the machine state, a piece counter and a scaled spindle speed, idempotently', function (): void {
    mfgSignalMap($this, ['protocol' => 'modbus', 'source_key' => '1:holding:10', 'signal' => 'state', 'data_type' => 'uint16', 'state_map' => ['1' => 'RUNNING', '2' => 'FAULT']])->assertCreated();
    mfgSignalMap($this, ['protocol' => 'modbus', 'source_key' => 'holding:20', 'signal' => 'piece_count', 'data_type' => 'uint32', 'unit' => 'pcs'])->assertCreated();
    mfgSignalMap($this, ['protocol' => 'modbus', 'source_key' => 'holding:30', 'signal' => 'spindle_speed', 'data_type' => 'uint16', 'scale_factor' => '0.1', 'unit' => 'rpm'])->assertCreated();

    $at = now()->subMinute()->toIso8601String();
    $body = ['protocol' => 'modbus', 'messages' => [
        ['unit_id' => 1, 'timestamp' => $at, 'blocks' => [
            ['function' => 'holding', 'address' => 10, 'registers' => [2]],
            ['function' => 'holding', 'address' => 20, 'registers' => [1, 4464]],
            ['function' => 'holding', 'address' => 30, 'registers' => [8000]],
            ['function' => 'holding', 'address' => 40, 'registers' => [1]],
        ]],
        ['unit_id' => 1, 'blocks' => []],
    ]];

    mfgGatewayMessages($this, $body, 'deadbeef')->assertUnauthorized();
    mfgGatewayMessages($this, ['protocol' => 'profibus', 'messages' => [['a' => 1]]])->assertUnprocessable()->assertJsonValidationErrors(['protocol']);

    mfgGatewayMessages($this, $body)->assertOk()
        ->assertJsonPath('data.messages', 2)
        ->assertJsonPath('data.samples', 3)
        ->assertJsonPath('data.unmapped', 2)
        ->assertJsonPath('data.states.accepted', 1)
        ->assertJsonPath('data.readings.accepted', 2)
        ->assertJsonPath('data.errors.0.index', 1);

    expect($this->machine->refresh()->current_state)->toBe('FAULT')
        ->and(MfgMachineStateEvent::query()->where('machine_id', $this->machine->id)->count())->toBe(1)
        ->and((string) MfgMachineReading::query()->where('signal', 'piece_count')->value('value'))->toBe('70000.0000')
        ->and((string) MfgMachineReading::query()->where('signal', 'spindle_speed')->value('value'))->toBe('800.0000')
        ->and(MfgMachineReading::query()->where('signal', 'piece_count')->value('unit'))->toBe('pcs');

    mfgGatewayMessages($this, $body)->assertOk()
        ->assertJsonPath('data.states.accepted', 0)->assertJsonPath('data.states.duplicates', 1)
        ->assertJsonPath('data.readings.accepted', 0)->assertJsonPath('data.readings.duplicates', 2);
    expect(MfgMachineReading::query()->count())->toBe(2);
});

test('OPC UA nodes and MQTT Sparkplug B metrics feed the same machine through the mappings', function (): void {
    mfgSignalMap($this, ['protocol' => 'opcua', 'source_key' => 'ns=2;s=CNC1.Mode', 'signal' => 'state', 'state_map' => ['0' => 'IDLE', '1' => 'RUNNING', '3' => 'SETUP']])->assertCreated();
    mfgSignalMap($this, ['protocol' => 'opcua', 'source_key' => 'ns=2;s=CNC1.Power', 'signal' => 'power_kw', 'scale_factor' => '0.001', 'unit' => 'kW'])->assertCreated();
    mfgSignalMap($this, ['protocol' => 'mqtt', 'source_key' => 'spBv1.0/Plant/DDATA/+/CNC1/Spindle/Speed', 'signal' => 'spindle_speed', 'unit' => 'rpm'])->assertCreated();
    mfgSignalMap($this, ['protocol' => 'mqtt', 'source_key' => 'plant/cnc1/status', 'signal' => 'state', 'value_path' => 'machine.state'])->assertCreated();

    mfgGatewayMessages($this, ['protocol' => 'opcua', 'messages' => [
        ['Messages' => [['Timestamp' => now()->subMinutes(5)->toIso8601String(), 'Payload' => ['ns=2;s=CNC1.Mode' => ['Value' => 3], 'ns=2;s=CNC1.Power' => 18500]]]],
        ['nodeId' => 'ns=2;s=CNC1.Mode', 'value' => ['Value' => 1, 'SourceTimestamp' => now()->subMinutes(2)->toIso8601String()]],
    ]])->assertOk()->assertJsonPath('data.samples', 3)->assertJsonPath('data.states.accepted', 2)->assertJsonPath('data.readings.accepted', 1);

    expect($this->machine->refresh()->current_state)->toBe('RUNNING')
        ->and((string) MfgMachineReading::query()->where('signal', 'power_kw')->value('value'))->toBe('18.5000');

    mfgGatewayMessages($this, ['protocol' => 'mqtt', 'messages' => [
        ['topic' => 'spBv1.0/Plant/DDATA/Edge7/CNC1', 'payload' => ['timestamp' => now()->subMinute()->getTimestampMs(), 'metrics' => [['name' => 'Spindle/Speed', 'value' => 9000]]]],
        ['topic' => 'plant/cnc1/status', 'payload' => '{"machine":{"state":"FAULT"}}', 'timestamp' => now()->toIso8601String()],
        ['topic' => 'plant/cnc2/status', 'payload' => '{"machine":{"state":"FAULT"}}'],
    ]])->assertOk()->assertJsonPath('data.samples', 2)->assertJsonPath('data.unmapped', 1)->assertJsonPath('data.states.accepted', 1);

    expect($this->machine->refresh()->current_state)->toBe('FAULT')
        ->and((string) MfgMachineReading::query()->where('signal', 'spindle_speed')->value('value'))->toBe('9000.0000');
});

test('a mapping bound to another gateway or switched off is not applied', function (): void {
    $other = $this->postJson('/api/v1/manufacturing/edge/gateways', ['name' => 'Second gateway'])->assertCreated();
    mfgSignalMap($this, ['protocol' => 'mqtt', 'source_key' => 'plant/a', 'signal' => 'piece_count', 'gateway_id' => (string) $other->json('data.id')])->assertCreated();
    mfgSignalMap($this, ['protocol' => 'mqtt', 'source_key' => 'plant/b', 'signal' => 'piece_count', 'is_active' => false])->assertCreated();

    mfgGatewayMessages($this, ['protocol' => 'mqtt', 'messages' => [['topic' => 'plant/a', 'payload' => 5], ['topic' => 'plant/b', 'payload' => 6]]])
        ->assertOk()->assertJsonPath('data.samples', 0)->assertJsonPath('data.unmapped', 2);
    expect(MfgMachineReading::query()->count())->toBe(0)
        ->and(Str::isUuid($this->gatewayId))->toBeTrue();
});
