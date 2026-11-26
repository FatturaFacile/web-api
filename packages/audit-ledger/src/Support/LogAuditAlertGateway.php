<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Support;

use Enterprise\AuditLedger\Contracts\AuditAlertGateway;
use Illuminate\Support\Facades\Log;

/**
 * Fallback alert gateway: a critical log line. The application binds the Mod 008 notification adapter over it.
 */
final class LogAuditAlertGateway implements AuditAlertGateway
{
    public function chainBroken(string $tenantId, array $details): void
    {
        Log::critical('The audit chain of a tenant is broken.', ['tenant_id' => $tenantId] + $details);
    }
}
