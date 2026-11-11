<?php

declare(strict_types=1);

namespace App\Services\Auth\Sso;

use App\Models\SsoProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The signing keys of an identity provider (its `jwks_uri`, RFC 7517), cached per provider. An id_token that names an
 * unknown `kid` makes one refresh possible, but not more than one every 30 seconds, so forged tokens cannot turn the
 * login endpoint into a way to hammer the provider.
 */
final class JwksKeys
{
    private const REFRESH_COOLDOWN_SECONDS = 30;

    public function __construct(
        private readonly SsoUrlGuard $guard,
        private readonly JwkConverter $converter,
    ) {}

    /**
     * The PEM public key for the `kid` of a token. A token without `kid` is accepted only when the provider publishes a
     * single signing key.
     *
     * @throws SsoException when no matching key is available
     */
    public function pemFor(SsoProvider $provider, ?string $kid): string
    {
        $cached = $this->cached($provider);
        $jwk = $this->select($cached['keys'] ?? [], $kid);

        if ($jwk === null && (time() - (int) ($cached['fetched_at'] ?? 0)) >= self::REFRESH_COOLDOWN_SECONDS) {
            $cached = $this->refresh($provider);
            $jwk = $this->select($cached['keys'], $kid);
        }

        if ($jwk === null) {
            throw new SsoException('unknown_key');
        }

        return $this->converter->toPem($jwk);
    }

    /**
     * @param  list<array<string, mixed>>  $keys
     * @return array<string, mixed>|null
     */
    private function select(array $keys, ?string $kid): ?array
    {
        $signing = array_values(array_filter($keys, fn (array $key): bool => ($key['kty'] ?? null) === 'RSA' && (($key['use'] ?? 'sig') === 'sig')));

        if ($kid === null) {
            return count($signing) === 1 ? $signing[0] : null;
        }

        foreach ($signing as $key) {
            if (($key['kid'] ?? null) === $kid) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return array{keys?: list<array<string, mixed>>, fetched_at?: int}
     */
    private function cached(SsoProvider $provider): array
    {
        $value = Cache::get($this->cacheKey($provider));

        return is_array($value) ? $value : [];
    }

    /**
     * @return array{keys: list<array<string, mixed>>, fetched_at: int}
     *
     * @throws SsoException
     */
    private function refresh(SsoProvider $provider): array
    {
        $uri = (string) $provider->jwks_uri;
        $this->guard->assertSafe($uri, 'jwks_uri');

        try {
            $response = Http::timeout((int) config('sso.http_timeout_seconds'))->withoutRedirecting()->acceptJson()->get($uri);
        } catch (ConnectionException) {
            throw new SsoException('jwks_unreachable');
        }

        $keys = $response->json('keys');

        if (! $response->successful() || ! is_array($keys)) {
            // The attempt counts for the cooldown even when it failed.
            Cache::put($this->cacheKey($provider), ['keys' => $this->cached($provider)['keys'] ?? [], 'fetched_at' => time()], (int) config('sso.jwks_cache_seconds'));

            throw new SsoException('jwks_failed', (string) $response->status());
        }

        $document = ['keys' => array_values($keys), 'fetched_at' => time()];
        Cache::put($this->cacheKey($provider), $document, (int) config('sso.jwks_cache_seconds'));

        return $document;
    }

    private function cacheKey(SsoProvider $provider): string
    {
        return 'sso-jwks:'.$provider->id.':'.hash('sha256', (string) $provider->jwks_uri);
    }
}
