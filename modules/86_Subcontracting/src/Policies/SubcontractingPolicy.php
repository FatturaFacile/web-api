<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Fine-grained permissions of the subcontracting module on top of the module access rules (config/permissions.php, module
 * "subcontracting"): subcontracting.view, .create, .dispatch, .receive and .ledger.export, each also granted by the role below or by
 * the wildcard "subcontracting.*" in the permissions of the tenant membership. Every record must belong to the active company.
 */
final class SubcontractingPolicy
{
    use ResolvesTenantMembership;

    private const array ReadRoles = ['owner', 'admin', 'accountant', 'accountant_readonly', 'external_fiduciary', 'production', 'procurement_manager', 'operations_manager', 'sales_manager', 'technician'];

    private const array PlanRoles = ['owner', 'admin', 'accountant', 'production', 'procurement_manager', 'operations_manager'];

    private const array ExportRoles = ['owner', 'admin', 'accountant', 'accountant_readonly', 'external_fiduciary'];

    public function viewAny(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::ReadRoles, ['subcontracting.view', 'subcontracting.read', 'subcontracting.*']);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user) && $this->isOwnRecord($record);
    }

    public function create(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::PlanRoles, ['subcontracting.create', 'subcontracting.write', 'subcontracting.*']);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->create($user) && $this->isOwnRecord($record);
    }

    public function dispatch(User $user, Model $record): bool
    {
        return $this->hasRoleOrPermission($user, self::PlanRoles, ['subcontracting.dispatch', 'subcontracting.*']) && $this->isOwnRecord($record);
    }

    public function receive(User $user, Model $record): bool
    {
        return $this->hasRoleOrPermission($user, self::PlanRoles, ['subcontracting.receive', 'subcontracting.*']) && $this->isOwnRecord($record);
    }

    public function exportLedger(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::ExportRoles, ['subcontracting.ledger.export', 'subcontracting.*']);
    }
}
