<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\MesFeedback;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Manufacturing\Exceptions\MesEventRejectedException;
use Throwable;

/**
 * Translates an outbox message of the MES Core (`{id, type, dedupe_key, occurred_at, payload}`, see
 * `Mes.Infrastructure.LaravelSync.OutboxRelay`) into the event the feedback processor validates and applies.
 *
 * The MES Core carries no revision and no scope on its messages, so they are derived here:
 * - the revision of a snapshot is the Unix time (seconds) of `occurred_at`, which grows with every recompute;
 * - a shift snapshot is keyed by the shift reference, a recomputed window by its start and end, an order snapshot by the
 *   production run;
 * - `work_order_ref` is the id of the production order operation the MES Core pulled from `work-orders`.
 *
 * Only the envelope is interpreted; the translated event is still validated by the processor.
 */
final readonly class MesWireEventTranslator
{
    /**
     * @param  array<string, mixed>  $wire
     * @return array<string, mixed>
     *
     * @throws MesEventRejectedException when the message has a shape this API cannot translate
     */
    public function __invoke(array $wire, string $tenantId): array
    {
        $type = is_string($wire['type'] ?? null) ? $wire['type'] : '';
        $payload = is_array($wire['payload'] ?? null) ? $wire['payload'] : [];

        $event = [
            'event_id' => $wire['id'] ?? null,
            'idempotency_key' => $wire['dedupe_key'] ?? null,
            'event_type' => $type,
            'schema_version' => 1,
            'tenant_id' => $tenantId,
            'machine_id' => $payload['machine_id'] ?? null,
            'occurred_at' => $wire['occurred_at'] ?? null,
        ];

        $occurredAt = $this->timestamp($wire['occurred_at'] ?? null);

        return match ($type) {
            'OeeShiftClosed' => $event + [
                'scope' => 'Shift',
                'scope_key' => $this->limit((string) ($payload['shift_ref'] ?? '')),
                'revision' => $occurredAt,
                'policy_version' => $payload['policy_version'] ?? null,
                'as_of_event_time' => $payload['end'] ?? null,
                'metrics' => $this->oeeMetrics($payload['oee'] ?? null),
            ],
            'OeeSnapshotRevised' => $this->revised($event, $payload, $occurredAt),
            'OeeOrderCompleted' => $event + [
                'production_order_operation_id' => $this->operationId($payload),
                'scope' => 'Order',
                'scope_key' => $this->limit((string) ($payload['run_id'] ?? '')),
                'revision' => $occurredAt,
                'policy_version' => 0,
                'as_of_event_time' => $payload['ended_at'] ?? null,
                'metrics' => [
                    'good_count' => max((int) ($payload['total'] ?? 0) - (int) ($payload['scrap'] ?? 0), 0),
                    'scrap_count' => (int) ($payload['scrap'] ?? 0),
                ],
            ],
            'ProductionCountCheckpoint' => $event + [
                'production_order_operation_id' => $this->operationId($payload),
                'as_of_event_time' => $payload['at'] ?? null,
                'metrics' => [
                    'good_count' => (int) ($payload['good'] ?? 0),
                    'scrap_count' => max((int) ($payload['total'] ?? 0) - (int) ($payload['good'] ?? 0), 0),
                ],
            ],
            'MachineAlarmRaised' => $event + ['as_of_event_time' => $payload['raised_at'] ?? null],
            default => $event,
        };
    }

    /**
     * A recomputed window is a shift-scoped snapshot; the scrap correction of one run has no window and no operation, so
     * it cannot be attributed and is refused instead of being dropped without a trace.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function revised(array $event, array $payload, int $occurredAt): array
    {
        if (! isset($payload['window_start'], $payload['window_end'])) {
            throw new MesEventRejectedException('A scrap correction without a window cannot be attributed to an operation.', 'event.unsupported_shape');
        }

        return $event + [
            'scope' => 'Shift',
            'scope_key' => $this->limit('w:'.$this->timestamp($payload['window_start']).':'.$this->timestamp($payload['window_end'])),
            'revision' => $occurredAt,
            'policy_version' => $payload['policy_version'] ?? null,
            'as_of_event_time' => $payload['window_end'],
            'metrics' => $this->oeeMetrics($payload['oee'] ?? null),
        ];
    }

    /**
     * The metrics of the snapshot: every figure the MES Core computed, with the scrap derived from its counters.
     *
     * @return array<string, mixed>
     */
    private function oeeMetrics(mixed $oee): array
    {
        if (! is_array($oee)) {
            return [];
        }

        return $oee + ['scrap_count' => max((int) ($oee['total_count'] ?? 0) - (int) ($oee['good_count'] ?? 0), 0)];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function operationId(array $payload): ?string
    {
        $reference = $payload['production_order_operation_id'] ?? $payload['work_order_ref'] ?? null;

        return is_string($reference) && Str::isUuid($reference) ? $reference : null;
    }

    private function timestamp(mixed $value): int
    {
        if (! is_string($value)) {
            return 0;
        }

        try {
            return Carbon::parse($value)->getTimestamp();
        } catch (Throwable) {
            return 0;
        }
    }

    private function limit(string $key): string
    {
        return mb_strlen($key) > 64 ? mb_substr($key, 0, 64) : $key;
    }
}
