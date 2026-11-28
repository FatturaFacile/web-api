<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Domain\ValueObjects\BiTemporalCoordinate;
use Modules\TimeTravel\Domain\ValueObjects\MaterializedStateCheckpoint;
use Modules\TimeTravel\Models\BiTemporalEvent;
use Modules\TimeTravel\Models\MaterializedCheckpoint;
use Modules\TimeTravel\Support\Instant;

/**
 * Reads the event store. Every query is explicitly bound to the tenant (on top of the tenant scope of the models), so a reader given a
 * tenant id never sees the stream of another.
 */
final class EventStreamReader
{
    /**
     * @return Builder<BiTemporalEvent>
     */
    public function stream(string $tenantId, AggregateType $type): Builder
    {
        return BiTemporalEvent::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('stream_id', $type->streamId());
    }

    /**
     * The events known at the coordinate: effective by the valid time and recorded by the known time, in the order they were recorded.
     * With a checkpoint, only the delta: what the checkpoint did not contain yet.
     *
     * @return LazyCollection<int, BiTemporalEvent>
     */
    public function visibleAt(string $tenantId, AggregateType $type, BiTemporalCoordinate $coordinate, ?MaterializedStateCheckpoint $after = null): LazyCollection
    {
        $query = $this->stream($tenantId, $type)
            ->where('valid_at', '<=', Instant::db($coordinate->validAt))
            ->where('recorded_at', '<=', Instant::db($coordinate->knownAt));

        if ($after !== null) {
            $validAt = Instant::db($after->validAt);
            $recordedAt = Instant::db($after->recordedAt);
            $query->where(fn (Builder $outside) => $outside->where('valid_at', '>', $validAt)->orWhere('recorded_at', '>', $recordedAt));
        }

        return $query->orderBy('sequence_number')->cursor();
    }

    /**
     * The checkpoint inside the coordinate (valid_at <= target valid time and recorded_at <= target known time) that already contains the
     * most events.
     */
    public function nearestCheckpoint(string $tenantId, AggregateType $type, BiTemporalCoordinate $coordinate): ?MaterializedStateCheckpoint
    {
        $model = MaterializedCheckpoint::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('aggregate_type', $type->value)
            ->where('valid_at', '<=', Instant::db($coordinate->validAt))
            ->where('recorded_at', '<=', Instant::db($coordinate->knownAt))
            ->orderByDesc('event_count')
            ->orderByDesc('recorded_at')
            ->first();

        return $model !== null ? MaterializedStateCheckpoint::fromModel($model) : null;
    }

    /**
     * Events recorded in the window (from, to] that are effective by the valid time: the late entries that change a closed cut-off.
     *
     * @return LazyCollection<int, BiTemporalEvent>
     */
    public function recordedBetween(string $tenantId, AggregateType $type, CarbonImmutable $validAt, CarbonImmutable $knownFrom, CarbonImmutable $knownTo): LazyCollection
    {
        return $this->stream($tenantId, $type)
            ->where('valid_at', '<=', Instant::db($validAt))
            ->where('recorded_at', '>', Instant::db($knownFrom))
            ->where('recorded_at', '<=', Instant::db($knownTo))
            ->orderBy('recorded_at')
            ->orderBy('sequence_number')
            ->cursor();
    }
}
