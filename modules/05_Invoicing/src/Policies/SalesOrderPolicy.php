<?php

declare(strict_types=1);

namespace Modules\Invoicing\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Modules\Invoicing\Models\SalesOrder;

/**
 * Sales orders follow the module access of the invoicing module; the internal approval of an order is reserved to the
 * sales managers of the company, like the approval of a quote.
 */
final class SalesOrderPolicy
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Internal approval of an order.
     */
    public function approve(User $user, SalesOrder $order): bool
    {
        $tenantId = $this->tenantContext->getTenantId();

        return $tenantId !== null
            && (string) $order->tenant_id === (string) $tenantId
            && in_array($user->getRoleInTenant($tenantId), SalesQuotePolicy::ApproverRoles, true);
    }

    /**
     * Release of an order blocked by credit: the credit managers (permission `sales_orders.approve_credit`, or the
     * owner, admin, sales manager and credit manager roles).
     */
    public function approveCredit(User $user, SalesOrder $order): bool
    {
        $tenantId = $this->tenantContext->getTenantId();

        return $tenantId !== null
            && (string) $order->tenant_id === (string) $tenantId
            && $user->hasTenantPermission($tenantId, 'sales_orders.approve_credit', ['owner', 'admin', 'sales_manager', 'credit_manager']);
    }

    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, SalesOrder $order): bool
    {
        return true;
    }

    public function create(?User $user): bool
    {
        return true;
    }

    public function update(?User $user, SalesOrder $order): bool
    {
        return true;
    }

    public function delete(?User $user, SalesOrder $order): bool
    {
        return true;
    }
}
