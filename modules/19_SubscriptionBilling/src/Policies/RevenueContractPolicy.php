<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Policies;

use App\Models\User;
use Modules\SubscriptionBilling\Models\RevenueContract;

final class RevenueContractPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RevenueContract $contract): bool
    {
        return $this->belongsToTenant($user, (string) $contract->tenant_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, RevenueContract $contract): bool
    {
        return $this->belongsToTenant($user, (string) $contract->tenant_id);
    }

    public function delete(User $user, RevenueContract $contract): bool
    {
        return $this->belongsToTenant($user, (string) $contract->tenant_id);
    }

    private function belongsToTenant(User $user, string $tenantId): bool
    {
        $currentTenant = $user->tenant_id ?? $user->current_tenant_id ?? null;

        return $currentTenant === null || (string) $currentTenant === $tenantId;
    }
}
