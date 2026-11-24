<?php

declare(strict_types=1);

namespace Modules\AssetManagement\DTOs;

/**
 * Parameters of a depreciation run: the period (code and type), the ledger and, for units of production, the units
 * produced by each asset in the period.
 */
final readonly class RunDepreciationDTO
{
    /**
     * @param  array<string, string|int|float>  $unitsProducedByAssetId
     */
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $periodCode, // '2026-10' (monthly), '2026-Q4' (quarterly) or '2026' (yearly)
        public string $periodType = 'monthly',
        public string $ledgerType = 'statutory', // statutory (civil), tax (fiscal), ifrs, management
        public ?string $singleAssetId = null,
        public array $unitsProducedByAssetId = [],
        public bool $autoPostToGl = true
    ) {}
}
