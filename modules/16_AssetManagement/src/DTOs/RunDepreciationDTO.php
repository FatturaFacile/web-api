<?php

declare(strict_types=1);

namespace Modules\AssetManagement\DTOs;

final readonly class RunDepreciationDTO
{
    /**
     * @param  array<string, float>  $unitsProducedByAssetId
     */
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $periodCode, // e.g., '2026-10' or '2026'
        public string $periodType = 'monthly', // 'monthly' or 'yearly'
        public string $ledgerType = 'statutory', // 'statutory', 'ifrs', 'tax', 'management'
        public ?string $singleAssetId = null,
        public array $unitsProducedByAssetId = [],
        public bool $autoPostToGl = true
    ) {}
}
