<?php

declare(strict_types=1);

namespace Modules\Invoicing\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Modules\Invoicing\Models\SalesQuote;

final class SalesQuotePolicy
{
    /**
     * Roles of the active company that may approve or reject a quote when no approval workflow takes the decision.
     */
    public const array ApproverRoles = ['owner', 'admin', 'sales_manager'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Direct approval or rejection of a quote waiting for approval, without a workflow.
     */
    public function approve(User $user, SalesQuote $quote): bool
    {
        $tenantId = $this->tenantContext->getTenantId();

        return $tenantId !== null
            && (string) $quote->tenant_id === (string) $tenantId
            && in_array($user->getRoleInTenant($tenantId), self::ApproverRoles, true);
    }

    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, SalesQuote $quote): bool
    {
        return true;
    }

    public function create(?User $user): bool
    {
        return true;
    }

    public function update(?User $user, SalesQuote $quote): bool
    {
        return true;
    }

    public function delete(?User $user, SalesQuote $quote): bool
    {
        return true;
    }
}
