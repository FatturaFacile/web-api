<?php

declare(strict_types=1);

namespace Modules\HumanResources\Policies;

use App\Models\User;
use Modules\HumanResources\Models\PerformanceReview;

final class PerformanceReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PerformanceReview $review): bool
    {
        return $this->belongsToTenant($user, (string) $review->tenant_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function complete(User $user, PerformanceReview $review): bool
    {
        return $this->belongsToTenant($user, (string) $review->tenant_id) && ! $review->is_locked;
    }

    private function belongsToTenant(User $user, string $tenantId): bool
    {
        $userTenant = (string) ($user->current_tenant_id ?? $user->tenant_id ?? '');

        return $userTenant === '' || $userTenant === $tenantId;
    }
}
