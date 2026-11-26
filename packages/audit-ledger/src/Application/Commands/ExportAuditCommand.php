<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Application\Commands;

/**
 * Request an export package of the ledger of a tenant for a date range (at most 366 days) and the optional filters of
 * the search. The package is built asynchronously; a repeated request with the same idempotency key returns the first.
 */
final readonly class ExportAuditCommand
{
    /**
     * @param  array<string, mixed>  $filters  actor, action, resource_type, company_id, channel, ip, category
     */
    public function __construct(
        public string $tenantId,
        public string $requestedBy,
        public string $from,
        public string $to,
        public array $filters = [],
        public ?string $idempotencyKey = null,
    ) {}
}
