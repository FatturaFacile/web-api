<?php

declare(strict_types=1);

namespace App\Services\Auth\Sso;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds a single sign-on to the browser that started it, the way PKCE binds an authorization code to the client that
 * asked for it. Starting a login sets a short-lived, HttpOnly, Secure, SameSite=Lax cookie that holds a random secret
 * (256 bits); only the SHA-256 of that secret is stored, with the login state. The login can be finished only by a
 * request that carries the cookie: directly (the callback of the SPA) or, when the sign-in ended on a cross-site request
 * that carries no cookie (the SAML assertion posted by the identity provider), at the exchange of the single-use sign-in
 * code, which keeps the hash. A state or a code stolen by another browser is therefore useless.
 *
 * The cookie is not encrypted by Laravel (the name is on the never-encrypt list in bootstrap/app.php): its value is a
 * random secret that means nothing without the stored hash.
 */
final class SsoBrowserBinding
{
    public const COOKIE = 'sso_flow';

    /**
     * A new secret and its hash.
     *
     * @return array{0: string, 1: string}
     */
    public function issue(): array
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [$secret, hash('sha256', $secret)];
    }

    /**
     * Sets the cookie on the response of the login start.
     */
    public function attach(Response $response, string $secret): Response
    {
        $response->headers->setCookie(new Cookie(
            self::COOKIE,
            $secret,
            now()->addMinutes((int) config('sso.state_ttl_minutes')),
            '/',
            null,
            true,
            true,
            false,
            Cookie::SAMESITE_LAX,
        ));

        return $response;
    }

    /**
     * Removes the cookie: the flow is over, whatever its outcome.
     */
    public function forget(Response $response): Response
    {
        $response->headers->clearCookie(self::COOKIE, '/', null, true, true, Cookie::SAMESITE_LAX);

        return $response;
    }

    /**
     * Whether the request comes from the browser that started the login whose state has this hash. A state without a hash
     * binds nothing and is refused.
     */
    public function matches(Request $request, ?string $hash): bool
    {
        $secret = $request->cookies->get(self::COOKIE);

        return $hash !== null && is_string($secret) && $secret !== '' && hash_equals($hash, hash('sha256', $secret));
    }
}
