<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Queries;

use Carbon\CarbonImmutable;

final readonly class GetResourceCapacityHeatmapQuery
{
    /**
     * @param  list<string>|null  $employeeIds
     */
    public function __construct(
        public string $tenantId,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public bool $weekly = true,
        public ?string $departmentId = null,
        public ?array $employeeIds = null,
    ) {}
}
