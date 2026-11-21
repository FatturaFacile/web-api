<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use App\Models\User;
use App\Services\Mobile\RoleResolver;
use App\Services\Tenancy\TenantContext;

/**
 * Authorization policy for Mod 044 Purchase Requisitions enforcing strict multi-tenant isolation.
 */
final readonly class Mod044PurchaseRequisitionPolicy
{
    /** @var list<string> */
    private const array ProcurementManagers = ['owner', 'admin', 'purchasing', 'procurement_manager'];

    /** @var list<string> */
    private const array Converters = ['owner', 'admin', 'purchasing', 'procurement_manager', 'accountant'];

    public function __construct(
        private TenantContext $tenantContext,
        private RoleResolver $roleResolver,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->activeTenantId() !== null;
    }

    public function view(User $user, PurchaseRequisition $requisition): bool
    {
        return $this->sameTenant($requisition);
    }

    public function create(User $user): bool
    {
        return $this->activeTenantId() !== null;
    }

    public function update(User $user, PurchaseRequisition $requisition): bool
    {
        if (! $this->sameTenant($requisition)) {
            return false;
        }

        if ($this->isRequesterOrCreator($user, $requisition)) {
            return true;
        }

        return $this->hasAnyRole($user, (string) $requisition->tenant_id, self::ProcurementManagers);
    }

    public function delete(User $user, PurchaseRequisition $requisition): bool
    {
        if (! $this->sameTenant($requisition)) {
            return false;
        }

        if ($this->isRequesterOrCreator($user, $requisition)) {
            return true;
        }

        return $this->hasAnyRole($user, (string) $requisition->tenant_id, ['owner', 'admin', 'procurement_manager']);
    }

    public function submit(User $user, PurchaseRequisition $requisition): bool
    {
        if (! $this->sameTenant($requisition)) {
            return false;
        }

        if ($this->isRequesterOrCreator($user, $requisition)) {
            return true;
        }

        return $this->hasAnyRole($user, (string) $requisition->tenant_id, self::ProcurementManagers);
    }

    public function decide(User $user, PurchaseRequisition $requisition): bool
    {
        return $this->sameTenant($requisition);
    }

    public function convert(User $user): bool
    {
        $tenantId = $this->activeTenantId();
        if ($tenantId === null) {
            return false;
        }

        if ($this->hasAnyRole($user, $tenantId, self::Converters)) {
            return true;
        }

        return $user->hasTenantPermission($tenantId, 'procurement.write');
    }

    private function activeTenantId(): ?string
    {
        $tenantId = $this->tenantContext->getTenantId();

        return $tenantId !== null && $tenantId !== '' ? (string) $tenantId : null;
    }

    private function sameTenant(PurchaseRequisition $requisition): bool
    {
        $tenantId = $this->activeTenantId();

        return $tenantId !== null && (string) $requisition->tenant_id === $tenantId;
    }

    private function isRequesterOrCreator(User $user, PurchaseRequisition $requisition): bool
    {
        return (string) $user->id === $requisition->effectiveRequesterId()
            || (string) $user->id === (string) ($requisition->created_by ?? '');
    }

    /**
     * @param  list<string>  $allowedRoles
     */
    private function hasAnyRole(User $user, string $tenantId, array $allowedRoles): bool
    {
        $roles = $this->roleResolver->roles($user, $tenantId)->all();

        return array_intersect($roles, $allowedRoles) !== [];
    }
}
