<?php

declare(strict_types=1);

namespace Modules\Manufacturing\DTOs;

/**
 * Data of a quality inspection: point (receipt, operation, final), references, sample, measured values and quantities
 * (decimal strings). Conformity of each measurement is computed from the plan tolerances, never taken as given.
 */
final readonly class RecordQualityInspectionDTO
{
    /**
     * @param  list<array{characteristic_id: string, measured_value: string|int|float}>  $characteristicResults
     */
    public function __construct(
        public string $tenantId,
        public string $qualityPlanId,
        public string $inspectionPoint = 'final',
        public ?string $productionOrderId = null,
        public ?string $productionOrderOperationId = null,
        public ?string $materialMovementId = null,
        public ?string $inspectorId = null,
        public int $sampleSize = 1,
        public int $defectCount = 0,
        public ?string $notes = null,
        public array $characteristicResults = [],
        public string $quantityInspected = '0',
        public ?string $quantityRejected = null,
    ) {}
}
