<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\Telemetry;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Manufacturing\Models\MfgEdgeGateway;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgMachineReading;
use Modules\Manufacturing\Models\MfgMachineSignalMap;
use Modules\Manufacturing\Models\MfgMachineStateEvent;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Services\EdgeTelemetryService;
use Ramsey\Uuid\Uuid;

/**
 * Converts the raw OPC UA, MQTT (JSON or Sparkplug B JSON) and Modbus messages relayed by an edge gateway into the
 * telemetry the module already understands, using the signal mappings configured per machine.
 *
 * State signals become machine state events and go through EdgeTelemetryService (live machine state, linked to the
 * running operation); numeric signals (piece counter, spindle speed, energy, power) are stored as machine readings.
 * Event and reading ids are derived from the content of each sample, so a message the gateway resends is counted as a
 * duplicate. Only the conversion runs here: the protocol transport (OPC UA sessions, the MQTT broker connection, Modbus
 * TCP polling) stays in the edge gateway, which posts the messages to the authenticated gateway-messages endpoint.
 */
final class GatewayMessageIngestor
{
    /** @var array<string, GatewayMessageNormalizer> */
    private array $normalizers = [];

    public function __construct(
        private readonly EdgeTelemetryService $telemetry,
        OpcUaNormalizer $opcUa,
        MqttNormalizer $mqtt,
        ModbusNormalizer $modbus,
    ) {
        foreach ([$opcUa, $mqtt, $modbus] as $normalizer) {
            $this->normalizers[$normalizer->protocol()] = $normalizer;
        }
    }

    /**
     * The normalizer of a protocol.
     */
    public function normalizer(string $protocol): GatewayMessageNormalizer
    {
        return $this->normalizers[$protocol] ?? throw new InvalidArgumentException('Unsupported protocol '.$protocol);
    }

    /**
     * Converts and stores a batch of messages of one protocol.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return array{messages: int, samples: int, unmapped: int, invalid: int, states: array{accepted: int, duplicates: int, rejected: list<string>}, readings: array{accepted: int, duplicates: int}, errors: list<array{index: int, error: string}>}
     */
    public function ingest(MfgEdgeGateway $gateway, string $protocol, array $messages, ?CarbonImmutable $receivedAt = null): array
    {
        $normalizer = $this->normalizer($protocol);
        $receivedAt ??= CarbonImmutable::now();
        $maps = $this->maps($gateway, $protocol);
        $errors = [];
        $unmapped = 0;
        $invalid = 0;
        $samples = 0;
        $events = [];
        $readings = [];

        foreach ($messages as $index => $message) {
            try {
                $signals = $normalizer->extract($message, $receivedAt);
            } catch (InvalidGatewayMessageException $exception) {
                $errors[] = ['index' => $index, 'error' => $exception->getMessage()];

                continue;
            }

            foreach ($signals as $signal) {
                $matching = $maps->filter(static fn (MfgMachineSignalMap $map): bool => $normalizer->matches($signal->sourceKey, $map->source_key));

                if ($matching->isEmpty()) {
                    $unmapped++;

                    continue;
                }

                foreach ($matching as $map) {
                    $raw = $normalizer->decode($signal, $map);
                    $value = $this->convert($map, $raw);

                    if ($value === null) {
                        $invalid++;

                        continue;
                    }

                    $samples++;
                    $uuid = $this->sampleUuid($gateway, $map, $signal, $raw);

                    if ($map->signal === MfgMachineSignalMap::StateSignal) {
                        $events[] = [
                            'event_uuid' => $uuid,
                            'machine_id' => $map->machine_id,
                            'state' => (string) $value,
                            'occurred_at' => $signal->occurredAt->toIso8601String(),
                            'payload' => ['protocol' => $protocol, 'source_key' => $signal->sourceKey, 'raw' => $this->printable($raw)],
                        ];
                    } else {
                        $readings[] = ['map' => $map, 'uuid' => $uuid, 'value' => (float) $value, 'at' => $signal->occurredAt, 'raw' => $this->printable($raw), 'source_key' => $signal->sourceKey];
                    }
                }
            }
        }

        $states = $events === [] ? ['accepted' => 0, 'duplicates' => 0, 'rejected' => []] : $this->telemetry->ingest($gateway, $events);

        return [
            'messages' => count($messages),
            'samples' => $samples,
            'unmapped' => $unmapped,
            'invalid' => $invalid,
            'states' => $states,
            'readings' => $this->storeReadings($gateway, $protocol, $readings),
            'errors' => $errors,
        ];
    }

