<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Policies;

use App\Models\User;
use Modules\ServiceManagement\Models\Loaner;

final class LoanerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Loaner $loaner): bool
    {
        return $this->belongsToTenant($user, (string) $loaner->tenant_id);
    }

    public function update(User $user, Loaner $loaner): bool
    {
        return $this->belongsToTenant($user, (string) $loaner->tenant_id);
    }

    private function belongsToTenant(User $user, string $tenantId): bool
    {
        $userTenant = (string) ($user->current_tenant_id ?? $user->tenant_id ?? '');

        return $userTenant === '' || $userTenant === $tenantId;
    }
}
