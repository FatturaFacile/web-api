<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Infrastructure\Calculators;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\KpiDashboards\Domain\Enums\KpiCode;
use Modules\KpiDashboards\Domain\ValueObjects\CockpitFilter;
use Modules\KpiDashboards\Infrastructure\Services\KpiContext;

/**
 * HR cockpit: turnover, absenteeism, overtime and billable utilization (Mod 079). Only approved timesheets and approved
 * leave count. Overtime cost is compensation data and is masked without the `kpi.hr.compensation` grant.
 */
final class HrKpiCalculator implements KpiCalculator
{
    private const float HoursPerDay = 8.0;

    public function supports(): array
    {
        return [KpiCode::HeadcountTurnover, KpiCode::Absenteeism, KpiCode::OvertimeTrend, KpiCode::BillableUtilization];
    }

    public function headline(KpiCode $kpi, CockpitFilter $filter): ?float
    {
        return match ($kpi) {
            KpiCode::HeadcountTurnover => $this->turnover($filter)['rate'],
            KpiCode::Absenteeism => $this->absenteeism($filter)['rate'],
            KpiCode::OvertimeTrend => $this->overtime($filter)['hours'],
            KpiCode::BillableUtilization => $this->utilization($filter)['rate'],
            default => null,
        };
    }

    public function payload(KpiCode $kpi, KpiContext $context): array
    {
        $filter = $context->filter;

        return match ($kpi) {
            KpiCode::HeadcountTurnover => ['unit' => 'percent', ...$this->turnover($filter)],
            KpiCode::Absenteeism => $this->absenteeismPayload($filter),
            KpiCode::OvertimeTrend => $this->overtimePayload($context),
            KpiCode::BillableUtilization => ['unit' => 'percent', 'target' => 75, ...$this->utilization($filter)],
            default => [],
        };
    }

    private function employees(CockpitFilter $filter): Builder
    {
        return DB::table('hr_employees')
            ->where('tenant_id', $filter->tenantId)->whereNull('deleted_at')
            ->when($filter->companyIds !== [], static fn (Builder $query) => $query->whereIn('company_id', $filter->companyIds))
            ->when($filter->costCenterIds !== [], static fn (Builder $query) => $query->whereIn('cost_center_id', $filter->costCenterIds));
    }

    private function headcountAt(CockpitFilter $filter, CarbonImmutable $date): int
    {
        return $this->employees($filter)
            ->whereDate('hire_date', '<=', $date->toDateString())
            ->where(static fn (Builder $query) => $query->whereNull('termination_date')->orWhereDate('termination_date', '>', $date->toDateString()))
            ->count();
    }

    /**
     * Leavers of the period over the average of the opening and closing headcount.
     *
     * @return array{rate: float|null, leavers: int, opening_headcount: int, closing_headcount: int}
     */
    private function turnover(CockpitFilter $filter): array
    {
        $opening = $this->headcountAt($filter, $filter->from->subDay());
        $closing = $this->headcountAt($filter, $filter->to);
        $leavers = $this->employees($filter)
            ->whereDate('termination_date', '>=', $filter->from->toDateString())->whereDate('termination_date', '<=', $filter->to->toDateString())->count();
        $average = ($opening + $closing) / 2;

        return ['rate' => $average > 0 ? round($leavers / $average * 100, 2) : null, 'leavers' => $leavers, 'opening_headcount' => $opening, 'closing_headcount' => $closing];
    }

    private function workingDays(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $days = 0;

        for ($day = $from->startOfDay(); $day <= $to; $day = $day->addDay()) {
            $days += $day->isWeekend() ? 0 : 1;
        }

        return $days;
    }

