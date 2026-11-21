<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\ValueObjects;

use Carbon\CarbonImmutable;
use Modules\Hse\Domain\Enums\ClearanceContext;

final readonly class ClearanceRequest
{
    public function __construct(
        public string $tenantId,
        public string $employeeId,
        public ClearanceContext $context,
        public string $targetId,
        public CarbonImmutable $scheduledAt,
    ) {}
}
