<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\ShiftPlanning\Application\Queries\GetEmployeeShiftsQuery;
use Modules\ShiftPlanning\Application\Services\ShiftComplianceValidator;
use Modules\ShiftPlanning\Domain\Enums\RosterStatus;
use Modules\ShiftPlanning\Infrastructure\Models\ScheduledShiftAssignment;
use Modules\ShiftPlanning\Presentation\Http\Resources\ShiftAssignmentResource;

/**
 * The mobile agenda and the open-shift marketplace of an employee.
 */
final class GetEmployeeShiftsHandler
{
    private const int LIMIT = 200;

    public function __construct(private readonly ShiftComplianceValidator $validator) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(GetEmployeeShiftsQuery $query): array
    {
        $shifts = ScheduledShiftAssignment::query()
            ->with(['template', 'period'])
            ->where('tenant_id', $query->tenantId)
            ->whereDate('shift_date', '>=', $query->from->format('Y-m-d'))
            ->whereDate('shift_date', '<=', $query->to->format('Y-m-d'))
            ->whereHas('period', static fn (Builder $period) => $period->whereIn('status', [RosterStatus::Published, RosterStatus::Locked]))
            ->when($query->openShifts,
                static fn (Builder $q) => $q->whereNull('employee_id')->where('start_at', '>', CarbonImmutable::now()),
                static fn (Builder $q) => $q->where('employee_id', $query->employeeId))
            ->orderBy('start_at')
            ->limit(self::LIMIT)
            ->get();

        $rows = [];

        foreach ($shifts as $shift) {
            $row = ShiftAssignmentResource::make($shift)->resolve();

            if ($query->openShifts) {
                $report = $this->validator->validate($query->tenantId, $shift->period->jurisdiction, $shift->toPlannedShift($query->employeeId), $shift->required_role_or_skill_id);

                if ($report->hasBlocking() || $shift->period->status !== RosterStatus::Published) {
                    continue;
                }

                $row['claim_warnings'] = array_map(static fn ($issue): array => $issue->toArray(), $report->warnings());
                $row['estimated_labor_cost'] = $report->estimatedLaborCost;
            } else {
                // The employee sees the tags of the surcharges, never the cost of the shift.
                unset($row['estimated_labor_cost']);
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
