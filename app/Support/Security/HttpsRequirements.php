<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Exceptions\InsecureConfigurationException;
use Illuminate\Contracts\Config\Repository;
use Laravel\Sanctum\Sanctum;

/**
 * The HTTPS-only rules of the configuration, checked when the application boots (any environment, tests included):
 * a violation stops the application with a message that names the setting to fix.
 *
 * - `app.url` and `app.frontend_url` are https URLs with a host (every generated link starts from them);
 * - the session cookie is Secure and HttpOnly, SameSite is `lax` or `strict` (never `none`), and a `__Host-` cookie
 *   name is only used with the attributes the browser demands for it (Secure, Path=/, no Domain);
 * - every CORS origin is an https origin (no wildcard, no http);
 * - the Sanctum stateful domains are bare hosts (no scheme, no path) and, when a session domain is set, the API and
 *   the frontend hosts belong to it, so the cookie the API sets is the cookie the SPA sends back.
 */
final class HttpsRequirements
{
    /**
     * @return list<string> the violations, empty when the configuration is HTTPS-only
     */
    public static function problems(Repository $config): array
    {
        $problems = [];

        $appUrl = (string) $config->get('app.url');
        $frontendUrl = (string) $config->get('app.frontend_url');

        foreach (['app.url (APP_URL)' => $appUrl, 'app.frontend_url (FRONTEND_URL)' => $frontendUrl] as $name => $url) {
            if (! self::isHttpsUrl($url)) {
                $problems[] = $name.' must be an https URL with a host, "'.$url.'" given';
            }
        }

        if ($config->get('session.secure') !== true) {
            $problems[] = 'session.secure (SESSION_SECURE_COOKIE) must be true: the session cookie is always Secure';
        }

        if ($config->get('session.http_only') !== true) {
            $problems[] = 'session.http_only (SESSION_HTTP_ONLY) must be true';
        }

        $sameSite = strtolower((string) $config->get('session.same_site'));

        if (! in_array($sameSite, ['lax', 'strict'], true)) {
            $problems[] = 'session.same_site (SESSION_SAME_SITE) must be "lax" or "strict", "'.$sameSite.'" given';
        }

        $domain = $config->get('session.domain');
        $domain = is_string($domain) && $domain !== '' ? strtolower(ltrim($domain, '.')) : null;

        if (str_starts_with((string) $config->get('session.cookie'), '__Host-') && ($domain !== null || $config->get('session.path') !== '/')) {
            $problems[] = 'a "__Host-" session cookie needs session.path "/" and no session.domain';
        }

        foreach ((array) $config->get('cors.allowed_origins', []) as $origin) {
            if (! is_string($origin) || ! self::isHttpsOrigin($origin)) {
                $problems[] = 'cors.allowed_origins (CORS_ALLOWED_ORIGINS) accepts https origins only, "'.(is_string($origin) ? $origin : gettype($origin)).'" given';
            }
        }

        foreach ((array) $config->get('cors.allowed_origins_patterns', []) as $pattern) {
            if (! is_string($pattern) || ! str_contains($pattern, 'https') || str_contains($pattern, 'http:') || str_contains($pattern, 'http\:')) {
                $problems[] = 'cors.allowed_origins_patterns must match https origins only';
            }
        }

        foreach ((array) $config->get('sanctum.stateful', []) as $stateful) {
            if (! is_string($stateful) || $stateful === Sanctum::$currentRequestHostPlaceholder) {
                continue;
            }

            if (preg_match('#^[a-z0-9.\-\[\]:]+$#i', $stateful) !== 1) {
                $problems[] = 'sanctum.stateful (SANCTUM_STATEFUL_DOMAINS) lists bare hosts (host or host:port), "'.$stateful.'" given';
            }
        }

        if ($domain !== null) {
            foreach (['app.url' => $appUrl, 'app.frontend_url' => $frontendUrl] as $name => $url) {
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));

                if ($host !== '' && $host !== $domain && ! str_ends_with($host, '.'.$domain)) {
                    $problems[] = 'the host of '.$name.' ("'.$host.'") is outside session.domain ("'.$domain.'"): the browser would not send the session cookie back';
                }
            }
        }

        return $problems;
    }

    /**
     * @throws InsecureConfigurationException
     */
    public static function assert(Repository $config): void
    {
        $problems = self::problems($config);

        if ($problems !== []) {
            throw InsecureConfigurationException::because($problems);
        }
    }

    private static function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ($parts['host'] ?? '') !== ''
            && ! isset($parts['user'], $parts['pass']);
    }

    private static function isHttpsOrigin(string $origin): bool
    {
        $parts = parse_url($origin);

        return self::isHttpsUrl($origin) && ($parts['path'] ?? '') === '' && ! isset($parts['query'], $parts['fragment']);
    }
}
