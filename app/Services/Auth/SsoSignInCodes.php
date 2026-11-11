<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The hand-over of a single sign-on that finished on a request without a cookie session: the SAML assertion posted by
 * the identity provider is a cross-site navigation, so the browser sends no session and no CSRF token with it. The
 * assertion is verified there and then, and what the browser receives is a short-lived, single-use code, which is NOT a
 * credential: only the SPA, on a stateful request with the CSRF token, can exchange it for the cookie session
 * (POST /auth/sso/exchange). The code is random (256 bits), stored only as a hash, valid `auth.sso_exchange.ttl_seconds`
 * (60 by default) and spent by the first exchange; a replay or an expired code is refused. It travels in the URL
 * fragment of the redirect, which browsers do not send to servers or in the Referer. The code also keeps the hash of the
 * browser binding of the login (SsoBrowserBinding): the exchange needs the cookie it matches.
 */
final class SsoSignInCodes
{
    private const string PREFIX = 'sso-signin-code:';

    public function ttlSeconds(): int
    {
        return max(1, (int) config('auth.sso_exchange.ttl_seconds'));
    }

    public function issue(User $user, Tenant $tenant, ?string $bindingHash = null): string
    {
        $code = Str::random(64);

        Cache::put(self::PREFIX.hash('sha256', $code), [
            'user_id' => (string) $user->getKey(),
            'tenant_id' => (string) $tenant->getKey(),
            'binding' => $bindingHash,
        ], now()->addSeconds($this->ttlSeconds()));

        return $code;
    }

    /**
     * Spends the code: the first call gets what it was issued for, every other call (replay, expiry, guess) gets null.
     *
     * @return array{user_id: string, tenant_id: string, binding: string|null}|null
     */
    public function redeem(string $code): ?array
    {
        $key = self::PREFIX.hash('sha256', $code);
        $payload = Cache::get($key);

        // `add` is atomic: of two concurrent exchanges only one wins the right to spend the code.
        if (! is_array($payload) || ! Cache::add($key.':spent', true, now()->addSeconds($this->ttlSeconds()))) {
            return null;
        }

        Cache::forget($key);

        return ['user_id' => (string) $payload['user_id'], 'tenant_id' => (string) $payload['tenant_id'], 'binding' => isset($payload['binding']) ? (string) $payload['binding'] : null];
    }
}
