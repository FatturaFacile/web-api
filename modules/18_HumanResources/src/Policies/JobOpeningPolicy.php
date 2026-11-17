<?php

declare(strict_types=1);

namespace Modules\HumanResources\Policies;

use App\Models\User;
use Modules\HumanResources\Models\JobOpening;

final class JobOpeningPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, JobOpening $opening): bool
    {
        return $this->belongsToTenant($user, (string) $opening->tenant_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Closed openings (`closed_filled`, `cancelled`) are read only.
     */
    public function update(User $user, JobOpening $opening): bool
    {
        return $this->belongsToTenant($user, (string) $opening->tenant_id) && ! $opening->status->isClosed();
    }

    public function publish(User $user, JobOpening $opening): bool
    {
        return $this->belongsToTenant($user, (string) $opening->tenant_id);
    }

    public function close(User $user, JobOpening $opening): bool
    {
        return $this->belongsToTenant($user, (string) $opening->tenant_id);
    }

    private function belongsToTenant(User $user, string $tenantId): bool
    {
        $userTenant = (string) ($user->current_tenant_id ?? $user->tenant_id ?? '');

        return $userTenant === '' || $userTenant === $tenantId;
    }
}
