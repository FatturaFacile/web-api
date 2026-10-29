<?php

declare(strict_types=1);

namespace Modules\Manufacturing\DTOs;

final readonly class CreateRoutingDTO
{
    /**
     * @param array<int, array{
     *     sequence?: int,
     *     operation_code: string,
     *     name: string,
     *     work_center_id: string,
     *     machine_center_id?: string|null,
     *     setup_time_minutes?: float,
     *     run_time_minutes_per_unit?: float,
     *     labor_time_minutes_per_unit?: float,
     *     queue_time_minutes?: float,
     *     wait_time_minutes?: float,
     *     teardown_time_minutes?: float,
     *     standard_labor_rate?: float,
     *     standard_machine_rate?: float
     * }> $operations
     */
    public function __construct(
        public string $tenantId,
        public string $mfgItemId,
        public string $name,
        public string $revision = 'A',
        public bool $isDefault = true,
        public array $operations = []
    ) {}
}
