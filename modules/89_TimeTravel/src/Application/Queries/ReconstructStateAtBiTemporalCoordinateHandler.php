<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Application\Queries;

use Modules\TimeTravel\Services\EventStreamReader;
use Modules\TimeTravel\Services\ProjectorRegistry;

/**
 * Reconstructs a projection at a bi-temporal coordinate: starts from the nearest materialized checkpoint inside the coordinate and folds
 * only the delta events (effective by the valid time, recorded by the known time, not yet in the checkpoint). Without a checkpoint the
 * whole stream up to the coordinate is folded; the result is identical either way.
 */
final readonly class ReconstructStateAtBiTemporalCoordinateHandler
{
    public function __construct(private EventStreamReader $reader, private ProjectorRegistry $projectors) {}

    /**
     * @return array{domain: string, coordinate: array{valid_at: string, known_at: string}, rows: list<array<string, mixed>>, totals: array<string, mixed>, event_count: int, checkpoint_used: bool, duration_ms: float}
     */
    public function handle(ReconstructStateAtBiTemporalCoordinateQuery $query): array
    {
        $started = hrtime(true);
        $projector = $this->projectors->for($query->domain->aggregateType());
        $folded = $this->fold($query);
        $rendered = $projector->render($query->domain, $folded['state'], $query->filters, $query->coordinate->validAt, $query->tenantId);

        return [
            'domain' => $query->domain->value,
            'coordinate' => $query->coordinate->toArray(),
            'rows' => $rendered['rows'],
            'totals' => $rendered['totals'],
            'event_count' => $folded['event_count'],
            'checkpoint_used' => $folded['checkpoint_used'],
            'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
        ];
    }

    /**
     * @return array{state: array<string, mixed>, event_count: int, checkpoint_used: bool}
     */
    public function fold(ReconstructStateAtBiTemporalCoordinateQuery $query): array
    {
        $type = $query->domain->aggregateType();
        $projector = $this->projectors->for($type);
        $checkpoint = $query->useCheckpoints && $projector->usesCheckpoint($query->domain)
            ? $this->reader->nearestCheckpoint($query->tenantId, $type, $query->coordinate)
            : null;

        $state = $checkpoint !== null ? $checkpoint->state : $projector->initialState($query->domain, $query->filters);
        $count = $checkpoint !== null ? $checkpoint->eventCount : 0;

        foreach ($this->reader->visibleAt($query->tenantId, $type, $query->coordinate, $checkpoint) as $event) {
            $state = $projector->apply($state, $event);
            $count++;
        }

        return ['state' => $state, 'event_count' => $count, 'checkpoint_used' => $checkpoint !== null];
    }
}
