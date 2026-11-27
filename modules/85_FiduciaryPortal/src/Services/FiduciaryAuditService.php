<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Services;

use App\Models\User;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\FiduciaryPortal\Models\FiduciaryAuditLog;

final readonly class FiduciaryAuditService
{
    public function __construct(private AuditLedgerInterface $auditLedger) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $tenantId,
        string $action,
        string $summary,
        ?User $actor = null,
        ?string $targetType = null,
        ?string $targetId = null,
        ?string $targetReference = null,
        array $metadata = [],
        ?Model $auditModel = null,
    ): FiduciaryAuditLog {
        $actorRole = $actor?->getRoleInTenant($tenantId);

        $log = FiduciaryAuditLog::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'actor_user_id' => $actor?->getKey() !== null ? (string) $actor->getKey() : null,
            'actor_name' => $actor?->name,
            'actor_role' => $actorRole,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_reference' => $targetReference,
            'summary' => mb_substr($summary, 0, 500),
            'metadata_json' => $metadata,
            'ip_address' => request()->ip(),
            'created_by' => $actor?->getKey() !== null ? (string) $actor->getKey() : null,
        ]);

        if ($auditModel !== null) {
            $this->auditLedger->record(
                action: $action,
                model: $auditModel,
                payloadBefore: null,
                payloadAfter: $metadata,
                moduleCode: 'MODULE_032_FIDUCIARY_PORTAL'
            );
        }

        return $log;
    }
}
