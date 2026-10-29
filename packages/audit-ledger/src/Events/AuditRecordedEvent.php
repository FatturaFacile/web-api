<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final readonly class AuditRecordedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $auditId,
        public string $tenantId,
        public string $action,
        public string $resourceType,
        public string $resourceId,
        public string $recordHash
    ) {}
}
