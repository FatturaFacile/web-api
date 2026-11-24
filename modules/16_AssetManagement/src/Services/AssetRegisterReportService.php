<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Services;

use App\Models\Contact;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\AssetManagement\Models\AssetBook;
use Modules\AssetManagement\Models\AssetDisposal;
use Modules\AssetManagement\Models\AssetImprovement;
use Modules\AssetManagement\Models\DepreciationSchedule;
use Modules\AssetManagement\Support\AssetLedger;
use Modules\AssetManagement\Support\Money;

/**
 * Asset register (libro cespiti) of a year and ledger: compliant with Art. 16 DPR 600/73 (IT) and Art. 958c CO (CH).
 * For every asset of the book: cost at start of year, initial additions, capital improvements, disposals, cost at year
 * end, accumulated depreciation at start, depreciation of the year, depreciation released by disposals, accumulated
 * depreciation, value adjustments and net book value at year end.
 */
final class AssetRegisterReportService
{
    /** Columns of the register, in export order. */
    public const array Columns = [
        'asset_number', 'name', 'category_code', 'acquisition_date', 'capitalization_date', 'disposal_date', 'depreciation_method',
        'rate_percent', 'cost_start', 'additions', 'improvements_year', 'disposals_cost', 'cost_end', 'accumulated_start',
        'depreciation_year', 'disposals_accumulated', 'accumulated_end', 'value_adjustments', 'net_book_value_end',
    ];

    /**
     * One page of the register.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function page(string $tenantId, int $year, string $ledgerType, int $perPage = 50): LengthAwarePaginator
    {
        $paginator = $this->query($tenantId, $year, AssetLedger::normalize($ledgerType))->gridPaginate($perPage);
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
        $totals = array_fill_keys(['cost_start', 'additions', 'improvements_year', 'disposals_cost', 'cost_end', 'accumulated_start', 'depreciation_year', 'disposals_accumulated', 'accumulated_end', 'value_adjustments', 'net_book_value_end'], '0.00');

        foreach ($this->all($tenantId, $year, $ledgerType) as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] = Money::round(Money::add($value, $row[$key] ?? '0'));
            }
        }

        return $totals;
    }

    /**
     * Totals grouped by asset category (required for Art. 16 DPR 600/73 & Art. 958c CO presentation).
     *
     * @return list<array<string, mixed>>
     */
    public function categorySubtotals(string $tenantId, int $year, string $ledgerType): array
    {
        $groups = [];
        $numericKeys = ['cost_start', 'additions', 'improvements_year', 'disposals_cost', 'cost_end', 'accumulated_start', 'depreciation_year', 'disposals_accumulated', 'accumulated_end', 'value_adjustments', 'net_book_value_end'];

        foreach ($this->all($tenantId, $year, $ledgerType) as $row) {
            $code = (string) ($row['category_code'] ?: 'UNCATEGORIZED');
            if (! isset($groups[$code])) {
                $groups[$code] = [
                    'category_code' => $code,
                    'category_name' => (string) ($row['category_name'] ?? $code),
                    'assets_count' => 0,
                    ...array_fill_keys($numericKeys, '0.00'),
                ];
            }

            $groups[$code]['assets_count']++;
            foreach ($numericKeys as $key) {
                $groups[$code][$key] = Money::round(Money::add($groups[$code][$key], $row[$key] ?? '0'));
            }
        }

        ksort($groups);

        return array_values($groups);
    }

