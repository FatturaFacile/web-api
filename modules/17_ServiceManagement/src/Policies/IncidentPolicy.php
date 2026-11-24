<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Policies;

use App\Models\User;
use Modules\ServiceManagement\Models\Incident;

final class IncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Incident $incident): bool
    {
        return $this->belongsToTenant($user, (string) $incident->tenant_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Incident $incident): bool
    {
        return $this->belongsToTenant($user, (string) $incident->tenant_id) && ! $incident->is_locked;
    }

    public function resolve(User $user, Incident $incident): bool
    {
        return $this->belongsToTenant($user, (string) $incident->tenant_id) && ! $incident->is_locked;
    }

    public function delete(User $user, Incident $incident): bool
    {
        return $this->belongsToTenant($user, (string) $incident->tenant_id) && ! $incident->is_locked;
    }

    private function belongsToTenant(User $user, string $tenantId): bool
    {
        $userTenant = (string) ($user->current_tenant_id ?? $user->tenant_id ?? '');

        return $userTenant === '' || $userTenant === $tenantId;
    }
}
