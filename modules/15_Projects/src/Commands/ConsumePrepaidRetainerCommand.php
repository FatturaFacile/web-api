<?php

declare(strict_types=1);

namespace Modules\Projects\Commands;

/**
 * Burns the approved billable hours up to a date against the prepaid retainer of a project (Mod 081).
 */
final readonly class ConsumePrepaidRetainerCommand
{
    public function __construct(
        public string $projectId,
        public string $upToDate,
        public string $userId,
    ) {}
}
