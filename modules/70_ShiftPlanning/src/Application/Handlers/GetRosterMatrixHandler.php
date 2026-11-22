<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Handlers;

use Illuminate\Support\Collection;
use Modules\ShiftPlanning\Application\Queries\GetRosterMatrixQuery;
use Modules\ShiftPlanning\Domain\Contracts\EmployeeContractPort;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftNotFoundException;
use Modules\ShiftPlanning\Domain\ValueObjects\EmployeeContractProfile;
use Modules\ShiftPlanning\Infrastructure\Models\RosterSchedulePeriod;
use Modules\ShiftPlanning\Infrastructure\Models\ScheduledShiftAssignment;
use Modules\ShiftPlanning\Presentation\Http\Resources\RosterPeriodResource;
use Modules\ShiftPlanning\Presentation\Http\Resources\ShiftAssignmentResource;

/**
 * The scheduler grid: rows per employee (with scheduled against contractual hours) or per role, the open shifts, the daily
 * coverage heatmap (an open shift is a gap in the coverage of its template and day) and the labour cost totals.
 */
final class GetRosterMatrixHandler
{
    public function __construct(private readonly EmployeeContractPort $contracts) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ShiftNotFoundException
     */
    public function handle(GetRosterMatrixQuery $query): array
    {
        $period = RosterSchedulePeriod::query()->where('tenant_id', $query->tenantId)->whereKey($query->periodId)->first()
            ?? throw new ShiftNotFoundException('Unknown roster period.');
        $assignments = $period->assignments()->with('template')->orderBy('start_at')->get();
        $employeeIds = $assignments->pluck('employee_id')->filter()->unique()->values()->all();
        $profiles = $this->contracts->profilesFor($employeeIds, $period->start_date);
        $days = (int) $period->start_date->diffInDays($period->end_date) + 1;

        return [
            'period' => RosterPeriodResource::make($period)->resolve(),
            'days' => array_map(static fn (int $offset): string => $period->start_date->addDays($offset)->format('Y-m-d'), range(0, $days - 1)),
            'group_by' => $query->groupBy,
            'rows' => $query->groupBy === GetRosterMatrixQuery::GroupByRole
                ? $this->roleRows($assignments, $profiles)
                : $this->employeeRows($assignments, $profiles, $days),
            'open_shifts' => $assignments->filter(static fn (ScheduledShiftAssignment $a): bool => $a->isOpen())->map(static fn (ScheduledShiftAssignment $a): array => ShiftAssignmentResource::make($a)->resolve())->values()->all(),
            'coverage' => $this->coverage($assignments),
            'totals' => $this->totals($assignments),
        ];
    }

    /**
     * @param  Collection<int, ScheduledShiftAssignment>  $assignments
     * @param  array<string, EmployeeContractProfile>  $profiles
     * @return list<array<string, mixed>>
     */
    private function employeeRows(Collection $assignments, array $profiles, int $days): array
    {
        $rows = [];

        foreach ($assignments->filter(static fn (ScheduledShiftAssignment $a): bool => ! $a->isOpen())->groupBy('employee_id') as $employeeId => $shifts) {
            $profile = $profiles[(string) $employeeId] ?? null;
            $scheduled = $shifts->sum(static fn (ScheduledShiftAssignment $a): int => $a->surcharges()->workedMinutes);
            $contractual = $profile === null ? 0 : (int) round($profile->weeklyHours * 60 / 7 * $days);

            $rows[] = [
                'key' => (string) $employeeId,
                'employee_id' => (string) $employeeId,
                'employee_name' => $profile?->fullName,
                'contract_minutes' => $contractual,
                'scheduled_minutes' => $scheduled,
                'utilisation_percent' => $contractual > 0 ? round($scheduled / $contractual * 100, 1) : null,
                'labor_cost' => $this->sumCost($shifts),
                'cells' => $this->cells($shifts),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['employee_name'], (string) $b['employee_name']));

        return $rows;
    }

