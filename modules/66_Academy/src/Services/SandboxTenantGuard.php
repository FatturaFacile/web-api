<?php

declare(strict_types=1);

namespace Modules\Academy\Services;

use App\Models\Tenant;

/**
 * Tells whether a tenant is a Demo / Sandbox tenant (Mod 105): the flag lives in the tenant settings under "sandbox".
 * The Academy touches nothing but sandbox tenants when it reacts to ERP events, so a learner can never complete a mission
 * (or earn a certificate) with production data.
 */
final class SandboxTenantGuard
{
    public function isSandbox(Tenant $tenant): bool
    {
        $settings = $tenant->getAttribute('settings');

        return is_array($settings) && ($settings['sandbox'] ?? false) === true;
    }
}
