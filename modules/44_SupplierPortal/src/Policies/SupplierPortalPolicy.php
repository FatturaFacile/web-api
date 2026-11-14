<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;
use Modules\SupplierPortal\Models\BankChangeRequest;

/**
 * Who reads and writes the supplier records is decided by the module access rules (config/permissions.php, module
 * "supplier_portal"). The policy keeps every record inside the active company and reserves the sensitive steps:
 * approving a supplier or blocking it (owner, admin, procurement manager or supplier_portal.approve) and approving a
 * bank detail change (owner, admin, accountant or supplier_portal.bank_approve), which is also never allowed to the
 * user who asked for it.
 */
final class SupplierPortalPolicy
{
    use ResolvesTenantMembership;

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->isMember($user) && $this->isOwnRecord($record);
    }

    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->isMember($user) && $this->isOwnRecord($record);
    }

    /**
     * Approve, reject or block a supplier.
     */
    public function decide(User $user, Model $record): bool
    {
        return $this->isOwnRecord($record) && $this->hasRoleOrPermission($user, ['owner', 'admin', 'procurement_manager'], ['supplier_portal.approve', 'supplier_portal.*']);
    }

    /**
     * Approve or reject a bank detail change, and confirm it by callback.
     */
    public function decideBankChange(User $user, BankChangeRequest $request): bool
    {
        return $this->isOwnRecord($request)
            && (string) $request->requested_by !== (string) $user->getKey()
            && $this->hasRoleOrPermission($user, ['owner', 'admin', 'accountant'], ['supplier_portal.bank_approve', 'supplier_portal.*']);
    }
}
