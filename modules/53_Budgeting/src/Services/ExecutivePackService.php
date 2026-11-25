<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Illuminate\Support\Carbon;
use Modules\Budgeting\Contracts\LedgerLookup;
use Modules\Budgeting\Models\BudgetCommitment;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Models\BudgetOverride;
use Modules\Budgeting\Models\BudgetSpendRule;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * The executive budget pack for the board: one workbook with the summary of the version, the multidimensional
 * breakdown, the versions of the fiscal year (original, revisions, scenarios, rolling forecast), the rolling forecast
 * by period, the variance analysis by account and by cost centre with the volume and price effects, and the spend
 * control (rules, open commitments and overrides). Sheet titles and headers are translated.
 */
final readonly class ExecutivePackService
{
    public function __construct(
        private GridService $grid,
        private VarianceService $variance,
        private VolumePriceVarianceService $volumePrice,
        private AvailabilityService $availability,
        private LedgerLookup $ledger,
        private WorkbookWriter $writer,
    ) {}

    /**
     * @return string path of the temporary workbook, to be deleted by the caller
     */
    public function build(BudgetVersion $version, int $throughPeriod): string
    {
        $throughPeriod = max(1, min(BudgetPeriods::Count, $throughPeriod));
        $byAccount = $this->variance->variance($version, $throughPeriod, 'account');
        $byCostCenter = $this->variance->variance($version, $throughPeriod, 'cost_center');

        return $this->writer->write([
            $this->summary($version, $throughPeriod, $byAccount),
            $this->breakdown($version),
            $this->versions($version),
            $this->rolling($version),
            $this->varianceSheet('variance_account', $byAccount),
            $this->varianceSheet('variance_cost_center', $byCostCenter),
            $this->volumePriceSheet($version, $throughPeriod),
            $this->rules($version),
            $this->commitments($version),
            $this->overrides($version),
        ]);
    }

    /**
     * @param  array<string, mixed>  $variance
     * @return array{name: string, headers: list<string>, rows: list<list<scalar|null>>}
     */
    private function summary(BudgetVersion $version, int $throughPeriod, array $variance): array
    {
        $totals = $variance['totals'];
        $availability = $this->availability->forScope($version->tenant_id, Carbon::parse($version->year_start), null, null, null);
        $t = static fn (string $key): string => __('budgeting_pack.'.$key);

        return [
            'name' => $t('sheet.summary'),
            'headers' => [$t('field.item'), $t('field.value')],
            'rows' => [
                [$t('field.version'), $version->name],
                [$t('field.fiscal_year'), $version->fiscal_year],
                [$t('field.type'), __('budgeting_pack.type.'.$version->version_type)],
                [$t('field.status'), __('budgeting_pack.status.'.$version->status)],
                [$t('field.currency'), $version->currency],
                [$t('field.approved_at'), $version->approved_at?->toDateString() ?? ''],
                [$t('field.through_period'), BudgetPeriods::label($version, $throughPeriod)],
                [$t('field.budget'), (float) $totals['budget']],
                [$t('field.actual'), (float) $totals['actual']],
                [$t('field.variance'), (float) $totals['variance']],
                [$t('field.variance_percent'), $totals['variance_percent'] === null ? '' : (float) $totals['variance_percent']],
                [$t('field.committed'), (float) $availability->committed],
                [$t('field.available'), (float) $availability->available()],
            ],
        ];
    }

    /**
     * @return array{name: string, headers: list<string>, rows: list<list<scalar|null>>}
     */
    private function breakdown(BudgetVersion $version): array
    {
        $grid = $this->grid->grid($version);
        $labels = array_map(static fn (array $slot): string => $slot['label'], $grid['slots']);
        $rows = [];

        foreach ($grid['rows'] as $row) {
            $cells = (array) $row['cells'];
            $values = [$row['account_code'], $row['account_name'], $row['cost_center_code'], $row['project_code']];

            foreach ($grid['slots'] as $slot) {
                $values[] = (float) ($cells[$slot['slot']]['amount'] ?? '0');
            }

            $values[] = (float) $row['total'];
            $rows[] = $values;
        }

        return [
            'name' => __('budgeting_pack.sheet.breakdown'),
            'headers' => [__('budgeting_pack.field.account'), __('budgeting_pack.field.account_name'), __('budgeting_pack.field.cost_center'), __('budgeting_pack.field.project'), ...$labels, __('budgeting_pack.field.total')],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{name: string, headers: list<string>, rows: list<list<scalar|null>>}
     */
    private function versions(BudgetVersion $version): array
    {
        $rows = [];

        foreach (BudgetVersion::query()->withSum('lines', 'amount')->where('tenant_id', $version->tenant_id)->where('fiscal_year', $version->fiscal_year)->orderBy('created_at')->get() as $item) {
            $rows[] = [
                $item->name,
                __('budgeting_pack.type.'.$item->version_type),
                $item->scenario_kind === null ? '' : __('budgeting_pack.scenario.'.$item->scenario_kind),
                $item->revision_number,
                __('budgeting_pack.status.'.$item->status),
                (float) ($item->lines_sum_amount ?? 0),
                $item->approved_at?->toDateString() ?? '',
                $item->revision_reason ?? '',
            ];
        }

        return [
            'name' => __('budgeting_pack.sheet.versions'),
            'headers' => array_map(static fn (string $key): string => __('budgeting_pack.field.'.$key), ['version', 'type', 'scenario', 'revision', 'status', 'total', 'approved_at', 'reason']),
            'rows' => $rows,
        ];
    }

    /**
     * @return array{name: string, headers: list<string>, rows: list<list<scalar|null>>}
     */
    private function rolling(BudgetVersion $version): array
    {
        $rolling = $version->version_type === BudgetVersion::RollingForecast
            ? $version
            : BudgetVersion::query()->where('tenant_id', $version->tenant_id)->where('fiscal_year', $version->fiscal_year)->where('version_type', BudgetVersion::RollingForecast)->latest()->first();
        $rows = [];

        if ($rolling !== null) {
            $lines = BudgetLine::query()->where('version_id', $rolling->id)->get()->groupBy('period');

            foreach (range(1, BudgetPeriods::Count) as $period) {
                $actual = '0.00';
                $forecast = '0.00';

                foreach ($lines->get($period, collect()) as $line) {
                    if ($line->source === BudgetLine::Actual) {
                        $actual = bcadd($actual, (string) $line->amount, 2);
                    } else {
                        $forecast = bcadd($forecast, (string) $line->amount, 2);
                    }
                }

                $rows[] = [BudgetPeriods::label($rolling, $period), $rolling->name, (float) $actual, (float) $forecast, (float) bcadd($actual, $forecast, 2)];
            }
        }

        return [
            'name' => __('budgeting_pack.sheet.rolling'),
            'headers' => array_map(static fn (string $key): string => __('budgeting_pack.field.'.$key), ['period', 'version', 'actual', 'forecast', 'total']),
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $variance
     * @return array{name: string, headers: list<string>, rows: list<list<scalar|null>>}
     */
    private function varianceSheet(string $sheet, array $variance): array
    {
        $rows = [];

        foreach ($variance['rows'] as $row) {
            $rows[] = [$row['code'], $row['label'], (float) $row['budget'], (float) $row['actual'], (float) $row['variance'], $row['variance_percent'] === null ? '' : (float) $row['variance_percent'], __('budgeting_pack.light.'.$row['traffic_light'])];
        }

        return [
            'name' => __('budgeting_pack.sheet.'.$sheet),
            'headers' => array_map(static fn (string $key): string => __('budgeting_pack.field.'.$key), ['code', 'name', 'budget', 'actual', 'variance', 'variance_percent', 'traffic_light']),
            'rows' => $rows,
        ];
    }

    /**
     * @return array{name: string, headers: list<string>, rows: list<list<scalar|null>>}
     */
    private function volumePriceSheet(BudgetVersion $version, int $throughPeriod): array
    {
        $rows = [];

        foreach ($this->volumePrice->decompose($version, $throughPeriod, 'account')['rows'] as $row) {
            $rows[] = [
                $row['code'], $row['label'], (float) $row['budget'], (float) $row['actual'], (float) $row['variance'],
                $row['volume_variance'] === null ? '' : (float) $row['volume_variance'],
                $row['price_variance'] === null ? '' : (float) $row['price_variance'],
                $row['decomposable'] ? __('budgeting_pack.yes') : __('budgeting_pack.no'),
            ];
        }

        return [
            'name' => __('budgeting_pack.sheet.volume_price'),
            'headers' => array_map(static fn (string $key): string => __('budgeting_pack.field.'.$key), ['code', 'name', 'budget', 'actual', 'variance', 'volume_variance', 'price_variance', 'decomposable']),
            'rows' => $rows,
        ];
    }

    /**
     * @return array{name: string, headers: list<string>, rows: list<list<scalar|null>>}
     */
    private function rules(BudgetVersion $version): array
    {
        $rows = [];
        $rules = BudgetSpendRule::query()->where('tenant_id', $version->tenant_id)->orderByDesc('priority')->get();
        [$accounts, $costCenters, $projects] = $this->dimensionNames($version->tenant_id, $rules->pluck('account_id')->filter()->all(), $rules->pluck('cost_center_id')->filter()->all(), $rules->pluck('project_id')->filter()->all());

        foreach ($rules as $rule) {
            $rows[] = [$rule->name, __('budgeting_pack.mode.'.$rule->mode), (float) $rule->threshold_percent, $accounts[$rule->account_id] ?? '', $costCenters[$rule->cost_center_id] ?? '', $projects[$rule->project_id] ?? '', implode(', ', $rule->stages), $rule->is_active ? __('budgeting_pack.yes') : __('budgeting_pack.no')];
        }

        return [
            'name' => __('budgeting_pack.sheet.rules'),
            'headers' => array_map(static fn (string $key): string => __('budgeting_pack.field.'.$key), ['name', 'mode', 'threshold', 'account', 'cost_center', 'project', 'stages', 'active']),
            'rows' => $rows,
        ];
    }

    /**
     * @return array{name: string, headers: list<string>, rows: list<list<scalar|null>>}
     */
    private function commitments(BudgetVersion $version): array
    {
        $rows = [];
        $items = BudgetCommitment::query()->where('tenant_id', $version->tenant_id)->where('fiscal_year', $version->fiscal_year)->where('status', BudgetCommitment::Open)->orderBy('commitment_date')->get();
        [, $costCenters, $projects] = $this->dimensionNames($version->tenant_id, [], $items->pluck('cost_center_id')->filter()->all(), $items->pluck('project_id')->filter()->all());

        foreach ($items as $item) {
            $rows[] = [__('budgeting_pack.source.'.$item->source_type), $item->source_number, $item->commitment_date->toDateString(), $costCenters[$item->cost_center_id] ?? '', $projects[$item->project_id] ?? '', (float) $item->amount, (float) $item->openAmount()];
        }

        return [
            'name' => __('budgeting_pack.sheet.commitments'),
            'headers' => array_map(static fn (string $key): string => __('budgeting_pack.field.'.$key), ['source', 'number', 'date', 'cost_center', 'project', 'amount', 'open_amount']),
            'rows' => $rows,
        ];
    }

    /**
     * @return array{name: string, headers: list<string>, rows: list<list<scalar|null>>}
     */
    private function overrides(BudgetVersion $version): array
    {
        $rows = [];

        foreach (BudgetOverride::query()->where('tenant_id', $version->tenant_id)->where('fiscal_year', $version->fiscal_year)->orderBy('created_at')->get() as $override) {
            $rows[] = [
                __('budgeting_pack.scope.'.$override->scope), $override->source_number ?? '', (float) $override->requested_amount, $override->approved_amount === null ? '' : (float) $override->approved_amount,
                __('budgeting_pack.override_status.'.$override->status), $override->reason, $override->decision_comment ?? '', $override->decided_at?->toDateString() ?? '', $override->valid_until?->toDateString() ?? '',
            ];
        }

        return [
            'name' => __('budgeting_pack.sheet.overrides'),
            'headers' => array_map(static fn (string $key): string => __('budgeting_pack.field.'.$key), ['scope', 'number', 'requested', 'approved', 'status', 'reason', 'decision', 'decided_at', 'valid_until']),
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<string>  $accountIds
     * @param  list<string>  $costCenterIds
     * @param  list<string>  $projectIds
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, string>}
     */
    private function dimensionNames(string $tenantId, array $accountIds, array $costCenterIds, array $projectIds): array
    {
        $format = static fn (array $items): array => array_map(static fn (array $item): string => trim($item['code'].' '.$item['name']), $items);

        return [
            $format($this->ledger->accounts($tenantId, array_values(array_unique($accountIds)))),
            $format($this->ledger->costCenters($tenantId, array_values(array_unique($costCenterIds)))),
            $format($this->ledger->projects($tenantId, array_values(array_unique($projectIds)))),
        ];
    }
}