    /**
     * The numeric or state value of a decoded raw value according to the mapping; null when it cannot be converted.
     */
    public function convert(MfgMachineSignalMap $map, mixed $raw): string|float|null
    {
        if ($map->signal === MfgMachineSignalMap::StateSignal) {
            $key = $this->stateKey($raw);
            $state = $key === null ? null : (($map->state_map ?? [])[$key] ?? null);

            if ($state === null && is_string($raw) && in_array(strtoupper(trim($raw)), MfgMachineStateEvent::States, true)) {
                $state = strtoupper(trim($raw));
            }

            return is_string($state) && in_array($state, MfgMachineStateEvent::States, true) ? $state : null;
        }

        if (is_bool($raw)) {
            $raw = $raw ? 1 : 0;
        }

        if (! is_numeric($raw) || ! is_finite((float) $raw)) {
            return null;
        }

        $value = round((float) $raw * (float) $map->scale_factor + (float) $map->offset_value, 4);

        return abs($value) < 1.0E+15 ? $value : null;
    }

    /**
     * Active mappings that apply to the gateway: its own and those valid for every gateway of the company.
     *
     * @return Collection<int, MfgMachineSignalMap>
     */
    private function maps(MfgEdgeGateway $gateway, string $protocol): Collection
    {
        return MfgMachineSignalMap::withoutGlobalScopes()
            ->where('tenant_id', $gateway->tenant_id)
            ->where('protocol', $protocol)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(static fn ($query) => $query->whereNull('gateway_id')->orWhere('gateway_id', $gateway->id))
            ->get();
    }

    private function stateKey(mixed $raw): ?string
    {
        return match (true) {
            is_bool($raw) => $raw ? 'true' : 'false',
            is_int($raw) => (string) $raw,
            is_float($raw) => floor($raw) === $raw ? (string) (int) $raw : (string) $raw,
            is_string($raw) => trim($raw),
            default => null,
        };
    }

    private function sampleUuid(MfgEdgeGateway $gateway, MfgMachineSignalMap $map, RawSignal $signal, mixed $raw): string
    {
        $name = implode('|', [$gateway->id, $map->id, $signal->sourceKey, $signal->occurredAt->format('Uv'), json_encode($raw)]);

        return Uuid::uuid5(Uuid::NAMESPACE_OID, $name)->toString();
    }

    private function printable(mixed $raw): mixed
    {
        return is_scalar($raw) || $raw === null || is_array($raw) ? $raw : null;
    }

    /**
     * @param  list<array{map: MfgMachineSignalMap, uuid: string, value: float, at: CarbonImmutable, raw: mixed, source_key: string}>  $readings
     * @return array{accepted: int, duplicates: int}
     */
    private function storeReadings(MfgEdgeGateway $gateway, string $protocol, array $readings): array
    {
        $tenantId = (string) $gateway->tenant_id;
        $accepted = 0;
        $duplicates = 0;
        $machines = [];

        DB::transaction(function () use ($gateway, $protocol, $readings, $tenantId, &$accepted, &$duplicates, &$machines): void {
            foreach ($readings as $reading) {
                $map = $reading['map'];

                if (MfgMachineReading::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('reading_uuid', $reading['uuid'])->exists()) {
                    $duplicates++;

                    continue;
                }

                $machines[$map->machine_id] ??= MfgMachineCenter::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereKey($map->machine_id)->exists();

                if (! $machines[$map->machine_id]) {
                    continue;
                }

                $operation = MfgProductionOrderOperation::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('machine_center_id', $map->machine_id)
                    ->whereNotNull('started_at')->whereNull('completed_at')->where('started_at', '<=', $reading['at'])->orderByDesc('started_at')->first();

                MfgMachineReading::withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId,
                    'gateway_id' => $gateway->id,
                    'machine_id' => $map->machine_id,
                    'reading_uuid' => $reading['uuid'],
                    'signal' => $map->signal,
                    'value' => number_format($reading['value'], 4, '.', ''),
                    'unit' => $map->unit,
                    'occurred_at' => $reading['at'],
                    'production_order_operation_id' => $operation?->id,
                    'payload' => ['protocol' => $protocol, 'source_key' => $reading['source_key'], 'raw' => $reading['raw']],
                ]);
                $accepted++;
            }
        });

        return ['accepted' => $accepted, 'duplicates' => $duplicates];
    }
}
