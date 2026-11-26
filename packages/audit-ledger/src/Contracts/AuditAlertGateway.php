<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Contracts;

/**
 * Port to the notification and alert engine (Mod 008): the security incidents raised by the ledger.
 */
interface AuditAlertGateway
{
    /**
     * Tells the owners and the CISO of a tenant that its audit chain is broken. Must not throw: a failing notification
     * never hides the verification result.
     *
     * @param  array<string, mixed>  $details  run id, outcome, broken sequence or missing range
     */
    public function chainBroken(string $tenantId, array $details): void;
}
