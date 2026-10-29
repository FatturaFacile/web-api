<?php

declare(strict_types=1);

namespace Modules\Projects\DTOs;

final readonly class RecordJobCostDTO
{
    /**
     * @param  array<int, array{task_id: string, percentage: float}>  $allocations
     */
    public function __construct(
        public string $projectId,
        public string $costCategory, // material, labour, supplier, fleet, expense, equipment
        public string $costSourceType,
        public string $costSourceId,
        public float $quantity,
        public float $unitCost,
        public float $totalCost,
        public string $incurredDate,
        public ?string $phaseId = null,
        public ?string $taskId = null,
        public string $uom = 'PCE',
        public bool $isBillable = true,
        public array $allocations = [],
        public ?string $userId = null,
        public ?string $companyId = null
    ) {}
}
