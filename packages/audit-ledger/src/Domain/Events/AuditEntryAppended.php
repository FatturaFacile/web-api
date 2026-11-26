<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An entry was appended to the chain of a tenant. Carries identifiers only: never the content of the entry.
 */
final readonly class AuditEntryAppended
{
    use Dispatchable;

    public function __construct(
        public string $entryId,
        public string $tenantId,
        public int $sequence,
        public string $action,
        public string $category,
        public string $hash,
    ) {}
}
