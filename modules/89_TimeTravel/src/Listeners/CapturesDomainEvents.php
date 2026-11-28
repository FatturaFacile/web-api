<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Listeners;

use Carbon\CarbonImmutable;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Domain\ValueObjects\BiTemporalDomainEventEnvelope;
use Modules\TimeTravel\Services\BiTemporalEventRecorder;
use Modules\TimeTravel\Support\Instant;

/**
 * Shared by the capture listeners: the switch, the actor and the hand-over to the recorder. The listeners run synchronously inside the
 * transaction of the domain write, so the event store and the business tables commit together.
 */
trait CapturesDomainEvents
{
    public function __construct(private readonly BiTemporalEventRecorder $recorder) {}

    private function enabled(string $tenantId): bool
    {
        return $tenantId !== '' && (bool) config('time_travel.enabled', true);
    }

    private function actor(?string $fallback = null): ?string
    {
        $id = auth()->id();

        if ($id !== null) {
            return (string) $id;
        }

        return $fallback !== null && $fallback !== '' ? $fallback : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    private function record(string $tenantId, AggregateType $type, string $aggregateId, string $eventType, CarbonImmutable $validAt, array $payload, string $source, ?string $actor = null, array $metadata = []): void
    {
        $this->recorder->record(new BiTemporalDomainEventEnvelope(
            $tenantId,
            $type,
            $aggregateId,
            $eventType,
            $validAt,
            $payload,
            ['source_event' => $source, ...$metadata],
            $this->actor($actor),
        ));
    }

    private function validAtOf(string $value, ?string $fallback = null): CarbonImmutable
    {
        $value = trim($value);

        if ($value === '') {
            return $fallback !== null ? $this->validAtOf($fallback) : Instant::now();
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? Instant::startOfDay($value) : Instant::from($value);
    }
}
