<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Manufacturing\Models\MfgMachineSignalMap;
use Modules\Manufacturing\Services\Telemetry\GatewayMessageIngestor;
use Modules\Manufacturing\Services\Telemetry\InvalidGatewayMessageException;
use Modules\Manufacturing\Services\Telemetry\ModbusNormalizer;
use Modules\Manufacturing\Services\Telemetry\MqttNormalizer;
use Modules\Manufacturing\Services\Telemetry\OpcUaNormalizer;
use Modules\Manufacturing\Services\Telemetry\RawSignal;
use Modules\Manufacturing\Services\Telemetry\RegisterDecoder;

/**
 * @param  array<string, mixed>  $attributes
 */
function gatewayMap(array $attributes): MfgMachineSignalMap
{
    return new MfgMachineSignalMap($attributes + ['word_order' => 'big', 'scale_factor' => '1', 'offset_value' => '0', 'is_active' => true]);
}

it('decodes Modbus registers by data type and word order', function (): void {
    expect(RegisterDecoder::decode([65535], 'int16'))->toBe(-1)
        ->and(RegisterDecoder::decode([65535], 'uint16'))->toBe(65535)
        ->and(RegisterDecoder::decode([1, 4464], 'uint32'))->toBe(70000)
        ->and(RegisterDecoder::decode([4464, 1], 'uint32', 'little'))->toBe(70000)
        ->and(RegisterDecoder::decode([65535, 65535], 'int32'))->toBe(-1)
        ->and(RegisterDecoder::decode([0x41B4, 0x0000], 'float32'))->toBe(22.5)
        ->and(RegisterDecoder::decode([0x0000, 0x41B4], 'float32', 'little'))->toBe(22.5)
        ->and(RegisterDecoder::decode([5], 'uint32'))->toBeNull()
        ->and(RegisterDecoder::decode([70000], 'uint16'))->toBeNull()
        ->and(RegisterDecoder::decode([0], 'bool'))->toBe(0);
});

it('reads the Modbus blocks of a message with the unit prefix optional in the mapping', function (): void {
    $normalizer = new ModbusNormalizer;
    $signals = $normalizer->extract([
        'unit_id' => 3,
        'timestamp' => '2027-01-05T10:00:00Z',
        'blocks' => [['function' => 'holding', 'address' => 100, 'registers' => [1, 4464, 7]], ['function' => 'coil', 'address' => 2, 'values' => [true]]],
    ], CarbonImmutable::now());

    expect(array_map(static fn (RawSignal $signal): string => $signal->sourceKey, $signals))->toBe(['3:holding:100', '3:holding:101', '3:holding:102', '3:coil:2'])
        ->and($signals[0]->value)->toBe([1, 4464])
        ->and($signals[2]->value)->toBe([7])
        ->and($signals[3]->value)->toBe([1])
        ->and($signals[0]->occurredAt->toIso8601String())->toBe('2027-01-05T10:00:00+00:00')
        ->and($normalizer->matches('3:holding:100', 'holding:100'))->toBeTrue()
        ->and($normalizer->matches('3:holding:100', '3:holding:100'))->toBeTrue()
        ->and($normalizer->matches('3:holding:100', '4:holding:100'))->toBeFalse()
        ->and($normalizer->decode($signals[0], gatewayMap(['data_type' => 'uint32'])))->toBe(70000);
});

it('rejects Modbus blocks that break the protocol limits', function (array $message): void {
    expect(fn () => (new ModbusNormalizer)->extract($message, CarbonImmutable::now()))->toThrow(InvalidGatewayMessageException::class);
})->with([
    'no blocks' => [['blocks' => []]],
    'unknown function' => [['blocks' => [['function' => 'write', 'address' => 1, 'registers' => [1]]]]],
    'address out of range' => [['blocks' => [['function' => 'holding', 'address' => 70000, 'registers' => [1]]]]],
    'too many registers' => [['blocks' => [['function' => 'holding', 'address' => 0, 'registers' => range(1, 126)]]]],
]);

