<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Policies;

use App\Models\User;
use Modules\SubscriptionBilling\Models\Subscription;

final class SubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Subscription $subscription): bool
    {
        return $this->belongsToTenant($user, (string) $subscription->tenant_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Subscription $subscription): bool
    {
        return $this->belongsToTenant($user, (string) $subscription->tenant_id) && ! $subscription->is_locked;
    }

    public function upgrade(User $user, Subscription $subscription): bool
    {
        return $this->belongsToTenant($user, (string) $subscription->tenant_id) && ! $subscription->is_locked;
    }

    public function cancel(User $user, Subscription $subscription): bool
    {
        return $this->belongsToTenant($user, (string) $subscription->tenant_id);
    }

    public function delete(User $user, Subscription $subscription): bool
    {
        return $this->belongsToTenant($user, (string) $subscription->tenant_id) && ! $subscription->is_locked;
    }

    private function belongsToTenant(User $user, string $tenantId): bool
    {
        $currentTenant = $user->tenant_id ?? $user->current_tenant_id ?? null;

        return $currentTenant === null || (string) $currentTenant === $tenantId;
    }
}
