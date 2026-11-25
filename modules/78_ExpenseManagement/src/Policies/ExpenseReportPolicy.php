<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Modules\ExpenseManagement\Models\ExpenseReport;

/**
 * Every member files and follows their own expense reports. Owners, administrators, HR and accountants (or members granted
 * expense_management.manage) see every report and approve (also expense_management.approve), never their own except for
 * the owner of the company. Paying out, confirming the reimbursement, importing and reconciling card statements are reserved
 * to owners, administrators, accountants and members granted expense_management.settle.
 */
final class ExpenseReportPolicy
{
    use ResolvesTenantMembership;

    /** @var list<string> */
    public const array ManagerRoles = ['owner', 'admin', 'hr', 'accountant'];

    /** @var list<string> */
    public const array FinanceRoles = ['owner', 'admin', 'accountant'];

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function isManager(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::ManagerRoles, ['expense_management.manage', 'expense_management.*']);
    }

    public function isFinance(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::FinanceRoles, ['expense_management.settle', 'expense_management.*']);
    }

    public function view(User $user, ExpenseReport $report): bool
    {
        return $this->isMember($user) && $this->isOwnRecord($report) && ($this->isClaimant($user, $report) || $this->isManager($user));
    }

    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    public function update(User $user, ExpenseReport $report): bool
    {
        return $this->isMember($user) && $this->isOwnRecord($report) && $this->isClaimant($user, $report);
    }

    public function delete(User $user, ExpenseReport $report): bool
    {
        return $this->update($user, $report);
    }

    public function approve(User $user, ExpenseReport $report): bool
    {
        if (! $this->isOwnRecord($report) || ! $this->hasRoleOrPermission($user, self::ManagerRoles, ['expense_management.approve', 'expense_management.*'])) {
            return false;
        }

        return ! $this->isClaimant($user, $report) || $this->hasRoleOrPermission($user, ['owner']);
    }

    public function settle(User $user, ExpenseReport $report): bool
    {
        return $this->isOwnRecord($report) && $this->isFinance($user);
    }

    public function viewCards(User $user): bool
    {
        return $this->isManager($user);
    }

    public function reconcile(User $user): bool
    {
        return $this->isFinance($user);
    }

    private function isClaimant(User $user, ExpenseReport $report): bool
    {
        return (string) $report->user_id === (string) $user->getKey();
    }
}
