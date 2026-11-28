<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Services;

use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Domain\ValueObjects\BiTemporalDomainEventEnvelope;
use Modules\TimeTravel\Models\BiTemporalEvent;
use Modules\TimeTravel\Support\EventHasher;
use Modules\TimeTravel\Support\Instant;

/**
 * Appends an event to the stream of its tenant and aggregate type: next sequence number, recording time (strictly increasing in the
 * stream) and SHA-256 chain hash, under a transaction-scoped lock. Called from the listeners of the domain events, so the fact is
 * recorded in the same transaction as the write it describes: both commit or neither.
 */
final class BiTemporalEventRecorder
{
    public function record(BiTemporalDomainEventEnvelope $envelope): BiTemporalEvent
    {
        return DB::transaction(function () use ($envelope): BiTemporalEvent {
            $stream = $envelope->aggregateType->streamId();
            TransactionLock::acquire('time-travel:'.$envelope->tenantId.':'.$stream);

            $last = BiTemporalEvent::withoutGlobalScopes()
                ->where('tenant_id', $envelope->tenantId)
                ->where('stream_id', $stream)
                ->orderByDesc('sequence_number')
                ->first(['sequence_number', 'cryptographic_chain_hash', 'recorded_at']);

            $sequence = $last !== null ? $last->sequence_number + 1 : 1;
            $previousHash = $last !== null ? $last->cryptographic_chain_hash : EventHasher::GENESIS;

            $recordedAt = Instant::now();

            if ($last !== null) {
                $lastRecorded = Instant::from($last->recorded_at);

                if ($recordedAt->lessThanOrEqualTo($lastRecorded)) {
                    $recordedAt = $lastRecorded->addMicrosecond();
                }
            }

            $payload = $envelope->payload;
            $metadata = $envelope->metadata;

            $hash = EventHasher::hash($previousHash, self::hashFields(
                $envelope->tenantId,
                $stream,
                $envelope->aggregateType->value,
                $envelope->aggregateId,
                $sequence,
                $envelope->eventType,
                Instant::iso($envelope->validAt),
                Instant::iso($recordedAt),
                $payload,
                $metadata,
                $envelope->actorUserId,
            ));

            return BiTemporalEvent::create([
                'tenant_id' => $envelope->tenantId,
                'stream_id' => $stream,
                'aggregate_type' => $envelope->aggregateType->value,
                'aggregate_id' => $envelope->aggregateId,
                'sequence_number' => $sequence,
                'event_type' => $envelope->eventType,
                'valid_at' => Instant::db($envelope->validAt),
                'recorded_at' => Instant::db($recordedAt),
                'payload' => $payload,
                'metadata' => $metadata,
                'actor_user_id' => $envelope->actorUserId,
                'previous_event_hash' => $previousHash,
                'cryptographic_chain_hash' => $hash,
            ]);
        });
    }

    /**
     * Whether the aggregate already has an event (of a type, when given): the capture of a write that more than one domain event announces
     * is recorded once.
     *
     * @param  list<string>|null  $eventTypes
     */
    public function exists(string $tenantId, AggregateType $type, string $aggregateId, ?array $eventTypes = null): bool
    {
        return BiTemporalEvent::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('stream_id', $type->streamId())
            ->where('aggregate_id', $aggregateId)
            ->when($eventTypes !== null, fn ($query) => $query->whereIn('event_type', $eventTypes))
            ->exists();
    }

    /**
     * The fields that enter the hash of a stored event: the recorder and the verifier build them the same way.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    public static function hashFields(string $tenantId, string $streamId, string $aggregateType, string $aggregateId, int $sequence, string $eventType, string $validAtIso, string $recordedAtIso, array $payload, ?array $metadata, ?string $actorUserId): array
    {
        return [
            'tenant_id' => $tenantId,
            'stream_id' => $streamId,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'sequence_number' => $sequence,
            'event_type' => $eventType,
            'valid_at' => $validAtIso,
            'recorded_at' => $recordedAtIso,
            'payload' => $payload,
            'metadata' => $metadata ?? [],
            'actor_user_id' => $actorUserId,
        ];
    }

    public static function hashOf(BiTemporalEvent $event): string
    {
        return EventHasher::hash($event->previous_event_hash, self::hashFields(
            (string) $event->tenant_id,
            (string) $event->stream_id,
            (string) $event->aggregate_type,
            (string) $event->aggregate_id,
            (int) $event->sequence_number,
            (string) $event->event_type,
            Instant::iso(Instant::from((string) $event->valid_at)),
            Instant::iso(Instant::from((string) $event->recorded_at)),
            $event->payload,
            $event->metadata,
            $event->actor_user_id,
        ));
    }
}
