<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Adapters;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\StatutoryFinancials\Contracts\FixedAssetRegisterPort;
use Modules\StatutoryFinancials\Support\Money;

/**
 * Fixed assets register of Mod 019 (asset management): cost, additions, disposals, revaluations, impairments, depreciation fund and
 * net book value per asset category. The depreciation of the year is the sum of the schedule lines of the year; the fund at the
 * start of the year is the closing fund minus that depreciation plus the fund released by the disposals.
 */
final class AssetManagementFixedAssetRegister implements FixedAssetRegisterPort
{
    public function movements(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasTable('eam_fixed_assets') || ! Schema::hasTable('eam_asset_categories')) {
            return ['available' => false, 'source' => 'Mod 019', 'rows' => []];
        }

        $assets = DB::table('eam_fixed_assets')
            ->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->whereNotIn('status', ['draft'])
            ->get(['id', 'category_id', 'acquisition_date', 'capitalization_date', 'disposal_date', 'acquisition_cost', 'capitalized_cost', 'accumulated_depreciation', 'impairment_loss_total', 'revaluation_surplus_total']);

        $categories = DB::table('eam_asset_categories')->where('tenant_id', $tenantId)->get(['id', 'name', 'asset_type'])->keyBy('id');
        $fromPeriod = $from->format('Y-m');
        $toPeriod = $to->format('Y-m');

        $schedules = Schema::hasTable('eam_depreciation_schedules')
            ? DB::table('eam_depreciation_schedules')->where('tenant_id', $tenantId)->whereNull('deleted_at')->get(['asset_id', 'period_code', 'depreciation_amount'])->groupBy('asset_id')
            : collect();

        $disposals = Schema::hasTable('eam_asset_disposals')
            ? DB::table('eam_asset_disposals')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereDate('disposal_date', '>=', $from->toDateString())->whereDate('disposal_date', '<=', $to->toDateString())->get(['asset_id', 'asset_cost_at_disposal', 'accumulated_depreciation_at_disposal'])->keyBy('asset_id')
            : collect();

        $rows = [];

        foreach ($assets as $asset) {
            $acquired = $asset->capitalization_date ?? $asset->acquisition_date;

            if ($acquired === null || CarbonImmutable::parse($acquired)->greaterThan($to)) {
                continue;
            }

            if ($asset->disposal_date !== null && CarbonImmutable::parse($asset->disposal_date)->lessThan($from)) {
                continue;
            }

            $category = $categories->get($asset->category_id);
            $key = (string) $asset->category_id;
            $rows[$key] ??= [
                'category_id' => $key, 'category' => $category->name ?? $key, 'asset_type' => $category->asset_type ?? 'tangible',
                'opening_cost' => '0', 'additions' => '0', 'disposals_cost' => '0', 'revaluations' => '0', 'impairments' => '0',
                'closing_cost' => '0', 'opening_accumulated' => '0', 'depreciation' => '0', 'disposals_accumulated' => '0', 'closing_accumulated' => '0', 'net_book_value' => '0',
            ];

            $cost = Money::from($asset->capitalized_cost ?? $asset->acquisition_cost);
            $assetSchedules = $schedules->get($asset->id, collect());
            $yearDepreciation = Money::sum($assetSchedules->filter(static fn ($line): bool => $line->period_code >= $fromPeriod && $line->period_code <= $toPeriod)->pluck('depreciation_amount')->map(static fn ($v): string => Money::from($v))->all());
            $afterDepreciation = Money::sum($assetSchedules->filter(static fn ($line): bool => $line->period_code > $toPeriod)->pluck('depreciation_amount')->map(static fn ($v): string => Money::from($v))->all());
            $disposal = $disposals->get($asset->id);

            if (CarbonImmutable::parse($acquired)->lessThan($from)) {
                $rows[$key]['opening_cost'] = Money::add($rows[$key]['opening_cost'], $cost);
            } else {
                $rows[$key]['additions'] = Money::add($rows[$key]['additions'], $cost);
            }

            if ($disposal !== null) {
                $rows[$key]['disposals_cost'] = Money::add($rows[$key]['disposals_cost'], Money::from($disposal->asset_cost_at_disposal));
                $rows[$key]['disposals_accumulated'] = Money::add($rows[$key]['disposals_accumulated'], Money::from($disposal->accumulated_depreciation_at_disposal));
                $rows[$key]['depreciation'] = Money::add($rows[$key]['depreciation'], $yearDepreciation);

                continue;
            }

            $closingAccumulated = Money::sub(Money::from($asset->accumulated_depreciation), $afterDepreciation);
            $rows[$key]['closing_accumulated'] = Money::add($rows[$key]['closing_accumulated'], $closingAccumulated);
            $rows[$key]['depreciation'] = Money::add($rows[$key]['depreciation'], $yearDepreciation);
            $rows[$key]['revaluations'] = Money::add($rows[$key]['revaluations'], Money::from($asset->revaluation_surplus_total));
            $rows[$key]['impairments'] = Money::add($rows[$key]['impairments'], Money::from($asset->impairment_loss_total));
        }

        foreach ($rows as $key => $row) {
            $closingCost = Money::sub(Money::add($row['opening_cost'], $row['additions']), $row['disposals_cost']);
            $rows[$key]['closing_cost'] = $closingCost;
            $rows[$key]['opening_accumulated'] = Money::add(Money::sub($row['closing_accumulated'], $row['depreciation']), $row['disposals_accumulated']);
            $rows[$key]['net_book_value'] = Money::sub(Money::add($closingCost, $row['revaluations']), Money::add($row['closing_accumulated'], $row['impairments']));
        }

        return ['available' => true, 'source' => 'Mod 019', 'rows' => array_values($rows)];
    }
}
