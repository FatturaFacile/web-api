<?php

declare(strict_types=1);

namespace Modules\Procurement\DTOs;

final readonly class EvaluateSupplierDTO
{
    public function __construct(
        public string $supplierId,
        public string $evaluatorUserId,
        public string $periodStart,
        public string $periodEnd,
        public float $onTimeDeliveryRate,
        public float $qualityAcceptanceRate,
        public float $priceCompetitivenessScore,
        public string $overallRating = 'A',
        public bool $isApprovedVendor = true,
        public ?string $comments = null,
        public ?string $tenantId = null,
        public ?string $companyId = null
    ) {}
}
