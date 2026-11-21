<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Commands;

final readonly class LockRosterPeriodCommand
{
    public function __construct(
        public string $tenantId,
        public string $userId,
        public string $periodId,
    ) {}
}
