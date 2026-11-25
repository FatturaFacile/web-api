<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Events;

/**
 * A participation dossier moved to another status of the tender pipeline.
 */
final readonly class TenderStatusChanged
{
    public function __construct(
        public string $participationId,
        public string $tenderId,
        public string $from,
        public string $to,
        public string $tenantId,
    ) {}
}
