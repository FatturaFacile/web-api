<?php

declare(strict_types=1);

namespace App\Services\Auth\Sso;

/**
 * The endpoints of an identity provider are URLs typed by a tenant administrator and fetched by the server, so they are a
 * server-side request forgery vector. A URL is accepted only if it is https (http is accepted only when
 * `sso.allow_insecure_endpoints` is on, for development), has a host and no credentials, and its host is not, and does
 * not resolve to, a private, loopback, link-local or reserved address.
 *
 * Limit: the HTTP client resolves the name again when it connects, so a DNS answer that changes between the check and
 * the call (rebinding) is not covered; redirects are never followed to reduce the surface.
 */
final class SsoUrlGuard
{
    /**
     * @throws SsoException when the URL must not be fetched
     */
    public function assertSafe(string $url, string $what): void
    {
        $parts = parse_url($url);
        $insecureAllowed = (bool) config('sso.allow_insecure_endpoints');

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new SsoException('endpoint_invalid', $what);
        }

        $scheme = strtolower((string) $parts['scheme']);

        if ($scheme !== 'https' && ! ($insecureAllowed && $scheme === 'http')) {
            throw new SsoException('endpoint_not_https', $what);
        }

        if ($insecureAllowed) {
            return;
        }

        $host = trim((string) $parts['host'], '[]');

        foreach ($this->addressesOf($host) as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new SsoException('endpoint_private_address', $what);
            }
        }
    }

    /**
     * @return list<string>
     *
     * @throws SsoException when the host does not resolve
     */
    private function addressesOf(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = gethostbynamel($host);

        if ($addresses === false || $addresses === []) {
            throw new SsoException('endpoint_unresolvable', $host);
        }

        return $addresses;
    }
}
