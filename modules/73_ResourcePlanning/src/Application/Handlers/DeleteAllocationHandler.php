<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\ResourcePlanning\Application\Services\AllocationPlanner;
use Modules\ResourcePlanning\Domain\Exceptions\ResourceNotFoundException;
use Modules\ResourcePlanning\Infrastructure\Models\ResourceAllocation;

/**
 * Removes an allocation and gives its hours back: the overload flags of the other bookings and the snapshots follow.
 */
final class DeleteAllocationHandler
{
    public function __construct(private readonly AllocationPlanner $planner) {}

    public function handle(string $allocationId): void
    {
        $allocation = ResourceAllocation::query()->find($allocationId)
            ?? throw new ResourceNotFoundException('allocation_not_found', 'The allocation does not exist.');

        DB::transaction(static fn () => $allocation->delete());

        $this->planner->synchronise([$allocation->employee_id], $allocation->start_date, $allocation->end_date);
    }
}
