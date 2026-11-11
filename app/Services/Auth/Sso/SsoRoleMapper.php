<?php

declare(strict_types=1);

namespace App\Services\Auth\Sso;

use App\Models\SsoProvider;

/**
 * Maps the claims of an id_token to a role of the tenant. The provider names the claim (`role_claim`, a dotted path such
 * as `groups` or `realm_access.roles`) and a table from claim value to role; the first entry of the table that matches
 * a value of the claim wins, so the order of the table is the priority. Without a match the provider default role
 * applies. A role that may not be provisioned from outside (the owner) is never returned.
 */
final class SsoRoleMapper
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function resolve(SsoProvider $provider, array $claims): ?string
    {
        $role = $this->fromClaim($provider, $claims) ?? $provider->default_role;

        return ProvisionableRoles::isAllowed($role) ? $role : null;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function fromClaim(SsoProvider $provider, array $claims): ?string
    {
        if ($provider->role_claim === null || $provider->role_claim === '' || empty($provider->role_mapping)) {
            return null;
        }

        $value = data_get($claims, $provider->role_claim);
        $values = array_map('strval', array_filter(is_array($value) ? $value : [$value], fn (mixed $item): bool => is_string($item) || is_int($item)));

        foreach ($provider->role_mapping as $claimValue => $role) {
            if (in_array((string) $claimValue, $values, true) && ProvisionableRoles::isAllowed((string) $role)) {
                return (string) $role;
            }
        }

        return null;
    }
}
