<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\User;

/**
 * Writes to data that can be global or of a tenant (translations, lookup labels). The client never chooses the tenant:
 * the row belongs to the active tenant, unless the body sends tenant_id as null (or a placeholder such as "default"),
 * which asks for the global row and is reserved to platform administrators. Any other tenant_id is ignored.
 */
trait TargetsGlobalOrActiveTenant
{
    public function wantsGlobal(): bool
    {
        if (! $this->exists('tenant_id')) {
            return false;
        }

        $declared = $this->input('tenant_id');

        if ($declared === null) {
            return $this->nullTenantMeansGlobal();
        }

        return is_string($declared) && in_array(strtolower(trim($declared)), ['null', 'undefined', 'default', 'global', ''], true);
    }

    /**
     * Whether a JSON null in tenant_id asks for the global row, as the clients of this endpoint have always sent it.
     */
    protected function nullTenantMeansGlobal(): bool
    {
        return true;
    }

    protected function mayTargetRequestedScope(): bool
    {
        $user = $this->user();

        return ! $this->wantsGlobal() || ($user instanceof User && $user->is_platform_admin);
    }
}
