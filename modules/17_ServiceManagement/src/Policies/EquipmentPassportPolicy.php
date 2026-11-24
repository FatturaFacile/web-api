<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Policies;

use App\Models\User;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;

final class EquipmentPassportPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, SerializedEquipmentPassport $passport): bool
    {
        return $this->belongsToTenant($user, (string) $passport->tenant_id);
    }

    public function update(User $user, SerializedEquipmentPassport $passport): bool
    {
        return $this->belongsToTenant($user, (string) $passport->tenant_id);
    }

    public function addExtension(User $user, SerializedEquipmentPassport $passport): bool
    {
        return $this->belongsToTenant($user, (string) $passport->tenant_id);
    }

    public function delete(User $user, SerializedEquipmentPassport $passport): bool
    {
        return $this->belongsToTenant($user, (string) $passport->tenant_id);
    }

    private function belongsToTenant(User $user, string $tenantId): bool
    {
        $userTenant = (string) ($user->current_tenant_id ?? $user->tenant_id ?? '');

        return $userTenant === '' || $userTenant === $tenantId;
    }
}
