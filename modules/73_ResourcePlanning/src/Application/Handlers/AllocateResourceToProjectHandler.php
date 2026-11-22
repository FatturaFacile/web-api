<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\ResourcePlanning\Application\Commands\AllocateResourceToProjectCommand;
use Modules\ResourcePlanning\Application\DTOs\AllocationOutcome;
use Modules\ResourcePlanning\Application\Services\AllocationPlanner;
use Modules\ResourcePlanning\Application\Services\SkillCompliance;
use Modules\ResourcePlanning\Domain\Exceptions\ResourcePlanningException;
use Modules\ResourcePlanning\Domain\ValueObjects\PlannedBooking;
use Modules\ResourcePlanning\Infrastructure\Models\ResourceAllocation;

final class AllocateResourceToProjectHandler
{
    public function __construct(private readonly AllocationPlanner $planner, private readonly SkillCompliance $skills) {}

    public function handle(AllocateResourceToProjectCommand $command): AllocationOutcome
    {
        $start = $command->startDate->startOfDay();
        $end = $command->endDate->startOfDay();

        $this->planner->assertPeriod($start, $end);
        $profile = $this->planner->profile($command->employeeId, $start);
        $this->planner->assertReferences($command->bookingType, $command->projectId, $command->wbsNodeId, $command->opportunityId);
        $total = $this->planner->totalHours($profile, $start, $end, $command->hoursPerDay);

        if (! $command->allowOverload) {
            $simulated = $this->planner->simulate($profile, new PlannedBooking($profile->employeeId, $start, $end, $command->hoursPerDay, $command->bookingType, $command->billable));

            if ($simulated->isOverloaded) {
                throw new ResourcePlanningException('overload', 'The booking would overload the employee.', ['employee_id' => $profile->employeeId, 'peak_load_percent' => $simulated->peakLoadPercent]);
            }
        }

        $allocation = DB::transaction(static fn (): ResourceAllocation => ResourceAllocation::query()->create([
            'project_id' => $command->projectId,
            'wbs_node_id' => $command->wbsNodeId,
            'employee_id' => $command->employeeId,
            'role_id' => $command->roleId,
            'booking_type' => $command->bookingType,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'allocated_hours_per_day' => $command->hoursPerDay,
            'total_allocated_hours' => $total,
            'is_billable' => $command->billable,
            'crm_opportunity_id' => $command->opportunityId,
            'notes' => $command->notes,
            'created_by' => $command->userId,
        ]));

        $this->planner->synchronise([$command->employeeId], $start, $end);

        return new AllocationOutcome(
            $allocation->refresh(),
            [$allocation],
            $this->planner->overloadOf($command->employeeId, $start, $end),
            $this->skills->warnings($command->employeeId, $command->requiredSkillIds, $start, $end),
        );
    }
}
