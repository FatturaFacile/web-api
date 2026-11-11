<?php

declare(strict_types=1);

namespace App\Services\Auth\Sso;

use App\Models\SamlProvider;
use App\Models\SsoProvider;

/**
 * Which single sign-on a login page should offer for an email address, from its domain: the tenant whose ready identity
 * provider (OpenID Connect or SAML) lists the domain in its allowed email domains.
 *
 * The answer is built to tell as little as possible, because it is public:
 * - only the domain is used, never the local part, so it says nothing about whether a user exists;
 * - an unknown domain and a domain without SSO give exactly the same answer (`available: false`, no protocol, no tenant),
 *   from the same two queries, and the call lasts at least `sso.discovery_min_milliseconds` either way, so the time does
 *   not tell them apart;
 * - a domain claimed by more than one tenant is ambiguous and is answered as unavailable: the page cannot pick a tenant
 *   for the user, and nothing about the tenants is revealed;
 * - only the protocol and the tenant id are returned, never a name, issuer or URL.
 * Whether the password login is still allowed (SSO-only mode) is deliberately not part of the answer.
 */
final class SsoDiscovery
{
    /**
     * @return array{available: bool, protocol: string|null, tenant_id: string|null}
     */
    public function find(string $domain): array
    {
        $startedAt = hrtime(true);

        // Both queries always run, whatever the first one finds.
        $oidc = SsoProvider::withoutGlobalScopes()->where('enabled', true)->whereJsonContains('allowed_email_domains', $domain)->get();
        $saml = SamlProvider::withoutGlobalScopes()->where('enabled', true)->whereJsonContains('allowed_email_domains', $domain)->get();

        $connections = [
            ...$oidc->map(fn (SsoProvider $provider): SsoConnection => SsoConnection::fromOidc($provider))->all(),
            ...$saml->map(fn (SamlProvider $provider): SsoConnection => SsoConnection::fromSaml($provider))->all(),
        ];
        $ready = array_values(array_filter($connections, fn (SsoConnection $connection): bool => $connection->ready));
        $tenants = array_values(array_unique(array_map(fn (SsoConnection $connection): string => $connection->tenantId, $ready)));

        $answer = ['available' => false, 'protocol' => null, 'tenant_id' => null];

        if (count($tenants) === 1) {
            $connection = $ready[0];
            $answer = ['available' => true, 'protocol' => $connection->protocol, 'tenant_id' => $connection->tenantId];
        }

        $this->padTo((int) config('sso.discovery_min_milliseconds'), $startedAt);

        return $answer;
    }

    private function padTo(int $milliseconds, int $startedAt): void
    {
        $remaining = $milliseconds * 1000 - (int) ((hrtime(true) - $startedAt) / 1000);

        if ($remaining > 0) {
            usleep($remaining);
        }
    }
}