    /**
     * @param  Collection<int, ScheduledShiftAssignment>  $assignments
     * @param  array<string, EmployeeContractProfile>  $profiles
     * @return list<array<string, mixed>>
     */
    private function roleRows(Collection $assignments, array $profiles): array
    {
        $rows = [];

        foreach ($assignments->groupBy(static fn (ScheduledShiftAssignment $a): string => $a->required_role_or_skill_id ?? 'none') as $role => $shifts) {
            $rows[] = [
                'key' => (string) $role,
                'role_or_skill_id' => $role === 'none' ? null : (string) $role,
                'scheduled_minutes' => $shifts->sum(static fn (ScheduledShiftAssignment $a): int => $a->isOpen() ? 0 : $a->surcharges()->workedMinutes),
                'labor_cost' => $this->sumCost($shifts),
                'cells' => $this->cells($shifts, $profiles),
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, ScheduledShiftAssignment>  $shifts
     * @param  array<string, EmployeeContractProfile>|null  $profiles  adds the employee name to each shift (role rows)
     * @return array<string, list<array<string, mixed>>> shifts per date
     */
    private function cells(Collection $shifts, ?array $profiles = null): array
    {
        $cells = [];

        foreach ($shifts as $shift) {
            $cell = ShiftAssignmentResource::make($shift)->resolve();

            if ($profiles !== null) {
                $cell['employee_name'] = $shift->employee_id === null ? null : ($profiles[$shift->employee_id]->fullName ?? null);
            }

            $cells[$shift->shift_date->format('Y-m-d')][] = $cell;
        }

        return $cells;
    }

    /**
     * @param  Collection<int, ScheduledShiftAssignment>  $assignments
     * @return list<array<string, mixed>>
     */
    private function coverage(Collection $assignments): array
    {
        $cells = [];

        foreach ($assignments->groupBy(static fn (ScheduledShiftAssignment $a): string => $a->shift_date->format('Y-m-d').'|'.$a->shift_template_id) as $key => $shifts) {
            [$date, $templateId] = explode('|', (string) $key);
            $open = $shifts->filter(static fn (ScheduledShiftAssignment $a): bool => $a->isOpen())->count();
            $assigned = $shifts->count() - $open;
            $ratio = $shifts->count() === 0 ? 1.0 : $assigned / $shifts->count();

            $cells[] = [
                'date' => $date,
                'shift_template_id' => $templateId,
                'assigned' => $assigned,
                'open' => $open,
                'coverage_ratio' => round($ratio, 2),
                'level' => match (true) {
                    $open === 0 => 'ok',
                    $ratio < 0.5 => 'critical',
                    default => 'low',
                },
            ];
        }

        return $cells;
    }

    /**
     * @param  Collection<int, ScheduledShiftAssignment>  $assignments
     * @return array<string, mixed>
     */
    private function totals(Collection $assignments): array
    {
        $byDay = [];
        $blocking = $warnings = 0;

        foreach ($assignments as $assignment) {
            $day = $assignment->shift_date->format('Y-m-d');
            $byDay[$day] = ($byDay[$day] ?? 0) + (float) $assignment->estimated_labor_cost;

            foreach ($assignment->issues() as $issue) {
                $issue->isBlocking() ? $blocking++ : $warnings++;
            }
        }

        return [
            'labor_cost' => $this->sumCost($assignments),
            'labor_cost_by_day' => array_map(static fn (float $cost): string => number_format($cost, 2, '.', ''), $byDay),
            'scheduled_minutes' => $assignments->sum(static fn (ScheduledShiftAssignment $a): int => $a->isOpen() ? 0 : $a->surcharges()->workedMinutes),
            'open_shifts' => $assignments->filter(static fn (ScheduledShiftAssignment $a): bool => $a->isOpen())->count(),
            'blocking_issues' => $blocking,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  Collection<int, ScheduledShiftAssignment>  $shifts
     */
    private function sumCost(Collection $shifts): string
    {
        return number_format((float) $shifts->sum(static fn (ScheduledShiftAssignment $a): float => (float) $a->estimated_labor_cost), 2, '.', '');
    }
}
