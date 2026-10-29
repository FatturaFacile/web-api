<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Actions;

use Modules\AssetManagement\Contracts\AssetLifecycleServiceInterface;
use Modules\AssetManagement\DTOs\RegisterAssetDTO;
use Modules\AssetManagement\Models\FixedAsset;

final readonly class RegisterAssetAction
{
    public function __construct(
        private AssetLifecycleServiceInterface $lifecycleService
    ) {}

    public function execute(RegisterAssetDTO $dto, ?string $actorUserId = null): FixedAsset
    {
        return $this->lifecycleService->registerAsset($dto, $actorUserId);
    }
}
