<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Contracts;

use Modules\AssetManagement\DTOs\RunDepreciationDTO;
use Modules\AssetManagement\Models\DepreciationRun;
use Modules\AssetManagement\Models\DepreciationSchedule;
use Modules\AssetManagement\Models\FixedAsset;

/**
 * Depreciation of the asset books: period runs (manual by design), single-asset charges, previews and simulations.
 */
interface DepreciationEngineServiceInterface
{
    /**
     * Full-period charge of the statutory book for a period type, without pro-rata (decimal string).
     */
    public function calculateAssetDepreciationAmount(FixedAsset $asset, string $periodType = 'monthly', string|int|float $unitsProduced = 0): string;

    /**
     * Depreciates every depreciable asset of the tenant for a period and ledger, once per period.
     */
    public function executeDepreciationRun(RunDepreciationDTO $dto, ?string $actorUserId = null): DepreciationRun;

    /**
     * Depreciates one asset for a period and ledger and posts it when the ledger posts to the General Ledger.
     */
    public function calculateSingleAssetDepreciation(
        FixedAsset $asset,
        string $periodCode,
        string $periodType = 'monthly',
        string $ledgerType = 'statutory',
        string|int|float $unitsProduced = 0,
        ?string $runId = null,
        bool $autoPostToGl = true,
        ?string $actorUserId = null
    ): DepreciationSchedule;

    /**
     * Projected depreciation schedule of an asset book from the next period on (nothing persisted).
     *
     * @return list<array{period_code: string, opening_book_value: string, depreciation_amount: string, closing_book_value: string, accumulated_depreciation: string}>
     */
    public function previewSchedule(FixedAsset $asset, string $ledgerType = 'statutory', string $periodType = 'yearly', int $maxPeriods = 60, string|int|float $unitsPerPeriod = 0): array;

    /**
     * What a run would charge, asset by asset, without writing anything.
     *
     * @return array{period_code: string, period_type: string, ledger_type: string, assets_count: int, total_depreciation_amount: string, lines: list<array<string, mixed>>}
     */
    public function simulateRun(RunDepreciationDTO $dto, ?string $categoryId = null): array;
}
