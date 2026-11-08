<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\AssetManagement\Models\AssetBook;
use Modules\AssetManagement\Models\AssetDisposal;
use Modules\AssetManagement\Models\DepreciationSchedule;
use Modules\AssetManagement\Support\AssetLedger;
use Modules\AssetManagement\Support\Money;

/**
 * Asset register (libro cespiti) of a year and ledger: for every asset of the book, the cost at the start of the year,
 * additions, disposals, cost at year end, accumulated depreciation at start, depreciation of the year, depreciation
 * released by disposals, accumulated depreciation and net book value at year end.
 *
 * Costs are the cost basis of the book (capitalized cost net of disposed shares); improvements are shown in the cost
 * of the year in which they are read, not split by date. Impairments net of revaluation surpluses are the value_adjustments column of the statutory book.
 */
final class AssetRegisterReportService
{
    /** Columns of the register, in export order. */
    public const array Columns = [
        'asset_number', 'name', 'category_code', 'acquisition_date', 'capitalization_date', 'disposal_date', 'depreciation_method',
        'rate_percent', 'cost_start', 'additions', 'disposals_cost', 'cost_end', 'accumulated_start', 'depreciation_year',
        'disposals_accumulated', 'accumulated_end', 'value_adjustments', 'net_book_value_end',
    ];

