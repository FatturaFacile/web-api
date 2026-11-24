<?php

declare(strict_types=1);

namespace Modules\HumanResources\Policies;

use App\Models\User;
use Modules\HumanResources\Models\LeaveRequest;

final class LeaveRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->belongsToTenant($user, (string) $leaveRequest->tenant_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function approve(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->belongsToTenant($user, (string) $leaveRequest->tenant_id);
    }

    private function belongsToTenant(User $user, string $tenantId): bool
    {
        $userTenant = (string) ($user->current_tenant_id ?? $user->tenant_id ?? '');

        return $userTenant === '' || $userTenant === $tenantId;
    }
}
