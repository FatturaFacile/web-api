<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\ResourcePlanning\Application\Commands\ConvertSoftToHardBookingCommand;
use Modules\ResourcePlanning\Application\DTOs\AllocationOutcome;
use Modules\ResourcePlanning\Application\Services\AllocationPlanner;
use Modules\ResourcePlanning\Domain\Enums\BookingType;
use Modules\ResourcePlanning\Domain\Exceptions\ResourceNotFoundException;
use Modules\ResourcePlanning\Domain\Exceptions\ResourcePlanningException;
use Modules\ResourcePlanning\Infrastructure\Models\ResourceAllocation;

final class ConvertSoftToHardBookingHandler
{
    public function __construct(private readonly AllocationPlanner $planner) {}

    public function handle(ConvertSoftToHardBookingCommand $command): AllocationOutcome
    {
        $allocation = ResourceAllocation::query()->find($command->allocationId)
            ?? throw new ResourceNotFoundException('allocation_not_found', 'The allocation does not exist.');

        if ($allocation->booking_type->isCommitted()) {
            throw new ResourcePlanningException('already_hard_committed', 'The allocation is already committed.');
        }

        $projectId = $command->projectId ?? $allocation->project_id;
        $wbsNodeId = $command->wbsNodeId ?? ($command->projectId === null || $command->projectId === $allocation->project_id ? $allocation->wbs_node_id : null);

        $this->planner->assertReferences(BookingType::HardCommitted, $projectId, $wbsNodeId, null);

        DB::transaction(static function () use ($allocation, $projectId, $wbsNodeId): void {
            $allocation->forceFill([
                'booking_type' => BookingType::HardCommitted,
                'project_id' => $projectId,
                'wbs_node_id' => $wbsNodeId,
                'converted_to_hard_at' => CarbonImmutable::now(),
            ])->save();
        });

        $this->planner->synchronise([$allocation->employee_id], $allocation->start_date, $allocation->end_date);

        return new AllocationOutcome(
            $allocation->refresh(),
            [$allocation],
            $this->planner->overloadOf($allocation->employee_id, $allocation->start_date, $allocation->end_date),
        );
    }
}
