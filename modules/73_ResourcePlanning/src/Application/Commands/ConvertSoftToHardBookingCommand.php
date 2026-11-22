<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Commands;

/**
 * Turns a tentative booking into a committed one once the opportunity is won and the project exists.
 */
final readonly class ConvertSoftToHardBookingCommand
{
    public function __construct(
        public string $tenantId,
        public string $allocationId,
        public ?string $projectId = null,
        public ?string $wbsNodeId = null,
    ) {}
}
