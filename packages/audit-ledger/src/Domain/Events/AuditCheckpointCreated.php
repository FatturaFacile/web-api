<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class AuditCheckpointCreated
{
    use Dispatchable;

    public function __construct(
        public string $checkpointId,
        public string $tenantId,
        public string $type,
        public int $sequence,
        public string $hash,
    ) {}
}
