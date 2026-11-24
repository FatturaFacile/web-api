<?php

declare(strict_types=1);

namespace Modules\AssetManagement\DTOs;

/**
 * Allocation of an asset to a project with its utilization rates.
 */
final readonly class AllocateProjectAssetDTO
{
    public function __construct(
        public string $tenantId,
        public string $assetId,
        public string $projectId,
        public string $allocatedFrom,
        public ?string $allocatedTo = null,
        public string|int|float $hourlyRate = 0.0,
        public string|int|float $dailyRate = 0.0,
        public string|int|float $utilizedHours = 0.0,
        public string|int|float $utilizedDays = 0.0,
        public ?string $notes = null
    ) {}
}
