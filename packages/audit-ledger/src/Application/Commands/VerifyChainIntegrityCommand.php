<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Application\Commands;

/**
 * Verify the whole chain of a tenant, with its checkpoints as anchors, and persist the outcome in `audit_integrity_runs`.
 */
final readonly class VerifyChainIntegrityCommand
{
    public const string TRIGGER_MANUAL = 'manual';

    public const string TRIGGER_SCHEDULED = 'scheduled';

    public function __construct(
        public string $tenantId,
        public string $trigger = self::TRIGGER_MANUAL,
        public ?string $requestedBy = null,
    ) {}
}
