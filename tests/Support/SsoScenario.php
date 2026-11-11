<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\SamlProvider;
use App\Models\SsoProvider;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Saml\SamlSettings;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * The steps of a single sign-on as a browser and an identity provider perform them, for the tests of the later
 * single-sign-on features (discovery, browser binding, ES256, logout). The provider is faked with the runtime keys of
 * OidcTestIdp and SamlTestIdp.
 */
final class SsoScenario
{
    public static function member(Tenant $tenant, string $role, string $email): User
    {
        $user = User::create([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => Hash::make(Str::password(16).chr(random_int(65, 90)).chr(random_int(97, 122)).random_int(0, 9)),
            'locale' => 'it',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function oidcClaims(SsoProvider $provider, string $nonce, array $overrides = []): array
    {
        return array_merge([
            'iss' => $provider->issuer,
            'aud' => $provider->client_id,
            'sub' => 'subject-'.Str::random(8),
            'exp' => time() + 300,
            'iat' => time(),
            'nonce' => $nonce,
            'email' => 'anna.rossi@example.com',
            'email_verified' => true,
            'name' => 'Anna Rossi',
        ], $overrides);
    }

    /**
     * Starts an OIDC login: keeps the binding cookie and returns what the provider reads from the authorization URL.
     *
     * @return array{state: string, nonce: string, query: array<string, string>, response: TestResponse}
     */
    public static function oidcStart(Tenant $tenant): array
    {
        $response = test()->postJson('/api/v1/auth/sso/start', ['tenant_id' => $tenant->id])->assertOk();
        SsoFlow::keepCookie($response);
        parse_str((string) parse_url((string) $response->json('data.authorization_url'), PHP_URL_QUERY), $query);

        return ['state' => $query['state'], 'nonce' => $query['nonce'], 'query' => $query, 'response' => $response];
    }

    /**
     * The JWKS of $jwksOwner and a token endpoint that returns $idToken.
     */
    public static function oidcFake(OidcTestIdp $jwksOwner, string $idToken): void
    {
        Http::swap(new Factory);
        Http::fake([
            'idp.example.com/jwks' => Http::response($jwksOwner->jwks()),
            'idp.example.com/token' => Http::response(['id_token' => $idToken, 'token_type' => 'Bearer']),
        ]);
    }

    public static function oidcCallback(string $state, bool $tokenMode = true): TestResponse
    {
        return test()->postJson('/api/v1/auth/sso/callback', ['code' => 'auth-code', 'state' => $state] + ($tokenMode ? ['token_mode' => true] : []));
    }

    /**
     * A whole OIDC login with the claims given.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function oidcLogin(Tenant $tenant, SsoProvider $provider, OidcTestIdp $idp, array $overrides = [], bool $tokenMode = true): TestResponse
    {
        $start = self::oidcStart($tenant);
        self::oidcFake($idp, $idp->idToken(self::oidcClaims($provider, $start['nonce'], $overrides)));

        return self::oidcCallback($start['state'], $tokenMode);
    }

    /**
     * @return array{request_id: string, relay_state: string, url: string, response: TestResponse}
     */
    public static function samlStart(Tenant $tenant): array
    {
        $response = test()->postJson('/api/v1/auth/saml/start', ['tenant_id' => $tenant->id])->assertOk();
        SsoFlow::keepCookie($response);
        $url = (string) $response->json('data.redirect_url');

        return ['request_id' => SamlTestIdp::requestIdOf($url), 'relay_state' => SamlTestIdp::relayStateOf($url), 'url' => $url, 'response' => $response];
    }

    /**
     * The base64 SAML response for the login started, a valid one unless the overrides say otherwise.
     *
     * @param  array{request_id: string, relay_state: string, url: string}  $start
     * @param  array<string, mixed>  $overrides
     */
    public static function samlResponse(Tenant $tenant, SamlTestIdp $idp, array $start, array $overrides = []): string
    {
        return $idp->response(array_merge([
            'destination' => SamlSettings::acsUrl((string) $tenant->id),
            'audience' => SamlSettings::entityId((string) $tenant->id),
            'inResponseTo' => $start['request_id'],
            'nameId' => 'subject-'.Str::random(8),
            'attributes' => ['email' => ['anna.rossi@example.com'], 'name' => ['Anna Rossi']],
        ], $overrides));
    }

    /**
     * What the browser of the user does when the identity provider posts the response: a plain form post, cross-site.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function samlAcsForm(Tenant $tenant, string $samlResponse, string $relayState, array $fields = []): TestResponse
    {
        return test()->post('/api/v1/auth/saml/'.$tenant->id.'/acs', ['SAMLResponse' => $samlResponse, 'RelayState' => $relayState] + $fields);
    }

    public static function samlProvider(Tenant $tenant, SamlTestIdp $idp, array $attributes = []): SamlProvider
    {
        return SamlProvider::factory()->create($attributes + [
            'tenant_id' => $tenant->id,
            'idp_entity_id' => SamlTestIdp::ENTITY_ID,
            'idp_sso_url' => SamlTestIdp::SSO_URL,
            'idp_slo_url' => SamlTestIdp::SLO_URL,
            'idp_certificates' => [$idp->certificateBody()],
        ]);
    }
}
