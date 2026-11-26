<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Application\Commands;

/**
 * Starts signing the chain of a tenant with a new key version. The new version must be configured
 * (AUDIT_LEDGER_MASTER_KEY_V<n>) and higher than the one in force; the entries already written keep verifying with the
 * version they carry.
 */
final readonly class RotateAuditKeyCommand
{
    public function __construct(
        public string $tenantId,
        public int $newVersion,
        public ?string $requestedBy = null,
    ) {}
}
