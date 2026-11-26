<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Application\Commands;

/**
 * Pins the current tail of the chain of a tenant: the sequence and hash at this moment, signed with the chain key. A
 * `daily` checkpoint is taken once per UTC day; a `period` checkpoint at the close of an accounting period
 * (`periodRef` such as "2027-12").
 */
final readonly class CreateCheckpointCommand
{
    public function __construct(
        public string $tenantId,
        public string $type = 'daily',
        public ?string $periodRef = null,
    ) {}
}
