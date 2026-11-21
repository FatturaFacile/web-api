<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Queries;

use Carbon\CarbonImmutable;
use Modules\Hse\Domain\Enums\ClearanceContext;

final readonly class ValidateEmployeeHseClearanceForDispatchQuery
{
    public function __construct(
        public string $tenantId,
        public string $employeeId,
        public ClearanceContext $targetContextType,
        public string $targetId,
        public CarbonImmutable $scheduledDateTime,
    ) {}
}
