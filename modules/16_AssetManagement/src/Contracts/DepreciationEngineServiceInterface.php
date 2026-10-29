<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Contracts;

use Modules\AssetManagement\DTOs\RunDepreciationDTO;
use Modules\AssetManagement\Models\DepreciationRun;
use Modules\AssetManagement\Models\DepreciationSchedule;
use Modules\AssetManagement\Models\FixedAsset;

interface DepreciationEngineServiceInterface
{
    public function calculateAssetDepreciationAmount(
        FixedAsset $asset,
        string $periodType = 'monthly',
        float $unitsProduced = 0.0
    ): float;

    public function executeDepreciationRun(RunDepreciationDTO $dto, ?string $actorUserId = null): DepreciationRun;

    public function calculateSingleAssetDepreciation(
        FixedAsset $asset,
        string $periodCode,
        string $periodType = 'monthly',
        string $ledgerType = 'statutory',
        float $unitsProduced = 0.0,
        ?string $runId = null,
        bool $autoPostToGl = true,
        ?string $actorUserId = null
    ): DepreciationSchedule;
}
