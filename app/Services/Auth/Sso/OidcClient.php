<?php

declare(strict_types=1);

namespace App\Services\Auth\Sso;

use App\Models\SsoLoginState;
use App\Models\SsoProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The OpenID Connect authorization code flow with PKCE (OpenID Connect Core 1.0 section 3.1, RFC 7636, S256 only).
 *
 * `begin` stores a one-time login state (state and nonce as hashes, PKCE verifier encrypted) and returns the URL the
 * browser goes to. `complete` consumes that state atomically (a replayed or forged state finds nothing), exchanges the
 * code at the token endpoint and validates the id_token. Client authentication at the token endpoint is the setting of
 * the provider: `client_secret_post` (default) or `client_secret_basic`; a public client without a secret sends none.
 */
final class OidcClient
{
    public function __construct(
        private readonly SsoUrlGuard $guard,
        private readonly IdTokenValidator $validator,
    ) {}

    /**
     * @return string the authorization URL to send the browser to
     */
    public function begin(SsoProvider $provider, ?string $bindingHash = null): string
    {
        $state = $this->randomToken();
        $nonce = $this->randomToken();
        $verifier = $this->randomToken();
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $record = new SsoLoginState([
            'sso_provider_id' => $provider->id,
            'state_hash' => hash('sha256', $state),
            'nonce_hash' => hash('sha256', $nonce),
            'code_verifier' => $verifier,
            'browser_binding_hash' => $bindingHash,
            'expires_at' => now()->addMinutes((int) config('sso.state_ttl_minutes')),
        ]);
        $record->forceFill(['tenant_id' => $provider->tenant_id])->save();

        $endpoint = (string) $provider->authorization_endpoint;
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $provider->client_id,
            'redirect_uri' => $provider->redirect_uri,
            'scope' => $provider->scopes,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query;
    }

    /**
     * Resolves the state to its provider, spends it, and returns the validated claims of the id_token.
     *
     * @return array{0: SsoProvider, 1: array<string, mixed>, 2: string|null, 3: string} the provider, the claims, the browser binding hash of the login and the raw id_token
     *
     * @throws SsoException
     */
    public function complete(string $code, string $state): array
    {
        $login = $this->consume($state);

        try {
            /** @var SsoProvider|null $provider */
            $provider = SsoProvider::withoutGlobalScopes()->where('tenant_id', $login->tenant_id)->whereKey($login->sso_provider_id)->first();

            if ($provider === null || ! $provider->isReady()) {
                throw new SsoException('provider_unavailable');
            }

            $idToken = $this->exchangeCode($provider, $code, $login->code_verifier);

            return [$provider, $this->validator->validate($idToken, $provider, $login->nonce_hash), $login->browser_binding_hash, $idToken];
        } catch (SsoException $exception) {
            throw $exception->forTenant($login->tenant_id);
        }
    }

    /**
     * Marks the login state as used, once: of two requests with the same state only one updates the row.
     *
     * @throws SsoException when the state is unknown, expired or already used
     */
    private function consume(string $state): SsoLoginState
    {
        $hash = hash('sha256', $state);

        /** @var SsoLoginState|null $login */
        $login = SsoLoginState::withoutGlobalScopes()->where('state_hash', $hash)->first();

        if ($login === null) {
            throw new SsoException('state_unknown');
        }

        $updated = SsoLoginState::withoutGlobalScopes()
            ->whereKey($login->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update(['consumed_at' => now()]);

        if ($updated !== 1) {
            throw (new SsoException($login->consumed_at !== null ? 'state_replayed' : 'state_expired'))->forTenant($login->tenant_id);
        }

        return $login;
    }

    /**
     * @throws SsoException
     */
    private function exchangeCode(SsoProvider $provider, string $code, string $verifier): string
    {
        $endpoint = (string) $provider->token_endpoint;
        $this->guard->assertSafe($endpoint, 'token_endpoint');

        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $provider->redirect_uri,
            'client_id' => $provider->client_id,
            'code_verifier' => $verifier,
        ];

        $request = Http::timeout((int) config('sso.http_timeout_seconds'))->withoutRedirecting()->asForm()->acceptJson();

        if ($provider->client_secret !== null && $provider->client_secret !== '') {
            if ($provider->token_endpoint_auth_method === 'client_secret_basic') {
                // RFC 6749 section 2.3.1: the id and the secret are form-urlencoded before they are put in the Basic header.
                unset($form['client_id']);
                $request = $request->withBasicAuth(urlencode($provider->client_id), urlencode($provider->client_secret));
            } else {
                $form['client_secret'] = $provider->client_secret;
            }
        }

        try {
            $response = $request->post($endpoint, $form);
        } catch (ConnectionException) {
            throw new SsoException('token_endpoint_unreachable');
        }

        $idToken = $response->json('id_token');

        if (! $response->successful() || ! is_string($idToken) || $idToken === '') {
            throw new SsoException('token_exchange_failed', (string) $response->status());
        }

        return $idToken;
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
