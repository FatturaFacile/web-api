<?php

declare(strict_types=1);

namespace App\Services\Auth\Sso;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * OpenID Connect Discovery 1.0: reads `<issuer>/.well-known/openid-configuration` and returns the endpoints the login
 * needs. As section 4.3 of the specification requires, the `issuer` of the document must be identical to the issuer the
 * document was fetched for, otherwise a document of another provider could be passed off as this one.
 */
final class OidcDiscovery
{
    public function __construct(private readonly SsoUrlGuard $guard) {}

    /**
     * @return array{authorization_endpoint: string, token_endpoint: string, jwks_uri: string, end_session_endpoint: string|null, token_endpoint_auth_methods_supported: list<string>|null}
     *
     * @throws SsoException when the document cannot be read or is not trustworthy
     */
    public function fetch(string $issuer): array
    {
        $url = rtrim($issuer, '/').'/.well-known/openid-configuration';
        $this->guard->assertSafe($url, 'discovery');

        try {
            $response = $this->client()->get($url);
        } catch (ConnectionException) {
            throw new SsoException('discovery_unreachable');
        }

        $document = $response->json();

        if (! $response->successful() || ! is_array($document)) {
            throw new SsoException('discovery_failed', (string) $response->status());
        }

        if (($document['issuer'] ?? null) !== $issuer) {
            throw new SsoException('discovery_issuer_mismatch');
        }

        $methods = $document['code_challenge_methods_supported'] ?? null;

        if (is_array($methods) && ! in_array('S256', $methods, true)) {
            throw new SsoException('discovery_pkce_unsupported');
        }

        $endpoints = [];

        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
            $value = $document[$key] ?? null;

            if (! is_string($value) || $value === '') {
                throw new SsoException('discovery_incomplete', $key);
            }

            $this->guard->assertSafe($value, $key);
            $endpoints[$key] = $value;
        }

        $endSession = $document['end_session_endpoint'] ?? null;

        if (is_string($endSession) && $endSession !== '') {
            $this->guard->assertSafe($endSession, 'end_session_endpoint');
        } else {
            $endSession = null;
        }

        // Section 3 of OpenID Connect Discovery: when the document does not say, the methods are client_secret_basic only.
        $authMethods = $document['token_endpoint_auth_methods_supported'] ?? null;

        return $endpoints + [
            'end_session_endpoint' => $endSession,
            'token_endpoint_auth_methods_supported' => is_array($authMethods) ? array_values(array_filter($authMethods, 'is_string')) : null,
        ];
    }

    private function client(): PendingRequest
    {
        return Http::timeout((int) config('sso.http_timeout_seconds'))->withoutRedirecting()->acceptJson();
    }
}