    /**
     * Approved sick and accident leave hours over the contractual hours of the period (FTE x 8h x working days).
     *
     * @return array{rate: float|null, absence_hours: float, planned_hours: float}
     */
    private function absenteeism(CockpitFilter $filter, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $from ??= $filter->from;
        $to ??= $filter->to;
        $hours = (float) DB::table('hr_leave_requests')
            ->join('hr_leave_types', 'hr_leave_requests.leave_type_id', '=', 'hr_leave_types.id')
            ->where('hr_leave_requests.tenant_id', $filter->tenantId)->whereNull('hr_leave_requests.deleted_at')
            ->where('hr_leave_requests.status', 'approved')
            ->whereIn('hr_leave_types.category', ['sick', 'accident'])
            ->whereDate('hr_leave_requests.start_date', '>=', $from->toDateString())->whereDate('hr_leave_requests.start_date', '<=', $to->toDateString())
            ->when($filter->companyIds !== [], static fn (Builder $query) => $query->whereIn('hr_leave_requests.company_id', $filter->companyIds))
            ->sum('hr_leave_requests.total_hours');

        $fte = (float) $this->employees($filter)
            ->whereDate('hire_date', '<=', $to->toDateString())
            ->where(static fn (Builder $query) => $query->whereNull('termination_date')->orWhereDate('termination_date', '>', $to->toDateString()))
            ->sum('fte_ratio');
        $planned = $fte * self::HoursPerDay * $this->workingDays($from, $to);

        return ['rate' => $planned > 0 ? round($hours / $planned * 100, 2) : null, 'absence_hours' => round($hours, 2), 'planned_hours' => round($planned, 2)];
    }

    /**
     * @return array<string, mixed>
     */
    private function absenteeismPayload(CockpitFilter $filter): array
    {
        $series = [];

        for ($month = $filter->from->startOfMonth(); $month <= $filter->to; $month = $month->addMonth()) {
            $start = $month < $filter->from ? $filter->from : $month;
            $end = $month->endOfMonth() > $filter->to ? $filter->to : $month->endOfMonth();
            $series[] = ['period' => $month->format('Y-m'), 'rate' => $this->absenteeism($filter, $start, $end)['rate']];
        }

        return ['unit' => 'percent', ...$this->absenteeism($filter), 'series' => $series, 'heatmap' => $this->absenceHeatmap($filter)];
    }

    /**
     * Approved sick and accident hours by department and month.
     *
     * @return list<array{department_id: string|null, period: string, hours: float}>
     */
    private function absenceHeatmap(CockpitFilter $filter): array
    {
        $rows = DB::table('hr_leave_requests')
            ->join('hr_leave_types', 'hr_leave_requests.leave_type_id', '=', 'hr_leave_types.id')
            ->join('hr_employees', 'hr_leave_requests.employee_id', '=', 'hr_employees.id')
            ->where('hr_leave_requests.tenant_id', $filter->tenantId)->whereNull('hr_leave_requests.deleted_at')
            ->where('hr_leave_requests.status', 'approved')
            ->whereIn('hr_leave_types.category', ['sick', 'accident'])
            ->whereDate('hr_leave_requests.start_date', '>=', $filter->from->toDateString())->whereDate('hr_leave_requests.start_date', '<=', $filter->to->toDateString())
            ->when($filter->companyIds !== [], static fn (Builder $query) => $query->whereIn('hr_leave_requests.company_id', $filter->companyIds))
            ->get(['hr_employees.department_id', 'hr_leave_requests.start_date', 'hr_leave_requests.total_hours']);

        $cells = [];

        foreach ($rows as $row) {
            $key = ($row->department_id ?? 'none').'|'.CarbonImmutable::parse($row->start_date)->format('Y-m');
            $cells[$key]['hours'] = ($cells[$key]['hours'] ?? 0.0) + (float) $row->total_hours;
            $cells[$key]['department_id'] = $row->department_id !== null ? (string) $row->department_id : null;
            $cells[$key]['period'] = CarbonImmutable::parse($row->start_date)->format('Y-m');
        }

        ksort($cells);

        return array_values(array_map(static fn (array $cell): array => ['department_id' => $cell['department_id'], 'period' => $cell['period'], 'hours' => round($cell['hours'], 2)], $cells));
    }

