<?php

declare(strict_types=1);

namespace Modules\Manufacturing\DTOs;

/**
 * Data of a new routing with its operations. Times are minutes as decimal strings.
 */
final readonly class CreateRoutingDTO
{
    /**
     * @param  list<array{operation_code: string, name: string, work_center_id: string, sequence?: int|null, machine_center_id?: string|null, description?: string|null, setup_time_minutes?: string|int|float|null, run_time_minutes_per_unit?: string|int|float|null, labor_time_minutes_per_unit?: string|int|float|null, queue_time_minutes?: string|int|float|null, wait_time_minutes?: string|int|float|null, teardown_time_minutes?: string|int|float|null, is_subcontracted?: bool|null, subcontractor_contact_id?: string|null, subcontract_unit_cost?: string|int|float|null, quality_plan_id?: string|null}>  $operations
     */
    public function __construct(
        public string $tenantId,
        public string $mfgItemId,
        public string $name,
        public array $operations,
        public string $revision = 'A',
        public bool $isDefault = true,
        public ?string $effectiveFrom = null,
        public ?string $effectiveTo = null,
        public ?string $notes = null,
    ) {}
}
