<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Policies;

use App\Models\User;
use Modules\ServiceManagement\Models\ChangeRequest;

final class ChangeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ChangeRequest $change): bool
    {
        return $this->belongsToTenant($user, (string) $change->tenant_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ChangeRequest $change): bool
    {
        return $this->belongsToTenant($user, (string) $change->tenant_id) && ! $change->is_locked;
    }

    public function approve(User $user, ChangeRequest $change): bool
    {
        return $this->belongsToTenant($user, (string) $change->tenant_id) && ! $change->is_locked;
    }

    public function delete(User $user, ChangeRequest $change): bool
    {
        return $this->belongsToTenant($user, (string) $change->tenant_id) && ! $change->is_locked;
    }

    private function belongsToTenant(User $user, string $tenantId): bool
    {
        $userTenant = (string) ($user->current_tenant_id ?? $user->tenant_id ?? '');

        return $userTenant === '' || $userTenant === $tenantId;
    }
}
