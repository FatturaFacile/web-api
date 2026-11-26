<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Application\Exceptions;

use RuntimeException;

/**
 * A verification of the chain of the tenant is already queued or running (HTTP 409).
 */
final class VerificationAlreadyRunning extends RuntimeException
{
    public function __construct(public readonly string $runId)
    {
        parent::__construct('An integrity verification of this audit chain is already in progress.');
    }
}