    /**
     * Statutory header metadata for the Fixed Asset Register (Art. 16 DPR 600/73 & Art. 958c CO).
     *
     * @return array<string, mixed>
     */
    public function complianceHeader(string $tenantId, int $year, string $ledgerType, ?string $jurisdiction = null): array
    {
        $tenant = Tenant::find($tenantId);
        $country = strtoupper((string) ($jurisdiction ?: ($tenant !== null ? $tenant->country : null) ?: 'IT'));
        $isCh = $country === 'CH';

        return [
            'company_name' => $tenant !== null ? (string) ($tenant->name ?? '') : '',
            'legal_name' => $tenant !== null ? (string) ($tenant->legal_name ?: $tenant->name ?: '') : '',
            'tax_id' => $tenant !== null ? ($isCh ? (string) ($tenant->uid_number ?: $tenant->vat_number ?: '') : (string) ($tenant->vat_number ?: '')) : '',
            'fiscal_code' => $tenant !== null ? (string) ($tenant->fiscal_code ?: '') : '',
            'jurisdiction' => $isCh ? 'CH' : 'IT',
            'legal_reference' => $isCh ? 'Art. 958c / Art. 960a CO (OR) — Anlagebuchhaltung' : 'Art. 16 D.P.R. 600/1973 — Registro dei Beni Ammortizzabili',
            'currency' => $tenant !== null ? (string) ($tenant->default_currency ?: ($isCh ? 'CHF' : 'EUR')) : ($isCh ? 'CHF' : 'EUR'),
            'year' => $year,
            'ledger_type' => AssetLedger::normalize($ledgerType),
            'generated_at' => now()->toIso8601String(),
        ];
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

        $improvements = AssetImprovement::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('asset_id', $assetIds)
            ->where('status', 'completed')
            ->get()
            ->groupBy('asset_id');

        $supplierIds = $books->map(static fn ($book) => $book instanceof AssetBook ? $book->asset?->supplier_id : null)->filter()->unique()->values()->all();
        $suppliers = $supplierIds === []
            ? collect()
            : Contact::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('id', $supplierIds)->pluck('company_name', 'id');

        $incentives = new FiscalIncentiveCalculator;
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

            $improvementsInYear = '0';
            $improvementsAfterYear = '0';
            foreach ($improvements->get((string) $asset->id, collect()) as $improvement) {
                if ($ledgerType === AssetLedger::Tax && ! $improvement->affects_tax_book) {
                    continue;
                }
                $impYear = (int) $improvement->improvement_date?->format('Y');
                if ($impYear === $year) {
                    $improvementsInYear = Money::add($improvementsInYear, (string) $improvement->amount);
                } elseif ($impYear > $year) {
                    $improvementsAfterYear = Money::add($improvementsAfterYear, (string) $improvement->amount);
                }
            }

            $schedule = $depreciation->get((string) $asset->id);
            $yearDepreciation = Money::round($schedule->year_amount ?? '0');
            $accumulatedEnd = Money::round(Money::sub($schedule->to_date_amount ?? '0', $disposedUpToYear['accumulated']));
            $accumulatedStart = Money::round(Money::add(Money::sub($accumulatedEnd, $yearDepreciation), $disposedInYear['accumulated']));
            $costEnd = Money::round(Money::max('0', Money::sub(Money::add($book->cost, $disposedAfterYear['cost']), $improvementsAfterYear)));
            $improvementsYear = Money::round($improvementsInYear);
            $isCapitalizedThisYear = (int) $asset->capitalization_date?->format('Y') === $year;
            $additions = $isCapitalizedThisYear
                ? Money::round(Money::max('0', Money::sub(Money::add($costEnd, $disposedInYear['cost']), $improvementsYear)))
                : '0.00';
            $costStart = Money::round(Money::max('0', Money::sub(Money::sub(Money::add($costEnd, $disposedInYear['cost']), $additions), $improvementsYear)));
            $impairment = $ledgerType === AssetLedger::Statutory
                ? Money::round(Money::sub($asset->impairment_loss_total, $asset->revaluation_surplus_total))
                : Money::round($book->impairment_loss_total ?? '0');
            $nbvEnd = Money::round(Money::max('0', Money::sub(Money::sub($costEnd, $accumulatedEnd), $impairment)));
            $incentiveSummary = $incentives->computeSummary($asset);

            $rows[] = [
                'asset_id' => (string) $asset->id,
                'asset_number' => (string) $asset->asset_number,
                'name' => (string) $asset->name,
                'category_code' => (string) ($asset->category->code ?? ''),
                'category_name' => (string) ($asset->category->name ?? ''),
                'acquisition_year' => $asset->acquisition_date?->format('Y'),
                'acquisition_date' => $asset->acquisition_date?->format('Y-m-d'),
                'capitalization_date' => $asset->capitalization_date?->format('Y-m-d'),
                'in_service_date' => $asset->in_service_date?->format('Y-m-d'),
                'disposal_date' => $asset->disposal_date?->format('Y-m-d'),
                'supplier_name' => $asset->supplier_id !== null ? (string) ($suppliers->get((string) $asset->supplier_id) ?? '') : '',
                'purchase_invoice_number' => (string) ($asset->purchase_invoice_number ?? ''),
                'depreciation_method' => (string) $book->depreciation_method,
                'fiscal_regime' => (string) ($book->fiscal_regime ?? ($ledgerType === AssetLedger::Statutory ? 'civil_linear' : '')),
                'first_year_half_applied' => (bool) $book->first_year_half_rate && $isCapitalizedThisYear,
                'rate_percent' => Money::round($calculator->annualRatePercent($book), 4),
                'cost_start' => $costStart,
                'additions' => $additions,
                'improvements_year' => $improvementsYear,
                'disposals_cost' => Money::round($disposedInYear['cost']),
                'cost_end' => $costEnd,
                'accumulated_start' => $accumulatedStart,
                'opening_net_book_value' => Money::round(Money::max('0', Money::sub($costStart, $accumulatedStart))),
                'depreciation_year' => $yearDepreciation,
                'disposals_accumulated' => Money::round($disposedInYear['accumulated']),
                'accumulated_end' => $accumulatedEnd,
                'value_adjustments' => $impairment,
                'net_book_value_end' => $nbvEnd,
                'incentive_regime' => $incentiveSummary['regime'],
                'incentive_tax_credit_year' => $incentiveSummary['annual_installments'][$year] ?? '0.00',
            ];
        }

        return $rows;
    }
}
