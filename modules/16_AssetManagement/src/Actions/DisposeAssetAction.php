<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Actions;

use Modules\AssetManagement\Contracts\AssetLifecycleServiceInterface;
use Modules\AssetManagement\DTOs\DisposeAssetDTO;
use Modules\AssetManagement\Models\AssetDisposal;

/**
 * Single-purpose action (dispose asset) delegating to the asset services.
 */
final readonly class DisposeAssetAction
{
    public function __construct(
        private AssetLifecycleServiceInterface $lifecycleService
    ) {}

    public function execute(DisposeAssetDTO $dto, ?string $actorUserId = null): AssetDisposal
    {
        return $this->lifecycleService->disposeAsset($dto, $actorUserId);
    }
}
