<?php

declare(strict_types=1);

namespace Modules\AssetManagement\DTOs;

final readonly class AllocateProjectAssetDTO
{
    public function __construct(
        public string $tenantId,
        public string $assetId,
        public string $projectId,
        public string $allocatedFrom,
        public ?string $allocatedTo = null,
        public float $hourlyRate = 0.0,
        public float $dailyRate = 0.0,
        public float $utilizedHours = 0.0,
        public float $utilizedDays = 0.0,
        public ?string $notes = null
    ) {}
}
