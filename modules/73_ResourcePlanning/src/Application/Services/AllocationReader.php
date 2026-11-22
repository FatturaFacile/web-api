<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\ResourcePlanning\Domain\ValueObjects\PlannedBooking;
use Modules\ResourcePlanning\Infrastructure\Models\ResourceAllocation;

/**
 * Reads the allocations of the active tenant that overlap a window.
 */
final class AllocationReader
{
    /**
     * @param  list<string>|null  $employeeIds  every employee when null
     * @return Builder<ResourceAllocation>
     */
    public function overlapping(?array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return ResourceAllocation::query()
            ->when($employeeIds !== null, static fn (Builder $query) => $query->whereIn('employee_id', $employeeIds ?? []))
            ->whereDate('start_date', '<=', $to->format('Y-m-d'))
            ->whereDate('end_date', '>=', $from->format('Y-m-d'));
    }

    /**
     * @param  list<string>|null  $employeeIds
     * @return list<PlannedBooking>
     */
    public function bookings(?array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var Collection<int, ResourceAllocation> $allocations */
        $allocations = $this->overlapping($employeeIds, $from, $to)->get();

        return $allocations->map(static fn (ResourceAllocation $allocation): PlannedBooking => $allocation->toBooking())->values()->all();
    }
}
