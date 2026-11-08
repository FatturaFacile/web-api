<?php

declare(strict_types=1);

namespace Modules\Analytics\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Modules\Analytics\Models\ReportDefinition;

/**
 * Who may use a saved report. A definition is private to its owner unless shared; only the owner, or an owner or
 * administrator of the company, changes or deletes it.
 */
final class ReportDefinitionPolicy
{
    /**
     * Run or read a definition: yours, or shared with the company.
     */
    public function view(User $user, ReportDefinition $definition): bool
    {
        return $definition->is_shared || (string) $definition->user_id === (string) $user->id;
    }

    /**
     * Change or delete a definition.
     */
    public function manage(User $user, ReportDefinition $definition): bool
    {
        if ((string) $definition->user_id === (string) $user->id) {
            return true;
        }

        $role = $user->getRoleInTenant((string) app(TenantContext::class)->getTenantId());

        return in_array($role, ['owner', 'admin'], true);
    }
}