    private function timesheets(CockpitFilter $filter): Builder
    {
        return DB::table('hr_timesheets')
            ->where('hr_timesheets.tenant_id', $filter->tenantId)->whereNull('hr_timesheets.deleted_at')
            ->where('hr_timesheets.status', 'approved')
            ->whereDate('hr_timesheets.work_date', '>=', $filter->from->toDateString())->whereDate('hr_timesheets.work_date', '<=', $filter->to->toDateString())
            ->when($filter->companyIds !== [], static fn (Builder $query) => $query->whereIn('hr_timesheets.company_id', $filter->companyIds))
            ->when($filter->costCenterIds !== [], static fn (Builder $query) => $query->whereIn('hr_timesheets.cost_center_id', $filter->costCenterIds));
    }

    /**
     * @return array{hours: float, cost: float}
     */
    private function overtime(CockpitFilter $filter): array
    {
        $rows = $this->timesheets($filter)->get(['overtime_hours', 'hourly_cost_rate']);

        return [
            'hours' => round((float) $rows->sum('overtime_hours'), 2),
            'cost' => round($rows->sum(static fn (object $row): float => (float) $row->overtime_hours * (float) $row->hourly_cost_rate), 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function overtimePayload(KpiContext $context): array
    {
        $filter = $context->filter;
        $canSeeCost = $context->access->canSeeCompensation();
        $months = [];

        foreach ($this->timesheets($filter)->get(['work_date', 'overtime_hours', 'hourly_cost_rate']) as $row) {
            $month = CarbonImmutable::parse($row->work_date)->format('Y-m');
            $months[$month]['hours'] = ($months[$month]['hours'] ?? 0.0) + (float) $row->overtime_hours;
            $months[$month]['cost'] = ($months[$month]['cost'] ?? 0.0) + (float) $row->overtime_hours * (float) $row->hourly_cost_rate;
        }

        ksort($months);
        $total = $this->overtime($filter);

        return [
            'unit' => 'hours',
            'masked' => ! $canSeeCost,
            'hours' => $total['hours'],
            'cost' => $canSeeCost ? $context->money($total['cost']) : null,
            'series' => array_map(static fn (string $month, array $row): array => [
                'period' => $month,
                'hours' => round($row['hours'], 2),
                'cost' => $canSeeCost ? $context->money($row['cost']) : null,
            ], array_keys($months), array_values($months)),
        ];
    }

    /**
     * Billable over total approved hours, with the utilization of each team.
     *
     * @return array{rate: float|null, billable_hours: float, total_hours: float, teams: list<array{team_id: string|null, rate: float|null, billable_hours: float, total_hours: float}>}
     */
    private function utilization(CockpitFilter $filter): array
    {
        $rows = $this->timesheets($filter)
            ->leftJoin('hr_employees', 'hr_timesheets.employee_id', '=', 'hr_employees.id')
            ->get(['hr_employees.team_id', 'hr_timesheets.billable_hours', 'hr_timesheets.total_hours']);

        $teams = [];

        foreach ($rows as $row) {
            $key = (string) ($row->team_id ?? 'none');
            $teams[$key]['team_id'] = $row->team_id !== null ? (string) $row->team_id : null;
            $teams[$key]['billable'] = ($teams[$key]['billable'] ?? 0.0) + (float) $row->billable_hours;
            $teams[$key]['total'] = ($teams[$key]['total'] ?? 0.0) + (float) $row->total_hours;
        }

        $billable = (float) $rows->sum('billable_hours');
        $total = (float) $rows->sum('total_hours');

        $list = array_values(array_map(static fn (array $team): array => [
            'team_id' => $team['team_id'],
            'rate' => $team['total'] > 0 ? round($team['billable'] / $team['total'] * 100, 2) : null,
            'billable_hours' => round($team['billable'], 2),
            'total_hours' => round($team['total'], 2),
        ], $teams));
        usort($list, static fn (array $a, array $b): int => ($b['rate'] ?? -1) <=> ($a['rate'] ?? -1));

        return ['rate' => $total > 0 ? round($billable / $total * 100, 2) : null, 'billable_hours' => round($billable, 2), 'total_hours' => round($total, 2), 'teams' => array_slice($list, 0, 10)];
    }
}
