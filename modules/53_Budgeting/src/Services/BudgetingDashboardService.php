<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Illuminate\Support\Carbon;
use Modules\Budgeting\Models\BudgetDriver;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * The controller's overview: versions per status, and for the selected version (the one asked for, else the latest
 * approved or locked budget, else the latest version) the budget against the actuals so far, the monthly series and the
 * largest adverse variances.
 */
final readonly class BudgetingDashboardService
{
    public function __construct(private VarianceService $variance) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(string $tenantId, ?string $versionId, ?int $throughPeriod): array
    {
        $versions = BudgetVersion::query()->where('tenant_id', $tenantId)->orderByDesc('fiscal_year')->orderByDesc('created_at')->get();

        $selected = $versionId !== null
            ? $versions->firstWhere('id', $versionId)
            : ($versions->first(static fn (BudgetVersion $version): bool => $version->version_type === BudgetVersion::Budget && in_array($version->status, [BudgetVersion::Approved, BudgetVersion::Locked], true))
                ?? $versions->first());

        $base = [
            'versions' => [
                'total' => $versions->count(),
                'by_status' => collect(BudgetVersion::Statuses)->mapWithKeys(static fn (string $status): array => [$status => $versions->where('status', $status)->count()])->all(),
                'by_type' => collect(BudgetVersion::Types)->mapWithKeys(static fn (string $type): array => [$type => $versions->where('version_type', $type)->count()])->all(),
            ],
            'pending_approval' => $versions->where('status', BudgetVersion::Submitted)->map(static fn (BudgetVersion $version): array => ['id' => $version->id, 'name' => $version->name, 'fiscal_year' => $version->fiscal_year, 'submitted_at' => $version->submitted_at?->toIso8601String()])->values()->all(),
            'selected_version' => null,
            'through_period' => 0,
            'totals' => null,
            'series' => [],
            'top_overruns' => [],
            'drivers_count' => 0,
        ];

        if ($selected === null) {
            return $base;
        }

        $through = $throughPeriod ?? BudgetPeriods::currentPeriod($selected, Carbon::today());
        $through = max(0, min(BudgetPeriods::Count, $through));
        $annual = (string) BudgetLine::query()->where('version_id', $selected->id)->sum('amount');
        $selectedInfo = ['id' => $selected->id, 'name' => $selected->name, 'fiscal_year' => $selected->fiscal_year, 'status' => $selected->status, 'version_type' => $selected->version_type, 'currency' => $selected->currency];
        $driversCount = BudgetDriver::query()->where('version_id', $selected->id)->count();

        if ($through === 0) {
            return [...$base, 'selected_version' => $selectedInfo, 'totals' => ['annual_budget' => bcadd($annual, '0', 2), 'budget' => '0.00', 'actual' => '0.00', 'variance' => '0.00', 'variance_percent' => null, 'consumption_percent' => '0.0'], 'drivers_count' => $driversCount];
        }

        $byPeriod = $this->variance->variance($selected, $through, 'period');
        $byAccount = $this->variance->variance($selected, $through, 'account');

        $cumulativeBudget = '0';
        $cumulativeActual = '0';
        $rows = collect($byPeriod['rows'])->keyBy('key');
        $series = [];

        foreach (range(1, BudgetPeriods::Count) as $period) {
            $row = $rows->get((string) $period);
            $budget = $row['budget'] ?? '0.00';
            $actual = $period <= $through ? ($row['actual'] ?? '0.00') : null;
            $cumulativeBudget = bcadd($cumulativeBudget, $budget, 2);
            $cumulativeActual = $actual === null ? null : bcadd($cumulativeActual ?? '0', $actual, 2);
            $series[] = ['period' => $period, 'month' => BudgetPeriods::label($selected, $period), 'budget' => $budget, 'actual' => $actual, 'cumulative_budget' => $cumulativeBudget, 'cumulative_actual' => $cumulativeActual];
        }

        $overruns = array_values(array_filter($byAccount['rows'], static fn (array $row): bool => ! $row['is_favorable']));
        usort($overruns, static fn (array $left, array $right): int => bccomp(ltrim($right['variance'], '-'), ltrim($left['variance'], '-'), 2));

        return [
            ...$base,
            'selected_version' => $selectedInfo,
            'through_period' => $through,
            'totals' => [
                'annual_budget' => bcadd($annual, '0', 2),
                'budget' => $byAccount['totals']['budget'],
                'actual' => $byAccount['totals']['actual'],
                'variance' => $byAccount['totals']['variance'],
                'variance_percent' => $byAccount['totals']['variance_percent'],
                'consumption_percent' => bccomp($annual, '0', 2) === 0 ? '0.0' : bcround(bcmul(bcdiv($byAccount['totals']['actual'], $annual, 8), '100', 8), 1),
            ],
            'series' => $series,
            'top_overruns' => array_slice($overruns, 0, 5),
            'drivers_count' => $driversCount,
        ];
    }
}
