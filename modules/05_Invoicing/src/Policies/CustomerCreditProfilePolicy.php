<?php

declare(strict_types=1);

namespace Modules\Invoicing\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Modules\Invoicing\Models\CustomerCreditProfile;

/**
 * Credit management is segregated from selling: a sales user sees the credit position of a customer but only the credit
 * managers of the company set limits and release orders held for credit.
 */
final class CustomerCreditProfilePolicy
{
    /**
     * Roles of the active company that manage customer credit.
     */
    public const array CreditManagerRoles = ['owner', 'admin', 'accountant', 'sales_manager'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Setting a credit limit or releasing a credit hold.
     */
    public function manageCredit(User $user): bool
    {
        $tenantId = $this->tenantContext->getTenantId();

        return $tenantId !== null && in_array($user->getRoleInTenant($tenantId), self::CreditManagerRoles, true);
    }

    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, CustomerCreditProfile $profile): bool
    {
        return true;
    }

    public function create(?User $user): bool
    {
        return true;
    }

    public function update(?User $user, CustomerCreditProfile $profile): bool
    {
        return true;
    }

    public function delete(?User $user, CustomerCreditProfile $profile): bool
    {
        return true;
    }
}
