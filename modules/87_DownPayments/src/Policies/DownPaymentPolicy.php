<?php

declare(strict_types=1);

namespace Modules\DownPayments\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Fine-grained permissions of the down-payment module on top of the module access rules (config/permissions.php, module
 * "down_payments"): down_payments.view, .create, .emit, .deposit.resolve, .settle and .credit_note, each also granted by the role
 * below or by the wildcard "down_payments.*" in the permissions of the tenant membership. Every record must belong to the active
 * company. Moving money (resolving a deposit), issuing the final invoice and crediting an advance are reserved to finance.
 */
final class DownPaymentPolicy
{
    use ResolvesTenantMembership;

    private const array ReadRoles = ['owner', 'admin', 'accountant', 'accountant_readonly', 'external_fiduciary', 'sales', 'sales_manager'];

    private const array PlanRoles = ['owner', 'admin', 'accountant', 'sales_manager'];

    private const array FinanceRoles = ['owner', 'admin', 'accountant'];

    public function viewAny(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::ReadRoles, ['down_payments.view', 'down_payments.read', 'down_payments.*']);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user) && $this->isOwnRecord($record);
    }

    public function create(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::PlanRoles, ['down_payments.create', 'down_payments.write', 'down_payments.*']);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->create($user) && $this->isOwnRecord($record);
    }

    public function emit(User $user, Model $record): bool
    {
        return $this->hasRoleOrPermission($user, self::PlanRoles, ['down_payments.emit', 'down_payments.*']) && $this->isOwnRecord($record);
    }

    public function resolveDeposit(User $user, Model $record): bool
    {
        return $this->hasRoleOrPermission($user, self::FinanceRoles, ['down_payments.deposit.resolve', 'down_payments.*']) && $this->isOwnRecord($record);
    }

    public function settle(User $user, Model $record): bool
    {
        return $this->hasRoleOrPermission($user, self::FinanceRoles, ['down_payments.settle', 'down_payments.*']) && $this->isOwnRecord($record);
    }

    public function creditNote(User $user, Model $record): bool
    {
        return $this->hasRoleOrPermission($user, self::FinanceRoles, ['down_payments.credit_note', 'down_payments.*']) && $this->isOwnRecord($record);
    }
}
