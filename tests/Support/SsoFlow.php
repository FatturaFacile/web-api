<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The browser side of a single sign-on in tests: the cookie that binds the login to the browser that started it.
 */
final class SsoFlow
{
    /**
     * Reads the binding cookie of a login start, checks its flags, and makes the test client send it from now on.
     */
    public static function keepCookie(TestResponse $response): string
    {
        $cookie = collect($response->headers->getCookies())->first(fn (Cookie $cookie): bool => $cookie->getName() === 'sso_flow');

        expect($cookie)->not->toBeNull()
            ->and($cookie->isHttpOnly())->toBeTrue()
            ->and($cookie->isSecure())->toBeTrue()
            ->and($cookie->getSameSite())->toBe('lax');

        // JSON requests of the test client carry cookies only with credentials, like a browser fetch with credentials included.
        test()->withCredentials()->withUnencryptedCookie('sso_flow', (string) $cookie->getValue());

        return (string) $cookie->getValue();
    }

    /**
     * Another browser: it has no binding cookie (an empty one), or the cookie of a flow it did not start.
     */
    public static function otherBrowser(string $cookie = ''): void
    {
        test()->withUnencryptedCookie('sso_flow', $cookie);
    }
}
