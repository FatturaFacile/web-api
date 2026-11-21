<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Handlers;

use Illuminate\Database\Eloquent\Builder;
use Modules\ShiftPlanning\Application\Queries\GetTeamShiftsQuery;
use Modules\ShiftPlanning\Domain\Contracts\EmployeeContractPort;
use Modules\ShiftPlanning\Domain\Enums\RosterStatus;
use Modules\ShiftPlanning\Infrastructure\Models\ScheduledShiftAssignment;

/**
 * The team calendar of an employee: who works when in the rosters the employee belongs to. Colleagues are shown by name,
 * template and time only: no cost, no compliance findings, no open shifts.
 */
final class GetTeamShiftsHandler
{
    private const int LIMIT = 500;

    public function __construct(private readonly EmployeeContractPort $contracts) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(GetTeamShiftsQuery $query): array
    {
        $from = $query->from->format('Y-m-d');
        $to = $query->to->format('Y-m-d');
        $published = static fn (Builder $period) => $period->whereIn('status', [RosterStatus::Published, RosterStatus::Locked]);

        $periodIds = ScheduledShiftAssignment::query()
            ->where('tenant_id', $query->tenantId)
            ->where('employee_id', $query->employeeId)
            ->whereDate('shift_date', '>=', $from)
            ->whereDate('shift_date', '<=', $to)
            ->whereHas('period', $published)
            ->pluck('roster_schedule_period_id')
            ->unique()
            ->values()
            ->all();

        if ($periodIds === []) {
            return [];
        }

        $shifts = ScheduledShiftAssignment::query()
            ->with('template')
            ->where('tenant_id', $query->tenantId)
            ->whereIn('roster_schedule_period_id', $periodIds)
            ->whereNotNull('employee_id')
            ->where('employee_id', '!=', $query->employeeId)
            ->whereDate('shift_date', '>=', $from)
            ->whereDate('shift_date', '<=', $to)
            ->orderBy('start_at')
            ->limit(self::LIMIT)
            ->get();

        $names = $this->contracts->profilesFor($shifts->pluck('employee_id')->unique()->values()->all(), $query->from);

        return $shifts->map(static fn (ScheduledShiftAssignment $shift): array => [
            'id' => $shift->id,
            'employee_id' => $shift->employee_id,
            'employee_name' => ($names[(string) $shift->employee_id] ?? null)?->fullName,
            'shift_date' => $shift->shift_date->format('Y-m-d'),
            'template' => $shift->template === null ? null : ['code' => $shift->template->code, 'name' => $shift->template->name, 'color_hex' => $shift->template->color_hex],
            'start_at' => $shift->start_at->toIso8601String(),
            'end_at' => $shift->end_at->toIso8601String(),
            'tags' => $shift->surcharges()->tags(),
        ])->values()->all();
    }
}
