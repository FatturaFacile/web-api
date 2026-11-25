<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Events;

/**
 * An awarded tender became a contract (Mod 108) and a project (Mod 065).
 */
final readonly class TenderAwardConverted
{
    public function __construct(
        public string $participationId,
        public string $contractId,
        public string $projectId,
        public string $tenantId,
    ) {}
}
