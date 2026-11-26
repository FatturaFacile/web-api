<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\Tenant;
use Enterprise\AuditLedger\Contracts\AuditAlertGateway;
use Illuminate\Support\Facades\Log;
use Modules\Platform\Services\TenantNotifier;
use Throwable;

/**
 * Adapter of the audit ledger to the Notification & Alert Engine (Mod 008): a broken audit chain is a security incident,
 * reported at once to the owners and administrators of the tenant (the CISO is one of them or holds the owner role).
 */
final class NotificationAuditAlertGateway implements AuditAlertGateway
{
    public function __construct(private readonly TenantNotifier $notifier) {}

    public function chainBroken(string $tenantId, array $details): void
    {
        Log::critical('The audit chain of a tenant is broken.', ['tenant_id' => $tenantId] + $details);

        if (! Tenant::query()->whereKey($tenantId)->exists()) {
            return;
        }

        try {
            $this->notifier->notify(
                $tenantId,
                'security',
                (string) __('audit_ledger.alerts.chain_broken.title'),
                (string) __('audit_ledger.alerts.chain_broken.body', [
                    'outcome' => (string) ($details['outcome'] ?? ''),
                    'sequence' => (string) ($details['broken_at_sequence'] ?? $details['missing_from_sequence'] ?? ''),
                ]),
                '/settings/audit',
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
