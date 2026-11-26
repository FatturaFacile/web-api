<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Support\ResourceTypes;
use Enterprise\MultiTenant\Services\ModuleAccessGate;

/**
 * Who may use the ledger. Searching, verifying and exporting the audit trail of a tenant is reserved to the owner, the
 * administrators, the external auditor and the data protection officer (config audit_ledger.auditor_roles), or to a member
 * granted the matching audit.* permission. The history of a single record is shown to whoever may read that record's module.
 */
final class AuditPolicy
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ModuleAccessGate $gate,
    ) {}

    public function search(User $user): bool
    {
        return $this->isAuditor($user, 'audit.view');
    }

    public function export(User $user): bool
    {
        return $this->isAuditor($user, 'audit.export');
    }

    public function verify(User $user): bool
    {
        return $this->isAuditor($user, 'audit.verify');
    }

    /**
     * The history of one record: the resource type must be one the ledger exposes, and the member must be allowed to read
     * the module the record belongs to.
     */
    public function history(User $user, string $resourceType): bool
    {
        $module = ResourceTypes::module($resourceType);
        $tenantId = $this->tenantId($user);

        if ($module === null || $tenantId === null) {
            return false;
        }

        return $this->gate->allows($user, $tenantId, $module, 'read');
    }

    private function isAuditor(User $user, string $permission): bool
    {
        $tenantId = $this->tenantId($user);

        if ($tenantId === null) {
            return false;
        }

        $role = strtolower((string) $user->getRoleInTenant($tenantId));
        $roles = array_map('strtolower', (array) config('audit_ledger.auditor_roles', []));

        return in_array($role, $roles, true) || $user->hasTenantPermission($tenantId, $permission);
    }

    private function tenantId(User $user): ?string
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $user->tenant_id;

        return $tenantId === null ? null : (string) $tenantId;
    }
}
