<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Application\Queries;

final readonly class EvaluateLongSickLeaveCurteilungOrComportoQuery
{
    public function __construct(
        public string $tenantId,
        public string $employeeId,
        public int $fiscalYear,
        public ?string $country = null,
        public bool $applyCurtailment = false,
    ) {}
}
