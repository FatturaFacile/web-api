<?php

declare(strict_types=1);

use App\Models\SsoIdentity;
use App\Models\SsoProvider;
use App\Models\Tenant;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\OidcTestIdp;
use Tests\Support\SsoScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['sso.allow_insecure_endpoints' => true, 'auth.token_mode.enabled' => true]);
    $this->tenant = Tenant::factory()->create();
    $this->member = SsoScenario::member($this->tenant, 'member', 'anna.rossi@example.com');
    $this->provider = SsoProvider::factory()->create(['tenant_id' => $this->tenant->id, 'allowed_email_domains' => ['example.com']]);
});

function algFailureReason(): ?string
{
    return AuditLedgerEntry::query()->where('action', 'SSO_LOGIN_FAILED')->latest('event_timestamp')->first()?->payload_after['reason'] ?? null;
}

/**
 * A login where the provider publishes the keys of $jwksOwner and the token comes from $signer.
 */
function algLoginWith(Tenant $tenant, SsoProvider $provider, OidcTestIdp $signer, OidcTestIdp $jwksOwner, array $headerOverrides = []): TestResponse
{
    $start = SsoScenario::oidcStart($tenant);
    SsoScenario::oidcFake($jwksOwner, $signer->idToken(SsoScenario::oidcClaims($provider, $start['nonce']), $headerOverrides));

    return SsoScenario::oidcCallback($start['state']);
}

test('an ES256 id_token (ECDSA P-256) signed by the provider key is accepted', function (): void {
    $idp = new OidcTestIdp('ec-key-1', true);

    algLoginWith($this->tenant, $this->provider, $idp, $idp)->assertOk()->assertJsonPath('data.active_tenant.id', $this->tenant->id);

    expect(SsoIdentity::withoutGlobalScopes()->count())->toBe(1);
});

test('an ES256 id_token signed by another EC key is refused as forged', function (): void {
    $idp = new OidcTestIdp('ec-key-1', true);
    $attacker = new OidcTestIdp('ec-key-1', true);

    algLoginWith($this->tenant, $this->provider, $attacker, $idp)->assertUnauthorized();

    expect(algFailureReason())->toBe('signature_invalid')->and(SsoIdentity::withoutGlobalScopes()->count())->toBe(0);
});

test('the key type must be the one of the algorithm: no RSA key for ES256, no EC key for RS256', function (): void {
    $ec = new OidcTestIdp('shared-kid', true);
    $rsa = new OidcTestIdp('shared-kid');

    // ES256 token, the provider publishes only an RSA key.
    algLoginWith($this->tenant, $this->provider, $ec, $rsa)->assertUnauthorized();
    expect(algFailureReason())->toBe('unknown_key');

    // RS256 token, the provider publishes only an EC key (the cached keys of the first call are forgotten).
    Cache::flush();
    algLoginWith($this->tenant, $this->provider, $rsa, $ec)->assertUnauthorized();
    expect(algFailureReason())->toBe('unknown_key');

    // An RSA-signed token that claims ES256 in its header.
    Cache::flush();
    algLoginWith($this->tenant, $this->provider, $rsa, $rsa, ['alg' => 'ES256'])->assertUnauthorized();
    expect(SsoIdentity::withoutGlobalScopes()->count())->toBe(0);
});

test('a truncated or padded ES256 signature is refused', function (): void {
    $idp = new OidcTestIdp('ec-key-1', true);

    foreach ([random_bytes(10), random_bytes(70)] as $signature) {
        $start = SsoScenario::oidcStart($this->tenant);
        [$header, $payload] = explode('.', $idp->idToken(SsoScenario::oidcClaims($this->provider, $start['nonce'])));
        SsoScenario::oidcFake($idp, $header.'.'.$payload.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '='));

        SsoScenario::oidcCallback($start['state'])->assertUnauthorized();
    }

    expect(algFailureReason())->toBe('signature_invalid');
});

test('ES384, EdDSA and other algorithms are still not accepted', function (string $algorithm): void {
    $idp = new OidcTestIdp('ec-key-1', true);

    algLoginWith($this->tenant, $this->provider, $idp, $idp, ['alg' => $algorithm])->assertUnauthorized();

    expect(algFailureReason())->toBe('algorithm_not_allowed');
})->with(['ES384', 'ES512', 'EdDSA', 'PS256', 'none', 'HS256']);

test('client_secret_post sends the secret in the body and no Authorization header', function (): void {
    $this->provider->update(['token_endpoint_auth_method' => 'client_secret_post']);
    $idp = new OidcTestIdp;

    SsoScenario::oidcLogin($this->tenant, $this->provider, $idp)->assertOk();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://idp.example.com/token'
        && ! $request->hasHeader('Authorization')
        && ($request->data()['client_secret'] ?? null) === $this->provider->client_secret
        && ($request->data()['client_id'] ?? null) === $this->provider->client_id);
});

