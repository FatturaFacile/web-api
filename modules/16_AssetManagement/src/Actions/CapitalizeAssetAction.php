<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Actions;

use Modules\AssetManagement\Contracts\AssetLifecycleServiceInterface;
use Modules\AssetManagement\DTOs\CapitalizeAssetDTO;
use Modules\AssetManagement\Models\FixedAsset;

final readonly class CapitalizeAssetAction
{
    public function __construct(
        private AssetLifecycleServiceInterface $lifecycleService
    ) {}

    public function execute(CapitalizeAssetDTO $dto, ?string $actorUserId = null): FixedAsset
    {
        return $this->lifecycleService->capitalizeAsset($dto, $actorUserId);
    }
}
