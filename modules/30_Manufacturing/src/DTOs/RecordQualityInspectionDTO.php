<?php

declare(strict_types=1);

namespace Modules\Manufacturing\DTOs;

final readonly class RecordQualityInspectionDTO
{
    /**
     * @param array<int, array{
     *     characteristic_id: string,
     *     measured_value: float,
     *     is_conforming: bool
     * }> $characteristicResults
     */
    public function __construct(
        public string $tenantId,
        public string $qualityPlanId,
        public ?string $productionOrderId = null,
        public ?string $operationId = null,
        public ?string $inspectorId = null,
        public int $sampleSize = 1,
        public int $defectCount = 0,
        public ?string $notes = null,
        public array $characteristicResults = []
    ) {}
}
