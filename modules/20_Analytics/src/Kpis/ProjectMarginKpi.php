<?php

declare(strict_types=1);

namespace Modules\Analytics\Kpis;

use Illuminate\Support\Collection;
use Modules\Analytics\Contracts\KpiCalculator;
use Modules\Analytics\Support\ConvertedAmountSql;
use Modules\Analytics\Support\ReportPeriod;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\ProjectProfitabilityService;

/**
 * Gross margin of the running and completed projects, taken from the profitability report of the Projects module (the
 * same figures the project page shows). A project with neither revenue nor cost has no margin to report and is left
 * out; a project in a currency other than the accounting one is counted apart, never added. Snapshot of today.
 */
final readonly class ProjectMarginKpi implements KpiCalculator
{
    /**
     * Projects read per request: the profitability report is computed on demand for each of them.
     */
    private const int MaxProjects = 100;

    /**
     * @var list<string>
     */
    private const array Statuses = ['active', 'on_hold', 'completed', 'closed'];

    /**
     * Inject the collaborators.
     */
    public function __construct(private ProjectProfitabilityService $profitability, private ConvertedAmountSql $sql) {}

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'project-margin';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return 'Project margin';
    }

    /**
     * {@inheritDoc}
     */
    public function unit(): string
    {
        return 'currency';
    }

    /**
     * {@inheritDoc}
     */
    public function kind(): string
    {
        return 'snapshot';
    }

    /**
     * {@inheritDoc}
     */
    public function requiredModule(): string
    {
        return 'projects';
    }

    /**
     * {@inheritDoc}
     */
    public function calculate(ReportPeriod $period): array
    {
        $currency = $this->sql->currency();
        $reports = $this->reports(self::MaxProjects);
        $counted = $reports->filter(static fn (array $report): bool => $report['currency'] === $currency);
        $revenue = $counted->reduce(static fn (string $sum, array $report): string => bcadd($sum, $report['revenue'], 2), '0');
        $margin = $counted->reduce(static fn (string $sum, array $report): string => bcadd($sum, $report['gross_margin'], 2), '0');

        return [
            'value' => $counted->isEmpty() ? null : $margin,
            'details' => [
                'currency' => $currency,
                'project_count' => $counted->count(),
                'revenue' => $counted->isEmpty() ? null : $revenue,
                'margin_percent' => $counted->isEmpty() || bccomp($revenue, '0', 2) === 0 ? null : bcround(bcdiv(bcmul($margin, '100', 4), $revenue, 4), 2),
                'excluded_other_currency' => $reports->count() - $counted->count(),
                'loss_making_projects' => $counted->filter(static fn (array $report): bool => bccomp($report['gross_margin'], '0', 2) < 0)->count(),
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function rows(ReportPeriod $period, int $limit): array
    {
        return $this->reports($limit)->values()->all();
    }

    /**
     * Profitability of the projects that have something to report.
     *
     * @return Collection<int, array{project_number: string, name: string, currency: string, revenue: string, cost: string, gross_margin: string, margin_percent: ?string}>
     */
    private function reports(int $limit): Collection
    {
        return Project::query()
            ->whereIn('status', self::Statuses)
            ->orderBy('project_number')
            ->limit($limit)
            ->get()
            ->map(function (Project $project): array {
                $report = $this->profitability->report($project);

                return [
                    'project_number' => (string) $project->project_number,
                    'name' => (string) $project->name,
                    'currency' => strtoupper((string) $report['currency']),
                    'revenue' => (string) $report['revenue']['recognised'],
                    'cost' => (string) $report['cost']['actual'],
                    'gross_margin' => (string) $report['margin']['gross_margin'],
                    'margin_percent' => $report['margin']['gross_margin_percentage'] === null ? null : (string) $report['margin']['gross_margin_percentage'],
                ];
            })
            ->filter(static fn (array $row): bool => bccomp($row['revenue'], '0', 2) !== 0 || bccomp($row['cost'], '0', 2) !== 0)
            ->values();
    }
}