it('reads OPC UA notifications, DataValues, Variants and PubSub network messages', function (): void {
    $normalizer = new OpcUaNormalizer;
    $now = CarbonImmutable::parse('2027-01-05T12:00:00Z');

    $single = $normalizer->extract(['nodeId' => 'ns=2;s=Press1.State', 'value' => ['Value' => 2, 'SourceTimestamp' => '2027-01-05T11:59:00Z']], $now);
    $variant = $normalizer->extract(['nodeId' => 'ns=2;s=Press1.Speed', 'value' => ['Type' => 10, 'Body' => 1250.5]], $now);
    $pubSub = $normalizer->extract(['MessageId' => 'm1', 'Messages' => [['Timestamp' => '2027-01-05T11:58:00Z', 'Payload' => ['ns=2;s=Press1.Count' => 120, 'ns=2;s=Press1.State' => ['Value' => 1]]]]], $now);

    expect($single[0]->sourceKey)->toBe('ns=2;s=Press1.State')
        ->and($single[0]->value)->toBe(2)
        ->and($single[0]->occurredAt->toIso8601String())->toBe('2027-01-05T11:59:00+00:00')
        ->and($variant[0]->value)->toBe(1250.5)
        ->and($variant[0]->occurredAt->equalTo($now))->toBeTrue()
        ->and($pubSub)->toHaveCount(2)
        ->and($pubSub[0]->value)->toBe(120)
        ->and($pubSub[1]->value)->toBe(1)
        ->and($pubSub[1]->occurredAt->toIso8601String())->toBe('2027-01-05T11:58:00+00:00')
        ->and(fn () => $normalizer->extract(['value' => 1], $now))->toThrow(InvalidGatewayMessageException::class);
});

it('matches MQTT topic filters and reads plain, JSON and Sparkplug B payloads', function (): void {
    $normalizer = new MqttNormalizer;
    $now = CarbonImmutable::parse('2027-01-05T12:00:00Z');

    expect($normalizer->matches('plant/press1/state', 'plant/+/state'))->toBeTrue()
        ->and($normalizer->matches('plant/press1/state', 'plant/#'))->toBeTrue()
        ->and($normalizer->matches('plant/press1/state', 'plant/+'))->toBeFalse()
        ->and($normalizer->matches('plant/press1', 'plant/press1/#'))->toBeTrue()
        ->and($normalizer->matches('plant', 'plant/press1/#'))->toBeFalse()
        ->and($normalizer->matches('plant/press1/state', 'plant/press2/state'))->toBeFalse();

    $plain = $normalizer->extract(['topic' => 'plant/press1/count', 'payload' => ' 42 '], $now);
    $json = $normalizer->extract(['topic' => 'plant/press1/data', 'payload' => '{"data":{"temp":71.5},"timestamp":1767614400000}'], $now);
    $sparkplug = $normalizer->extract(['topic' => 'spBv1.0/Plant/DDATA/Edge1/Press1', 'payload' => ['timestamp' => 1767614400000, 'metrics' => [['name' => 'Spindle/Speed', 'value' => 9000, 'timestamp' => 1767614460000], ['name' => 'Count', 'value' => 5]]]], $now);

    expect($plain[0]->value)->toBe('42')
        ->and($normalizer->decode($json[0], gatewayMap(['value_path' => 'data.temp'])))->toBe(71.5)
        ->and($normalizer->decode($json[0], gatewayMap(['value_path' => 'data.missing'])))->toBeNull()
        ->and($json[0]->occurredAt->getTimestamp())->toBe(1767614400)
        ->and($sparkplug[0]->sourceKey)->toBe('spBv1.0/Plant/DDATA/Edge1/Press1/Spindle/Speed')
        ->and($sparkplug[0]->occurredAt->getTimestamp())->toBe(1767614460)
        ->and($sparkplug[1]->occurredAt->getTimestamp())->toBe(1767614400)
        ->and(fn () => $normalizer->extract(['topic' => 'plant/+/state', 'payload' => 1], $now))->toThrow(InvalidGatewayMessageException::class);
});

it('converts decoded values with the state map, the scale and the offset of the mapping', function (): void {
    $ingestor = app(GatewayMessageIngestor::class);
    $state = gatewayMap(['signal' => 'state', 'state_map' => ['1' => 'RUNNING', '2' => 'FAULT', 'true' => 'IDLE']]);
    $speed = gatewayMap(['signal' => 'spindle_speed', 'scale_factor' => '0.1', 'offset_value' => '5']);

    expect($ingestor->convert($state, 1))->toBe('RUNNING')
        ->and($ingestor->convert($state, 2.0))->toBe('FAULT')
        ->and($ingestor->convert($state, '2'))->toBe('FAULT')
        ->and($ingestor->convert($state, true))->toBe('IDLE')
        ->and($ingestor->convert($state, 9))->toBeNull()
        ->and($ingestor->convert($state, 'setup'))->toBe('SETUP')
        ->and($ingestor->convert($speed, 8000))->toBe(805.0)
        ->and($ingestor->convert($speed, '1000'))->toBe(105.0)
        ->and($ingestor->convert($speed, 'n/a'))->toBeNull()
        ->and($ingestor->convert($speed, null))->toBeNull();
});
