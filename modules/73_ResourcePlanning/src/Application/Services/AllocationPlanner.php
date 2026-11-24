<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Services;

use Carbon\CarbonImmutable;
use Modules\ResourcePlanning\Application\DTOs\OverloadReport;
use Modules\ResourcePlanning\Domain\Contracts\OpportunityPort;
use Modules\ResourcePlanning\Domain\Contracts\ProjectPort;
use Modules\ResourcePlanning\Domain\Contracts\ResourceDirectoryPort;
use Modules\ResourcePlanning\Domain\Enums\BookingType;
use Modules\ResourcePlanning\Domain\Exceptions\ResourcePlanningException;
use Modules\ResourcePlanning\Domain\ValueObjects\PlannedBooking;
use Modules\ResourcePlanning\Domain\ValueObjects\ResourceProfile;

/**
 * The rules every booking command shares: the employee must have an active contract, the booking must point at an
 * allocatable project (Mod 078) or an open opportunity (Mod 035), the period must contain working days, and after a write
 * the overload flags and the weekly snapshots are brought up to date.
 */
final class AllocationPlanner
{
    public function __construct(
        private readonly ResourceDirectoryPort $directory,
        private readonly ProjectPort $projects,
        private readonly OpportunityPort $opportunities,
        private readonly CapacityLoadService $load,
        private readonly OverloadService $overload,
        private readonly CapacitySnapshotService $snapshots,
    ) {}

    public function profile(string $employeeId, CarbonImmutable $date): ResourceProfile
    {
        return $this->directory->resources([$employeeId], null, $date)[0]
            ?? throw new ResourcePlanningException('employee_not_found', 'The employee has no active contract.', ['employee_id' => $employeeId]);
    }

    public function assertPeriod(CarbonImmutable $start, CarbonImmutable $end): void
    {
        if ($end->startOfDay() < $start->startOfDay()) {
            throw new ResourcePlanningException('invalid_period', 'The end date precedes the start date.', ['start_date' => $start->format('Y-m-d'), 'end_date' => $end->format('Y-m-d')]);
        }
    }

    /**
     * The hours a booking of $hoursPerDay adds over the period; a period without a working day cannot be booked.
     */
    public function totalHours(ResourceProfile $profile, CarbonImmutable $start, CarbonImmutable $end, float $hoursPerDay): float
    {
        $total = $this->load->totalHours($profile, $start, $end, $hoursPerDay);

        if ($total <= 0.0) {
            throw new ResourcePlanningException('no_working_days', 'The period has no working day for the employee.', ['employee_id' => $profile->employeeId]);
        }

        return $total;
    }

    /**
     * A committed booking needs an allocatable project; a tentative one a project or an open opportunity; a WBS node must
     * belong to the project.
     */
    public function assertReferences(BookingType $type, ?string $projectId, ?string $wbsNodeId, ?string $opportunityId): void
    {
        if ($type->isCommitted() && $projectId === null) {
            throw new ResourcePlanningException('hard_booking_requires_project', 'A committed booking needs a project.');
        }

        if (! $type->isCommitted() && $projectId === null && $opportunityId === null) {
            throw new ResourcePlanningException('soft_booking_requires_reference', 'A tentative booking needs a project or an open opportunity.');
        }

        if ($projectId !== null) {
            $project = $this->projects->project($projectId);

            if ($project === null || ! $project->allocatable) {
                throw new ResourcePlanningException('project_not_allocatable', 'The project does not exist or no longer accepts allocations.', ['project_id' => $projectId, 'status' => $project?->status]);
            }
        }

        if ($opportunityId !== null) {
            $opportunity = $this->opportunities->opportunity($opportunityId);

            if ($opportunity === null || ! $opportunity->isOpen) {
                throw new ResourcePlanningException('opportunity_not_open', 'The opportunity does not exist or is closed.', ['crm_opportunity_id' => $opportunityId]);
            }
        }

        if ($wbsNodeId !== null) {
            $node = $this->projects->wbsNode($wbsNodeId);

            if ($node === null || $projectId === null || $node->projectId !== $projectId) {
                throw new ResourcePlanningException('wbs_node_not_in_project', 'The WBS node does not belong to the project.', ['wbs_node_id' => $wbsNodeId]);
            }
        }
    }

    /**
     * Refreshes the overload flags and the weekly snapshots of the employees over the window a write touched.
     *
     * @param  list<string>  $employeeIds
     */
    public function synchronise(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $employeeIds = array_values(array_unique($employeeIds));
        $this->overload->refresh($employeeIds, $from, $to);
        $this->snapshots->sync($employeeIds, $from, $to);
    }

    public function overloadOf(string $employeeId, CarbonImmutable $from, CarbonImmutable $to): OverloadReport
    {
        return $this->overload->report($employeeId, $from, $to);
    }

    /**
     * What the overload of the employee would be with an extra booking and without some allocations (no write).
     *
     * @param  list<string>  $excludeAllocationIds
     */
    public function simulate(ResourceProfile $profile, PlannedBooking $extra, array $excludeAllocationIds = []): OverloadReport
    {
        return $this->overload->simulate($profile, $extra, $excludeAllocationIds);
    }
}
