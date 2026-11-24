<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Contracts;

use Modules\AssetManagement\DTOs\AllocateProjectAssetDTO;
use Modules\AssetManagement\Models\ProjectAssetAllocation;

/**
 * Allocation of assets to projects with utilization rates and attributed cost (decimal strings).
 */
interface ProjectAssetIntegrationServiceInterface
{
    public function allocateAssetToProject(AllocateProjectAssetDTO $dto, ?string $actorUserId = null): ProjectAssetAllocation;

    public function recordUtilization(ProjectAssetAllocation $allocation, string|int|float $additionalHours, string|int|float $additionalDays = 0, ?string $actorUserId = null): ProjectAssetAllocation;

    public function releaseProjectAssets(string $projectId, ?string $actorUserId = null): int;
}
