<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Quote;
use App\Models\User;
use App\Services\Tenancy\TenantContext;

class QuotePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Quote $quote): bool
    {
        return $this->belongsToSameTenant($user, $quote);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Quote $quote): bool
    {
        if (! $this->belongsToSameTenant($user, $quote)) {
            return false;
        }

        // Accepted or invoiced quotes cannot be modified
        return ! in_array($quote->status, ['accepted', 'invoiced'], true);
    }

    public function delete(User $user, Quote $quote): bool
    {
        if (! $this->belongsToSameTenant($user, $quote)) {
            return false;
        }

        // Cannot delete accepted or converted quotes
        return ! in_array($quote->status, ['accepted', 'invoiced'], true);
    }

    public function sign(User $user, Quote $quote): bool
    {
        return $this->belongsToSameTenant($user, $quote);
    }

    public function convert(User $user, Quote $quote): bool
    {
        return $this->belongsToSameTenant($user, $quote);
    }

    public function download(User $user, Quote $quote): bool
    {
        return $this->belongsToSameTenant($user, $quote);
    }

    /**
     * Roles that see the industrial cost and the margin of a quote.
     *
     * @var list<string>
     */
    public const array CostVisibleRoles = ['owner', 'admin', 'sales_manager', 'accountant'];

    /**
     * Roles that may decide an approval request; the level check happens in the evaluator against the pending request.
     *
     * @var list<string>
     */
    public const array ApproverRoles = ['owner', 'admin', 'sales_manager'];

    /**
     * Roles that configure the approval tiers of the company.
     *
     * @var list<string>
     */
    public const array TierManagerRoles = ['owner', 'admin'];

    public function viewCost(User $user, Quote $quote): bool
    {
        return $this->belongsToSameTenant($user, $quote) && in_array($user->getRoleInTenant((string) $quote->tenant_id), self::CostVisibleRoles, true);
    }

    public function decideApproval(User $user, Quote $quote): bool
    {
        return $this->belongsToSameTenant($user, $quote) && in_array($user->getRoleInTenant((string) $quote->tenant_id), self::ApproverRoles, true);
    }

    public function manageApprovalTiers(User $user): bool
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return $tenantId !== null && in_array($user->getRoleInTenant((string) $tenantId), self::TierManagerRoles, true);
    }

    private function belongsToSameTenant(User $user, Quote $quote): bool
    {
        if (! empty($user->tenant_id) && $user->tenant_id === $quote->tenant_id) {
            return true;
        }

        return $user->tenants()->where('tenants.id', $quote->tenant_id)->exists();
    }
}