    /**
     * One page of the register.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function page(string $tenantId, int $year, string $ledgerType, int $perPage = 50): LengthAwarePaginator
    {
        $paginator = $this->query($tenantId, $year, AssetLedger::normalize($ledgerType))->paginate($perPage);
        $rows = $this->rows($tenantId, $year, AssetLedger::normalize($ledgerType), collect($paginator->items()));

        return new \Illuminate\Pagination\LengthAwarePaginator($rows, $paginator->total(), $paginator->perPage(), $paginator->currentPage());
    }

    /**
     * Every row of the register, chunk by chunk, for the export.
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function all(string $tenantId, int $year, string $ledgerType): iterable
    {
        $ledgerType = AssetLedger::normalize($ledgerType);

        foreach ($this->query($tenantId, $year, $ledgerType)->lazyById(200, 'eam_asset_books.id', 'id')->chunk(200) as $chunk) {
            yield from $this->rows($tenantId, $year, $ledgerType, $chunk->collect()->values());
        }
    }

    /**
     * Totals of the register columns.
     *
     * @return array<string, string>
     */
    public function totals(string $tenantId, int $year, string $ledgerType): array
    {
        $totals = array_fill_keys(['cost_start', 'additions', 'disposals_cost', 'cost_end', 'accumulated_start', 'depreciation_year', 'disposals_accumulated', 'accumulated_end', 'value_adjustments', 'net_book_value_end'], '0.00');

        foreach ($this->all($tenantId, $year, $ledgerType) as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] = Money::round(Money::add($value, $row[$key]));
            }
        }

        return $totals;
    }

    /**
     * Books of the ledger whose asset was capitalized by the end of the year and not disposed before it started.
     *
     * @return Builder<AssetBook>
     */
    private function query(string $tenantId, int $year, string $ledgerType): Builder
    {
        $yearStart = CarbonImmutable::create($year, 1, 1)->toDateString();
        $yearEnd = CarbonImmutable::create($year, 12, 31)->toDateString();

        return AssetBook::query()
            ->select('eam_asset_books.*')
            ->join('eam_fixed_assets', 'eam_fixed_assets.id', '=', 'eam_asset_books.asset_id')
            ->where('eam_asset_books.tenant_id', $tenantId)
            ->where('eam_asset_books.ledger_type', $ledgerType)
            ->whereNull('eam_fixed_assets.deleted_at')
            ->whereNotNull('eam_fixed_assets.capitalization_date')
            ->whereDate('eam_fixed_assets.capitalization_date', '<=', $yearEnd)
            ->where(fn (Builder $query) => $query->whereNull('eam_fixed_assets.disposal_date')->orWhereDate('eam_fixed_assets.disposal_date', '>=', $yearStart))
            ->orderBy('eam_fixed_assets.asset_number')
            ->with('asset.category');
    }

    /**
     * Register rows of a set of books.
     *
     * @param  Collection<int, mixed>  $books
     * @return list<array<string, mixed>>
     */
    private function rows(string $tenantId, int $year, string $ledgerType, Collection $books): array
    {
        $assetIds = $books->pluck('asset_id')->map(static fn ($id): string => (string) $id)->all();
        if ($assetIds === []) {
            return [];
        }

        $depreciation = DepreciationSchedule::query()
            ->where('tenant_id', $tenantId)
            ->where('ledger_type', $ledgerType)
            ->whereIn('asset_id', $assetIds)
            ->selectRaw('asset_id, SUM(CASE WHEN substr(period_code, 1, 4) = ? THEN depreciation_amount ELSE 0 END) AS year_amount, SUM(CASE WHEN substr(period_code, 1, 4) <= ? THEN depreciation_amount ELSE 0 END) AS to_date_amount', [(string) $year, (string) $year])
            ->groupBy('asset_id')
            ->get()
            ->keyBy('asset_id');

        $disposals = AssetDisposal::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('asset_id', $assetIds)
            ->where('status', 'completed')
            ->get()
            ->groupBy('asset_id');

        $calculator = new DepreciationCalculator;
        $rows = [];

        foreach ($books as $book) {
            if (! $book instanceof AssetBook) {
                continue;
            }

            $asset = $book->asset;
            if ($asset === null) {
                continue;
            }

            $disposedInYear = ['cost' => '0', 'accumulated' => '0'];
            $disposedUpToYear = ['cost' => '0', 'accumulated' => '0'];
            $disposedAfterYear = ['cost' => '0', 'accumulated' => '0'];

            foreach ($disposals->get((string) $asset->id, collect()) as $disposal) {
                $share = $disposal->metadata['book_shares'][$ledgerType] ?? null;
                $cost = is_array($share) ? (string) $share['cost'] : ($ledgerType === AssetLedger::Statutory ? (string) $disposal->asset_cost_at_disposal : '0');
                $accumulated = is_array($share) ? (string) $share['accumulated_depreciation'] : ($ledgerType === AssetLedger::Statutory ? (string) $disposal->accumulated_depreciation_at_disposal : '0');
                $disposalYear = (int) $disposal->disposal_date?->format('Y');

                $bucket = match (true) {
                    $disposalYear === $year => 'in',
                    $disposalYear > $year => 'after',
                    default => 'before',
                };

                if ($bucket === 'in') {
                    $disposedInYear = ['cost' => Money::add($disposedInYear['cost'], $cost), 'accumulated' => Money::add($disposedInYear['accumulated'], $accumulated)];
                }
                if ($bucket !== 'after') {
                    $disposedUpToYear['accumulated'] = Money::add($disposedUpToYear['accumulated'], $accumulated);
                }
                if ($bucket === 'after') {
                    $disposedAfterYear = ['cost' => Money::add($disposedAfterYear['cost'], $cost), 'accumulated' => Money::add($disposedAfterYear['accumulated'], $accumulated)];
                }
            }

            $schedule = $depreciation->get((string) $asset->id);
            $yearDepreciation = Money::round($schedule->year_amount ?? '0');
            $accumulatedEnd = Money::round(Money::sub($schedule->to_date_amount ?? '0', $disposedUpToYear['accumulated']));
            $accumulatedStart = Money::round(Money::add(Money::sub($accumulatedEnd, $yearDepreciation), $disposedInYear['accumulated']));
            $costEnd = Money::round(Money::add($book->cost, $disposedAfterYear['cost']));
            $additions = (int) $asset->capitalization_date?->format('Y') === $year ? Money::round(Money::add($costEnd, $disposedInYear['cost'])) : '0.00';
            $costStart = Money::round(Money::sub(Money::add($costEnd, $disposedInYear['cost']), $additions));
            $impairment = $ledgerType === AssetLedger::Statutory ? Money::round(Money::sub($asset->impairment_loss_total, $asset->revaluation_surplus_total)) : '0.00';

            $rows[] = [
                'asset_id' => (string) $asset->id,
                'asset_number' => (string) $asset->asset_number,
                'name' => (string) $asset->name,
                'category_code' => (string) ($asset->category->code ?? ''),
                'acquisition_date' => $asset->acquisition_date?->format('Y-m-d'),
                'capitalization_date' => $asset->capitalization_date?->format('Y-m-d'),
                'disposal_date' => $asset->disposal_date?->format('Y-m-d'),
                'depreciation_method' => (string) $book->depreciation_method,
                'rate_percent' => Money::round($calculator->annualRatePercent($book), 4),
                'cost_start' => $costStart,
                'additions' => $additions,
                'disposals_cost' => Money::round($disposedInYear['cost']),
                'cost_end' => $costEnd,
                'accumulated_start' => $accumulatedStart,
                'depreciation_year' => $yearDepreciation,
                'disposals_accumulated' => Money::round($disposedInYear['accumulated']),
                'accumulated_end' => $accumulatedEnd,
                'value_adjustments' => $impairment,
                'net_book_value_end' => Money::round(Money::max('0', Money::sub(Money::sub($costEnd, $accumulatedEnd), $impairment))),
            ];
        }

        return $rows;
    }
}