test('client_secret_basic sends the form-urlencoded id and secret in the Authorization header and nothing in the body', function (): void {
    $secret = 's:e+cr/et '.Str::random(10);
    $this->provider->update(['token_endpoint_auth_method' => 'client_secret_basic', 'client_secret' => $secret]);
    $idp = new OidcTestIdp;

    SsoScenario::oidcLogin($this->tenant, $this->provider, $idp)->assertOk();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://idp.example.com/token'
        && $request->header('Authorization') === ['Basic '.base64_encode(urlencode($this->provider->client_id).':'.urlencode($secret))]
        && ! array_key_exists('client_secret', $request->data())
        && ! array_key_exists('client_id', $request->data())
        && ($request->data()['code_verifier'] ?? null) !== null);
});

test('a public client without a secret authenticates with neither method', function (): void {
    $this->provider->update(['token_endpoint_auth_method' => 'client_secret_basic', 'client_secret' => null]);
    $idp = new OidcTestIdp;

    SsoScenario::oidcLogin($this->tenant, $this->provider, $idp)->assertOk();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://idp.example.com/token'
        && ! $request->hasHeader('Authorization') && ! array_key_exists('client_secret', $request->data()) && array_key_exists('client_id', $request->data()));
});

/**
 * @param  array<string, mixed>  $document
 * @param  array<string, mixed>  $overrides
 */
function algConfigure(Tenant $tenant, array $document, array $overrides = []): TestResponse
{
    $admin = SsoScenario::member($tenant, 'admin', 'ada.admin'.Str::random(4).'@example.com');
    Http::swap(new Factory);
    Http::fake(['login.example.com/.well-known/openid-configuration' => Http::response($document + [
        'issuer' => 'https://login.example.com',
        'authorization_endpoint' => 'https://login.example.com/authorize',
        'token_endpoint' => 'https://login.example.com/token',
        'jwks_uri' => 'https://login.example.com/keys',
    ])]);
    Sanctum::actingAs($admin);

    return test()->withHeader('X-Tenant-Id', $tenant->id)->putJson('/api/v1/settings/security/sso', $overrides + [
        'name' => 'IdP', 'issuer' => 'https://login.example.com', 'redirect_uri' => 'https://app.example.com/cb',
        'client_id' => 'erp', 'client_secret' => Str::random(20), 'allowed_email_domains' => ['example.com'],
    ]);
}

test('discovery chooses client_secret_basic when the provider advertises it, else post, and reads the end session endpoint', function (): void {
    $this->provider->delete();

    algConfigure($this->tenant, ['token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'], 'end_session_endpoint' => 'https://login.example.com/logout'])
        ->assertOk()
        ->assertJsonPath('data.token_endpoint_auth_method', 'client_secret_basic')
        ->assertJsonPath('data.end_session_endpoint', 'https://login.example.com/logout');

    algConfigure($this->tenant, ['token_endpoint_auth_methods_supported' => ['client_secret_post']])
        ->assertOk()->assertJsonPath('data.token_endpoint_auth_method', 'client_secret_post');

    algConfigure($this->tenant, [])->assertOk()->assertJsonPath('data.token_endpoint_auth_method', 'client_secret_post');
});

test('a client authentication method the provider does not advertise is refused, one it advertises is kept', function (): void {
    $this->provider->delete();

    algConfigure($this->tenant, ['token_endpoint_auth_methods_supported' => ['client_secret_basic']], ['token_endpoint_auth_method' => 'client_secret_post'])
        ->assertUnprocessable()->assertJsonValidationErrors('token_endpoint_auth_method');

    algConfigure($this->tenant, ['token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post']], ['token_endpoint_auth_method' => 'client_secret_post'])
        ->assertOk()->assertJsonPath('data.token_endpoint_auth_method', 'client_secret_post');

    algConfigure($this->tenant, [], ['token_endpoint_auth_method' => 'private_key_jwt'])->assertUnprocessable();
});

test('the post-logout redirect must be https, and the end session endpoint is checked like every endpoint', function (): void {
    $this->provider->delete();

    algConfigure($this->tenant, [], ['post_logout_redirect_uri' => 'http://app.example.com/bye'])->assertUnprocessable()->assertJsonValidationErrors('post_logout_redirect_uri');
    algConfigure($this->tenant, [], ['end_session_endpoint' => 'http://login.example.com/logout'])->assertUnprocessable();
    algConfigure($this->tenant, [], ['post_logout_redirect_uri' => 'https://app.example.com/bye', 'end_session_endpoint' => 'https://login.example.com/logout'])
        ->assertOk()->assertJsonPath('data.post_logout_redirect_uri', 'https://app.example.com/bye');
});
