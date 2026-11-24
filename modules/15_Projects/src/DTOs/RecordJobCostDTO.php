<?php

declare(strict_types=1);

namespace Modules\Projects\DTOs;

/**
 * A cost to book on a project. Amounts are decimal strings; the total must equal quantity times unit cost (rounded to
 * cents) unless the source document states a different total, which is then kept as the authoritative amount.
 */
final readonly class RecordJobCostDTO
{
    /**
     * @param  string  $costCategory  labour, material, supplier, fleet, expense or equipment
     * @param  array<int, array{task_id: string, percentage: string|int|float}>  $allocations
     * @param  string  $approvalStatus  approved (counts at once) or pending (waits for approval, e.g. an expense)
     */
    public function __construct(
        public string $projectId,
        public string $costCategory,
        public string $costSourceType,
        public string $costSourceId,
        public string $quantity,
        public string $unitCost,
        public string $totalCost,
        public string $incurredDate,
        public ?string $phaseId = null,
        public ?string $taskId = null,
        public string $uom = 'PCE',
        public bool $isBillable = true,
        public array $allocations = [],
        public ?string $userId = null,
        public ?string $companyId = null,
        public ?string $description = null,
        public string $approvalStatus = 'approved',
    ) {}
}
