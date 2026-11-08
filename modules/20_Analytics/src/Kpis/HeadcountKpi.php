<?php

declare(strict_types=1);

namespace Modules\Analytics\Kpis;

use Illuminate\Database\Query\Builder;
use Modules\Analytics\Contracts\KpiCalculator;
use Modules\Analytics\Support\ReportPeriod;
use Modules\HumanResources\Models\Employee;

/**
 * Headcount at the end of the period, rebuilt from hire and termination dates so it can be compared with an earlier
 * date. Candidates (not hired yet) never count. Aggregates only: no personal data leaves this indicator, and the caller
 * needs read access to the HR module.
 */
final readonly class HeadcountKpi implements KpiCalculator
{
    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'hr-headcount';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return 'Headcount';
    }

    /**
     * {@inheritDoc}
     */
    public function unit(): string
    {
        return 'count';
    }

    /**
     * {@inheritDoc}
     */
    public function kind(): string
    {
        return 'period';
    }

    /**
     * {@inheritDoc}
     */
    public function requiredModule(): string
    {
        return 'hr';
    }

    /**
     * {@inheritDoc}
     */
    public function calculate(ReportPeriod $period): array
    {
        $totals = $this->employed($period)
            ->selectRaw('COUNT(*) AS headcount')
            ->selectRaw('COALESCE(SUM(hr_employees.fte_ratio), 0) AS fte')
            ->first();

        $headcount = (int) $totals->headcount;
        $hires = $this->base()->whereBetween('hr_employees.hire_date', [$period->from->toDateString(), $period->to->toDateString()])->count();
        $leavers = $this->base()->whereBetween('hr_employees.termination_date', [$period->from->toDateString(), $period->to->toDateString()])->count();

        return [
            'value' => $headcount === 0 ? null : (string) $headcount,
            'details' => [
                'as_of' => $period->to->toDateString(),
                'full_time_equivalent' => $headcount === 0 ? null : bcadd((string) $totals->fte, '0', 2),
                'hires_in_period' => $hires,
                'terminations_in_period' => $leavers,
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function rows(ReportPeriod $period, int $limit): array
    {
        return $this->employed($period)
            ->leftJoin('hr_departments', 'hr_departments.id', '=', 'hr_employees.department_id')
            ->groupBy('hr_departments.id', 'hr_departments.name')
            ->orderByRaw('COUNT(*) DESC')
            ->limit($limit)
            ->selectRaw("COALESCE(hr_departments.name, '-') AS department, COUNT(*) AS headcount, COALESCE(SUM(hr_employees.fte_ratio), 0) AS fte")
            ->get()
            ->map(static fn (object $row): array => ['department' => (string) $row->department, 'headcount' => (int) $row->headcount, 'full_time_equivalent' => bcadd((string) $row->fte, '0', 2)])
            ->all();
    }

    private function base(): Builder
    {
        return Employee::query()->toBase()->where('hr_employees.employment_status', '!=', 'candidate');
    }

    private function employed(ReportPeriod $period): Builder
    {
        $asOf = $period->to->toDateString();

        return $this->base()
            ->where('hr_employees.hire_date', '<=', $asOf)
            ->where(static fn (Builder $query): Builder => $query->whereNull('hr_employees.termination_date')->orWhere('hr_employees.termination_date', '>', $asOf));
    }
}
