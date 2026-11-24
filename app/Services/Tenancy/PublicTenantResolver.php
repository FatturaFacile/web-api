<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * The tenant of a public route (translations, lookups). A visitor states a tenant through the tenant_id query parameter
 * or the X-Tenant-Id header, but the statement counts only when a signed-in member of that tenant makes it: anybody
 * else gets the global data, so a guest can never read the overrides of a company by guessing its id.
 */
final class PublicTenantResolver
{
    private const array PlaceholderValues = ['null', 'undefined', 'default', ''];

    public function resolve(Request $request): ?string
    {
        $declared = $request->query('tenant_id') ?? $request->header('X-Tenant-Id') ?? $request->header('X-Tenant-ID');

        if (! is_string($declared) || in_array(strtolower(trim($declared)), self::PlaceholderValues, true)) {
            return null;
        }

        $user = $request->user() ?? $request->user('sanctum');

        return $user instanceof User && $user->belongsToTenant($declared) ? $declared : null;
    }
}
