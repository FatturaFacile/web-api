<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Contracts;

use Modules\AssetManagement\DTOs\AllocateProjectAssetDTO;
use Modules\AssetManagement\Models\ProjectAssetAllocation;

interface ProjectAssetIntegrationServiceInterface
{
    public function allocateAssetToProject(AllocateProjectAssetDTO $dto, ?string $actorUserId = null): ProjectAssetAllocation;

    public function recordUtilization(ProjectAssetAllocation $allocation, float $additionalHours, float $additionalDays = 0.0, ?string $actorUserId = null): ProjectAssetAllocation;

    public function releaseProjectAssets(string $projectId, ?string $actorUserId = null): int;
}
