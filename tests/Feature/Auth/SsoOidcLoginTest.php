<?php

declare(strict_types=1);

use App\Models\SsoIdentity;
use App\Models\SsoProvider;
use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\OidcTestIdp;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['sso.allow_insecure_endpoints' => true]);
    $this->password = Str::password(16).chr(random_int(65, 90)).chr(random_int(97, 122)).random_int(0, 9);
    $this->idp = new OidcTestIdp;
    $this->tenant = Tenant::factory()->create();
    $this->provider = SsoProvider::factory()->create([
        'tenant_id' => $this->tenant->id,
        'allowed_email_domains' => ['example.com'],
    ]);
    $this->member = ssoMember($this->tenant, 'member', 'anna.rossi@example.com', $this->password);
});

function ssoMember(Tenant $tenant, string $role, string $email, string $password): User
{
    $user = User::create([
        'name' => Str::before($email, '@'),
        'email' => $email,
        'password' => Hash::make($password),
        'locale' => 'it',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

/**
 * Starts a login and returns the state and nonce the provider would see in the authorization URL.
 *
 * @return array{state: string, nonce: string, query: array<string, string>}
 */
function ssoStart(Tenant $tenant): array
{
    $url = test()->postJson('/api/v1/auth/sso/start', ['tenant_id' => $tenant->id])->assertOk()->json('data.authorization_url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return ['state' => $query['state'], 'nonce' => $query['nonce'], 'query' => $query];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ssoClaims(SsoProvider $provider, string $nonce, array $overrides = []): array
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
 * Makes the provider endpoints answer: the JWKS of $idp and a token endpoint that returns $idToken.
 */
function ssoFakeProvider(OidcTestIdp $idp, string $idToken): void
{
    // A fresh factory: stubs registered earlier would otherwise win over these.
    Http::swap(new Factory);
    Http::fake([
        'idp.example.com/jwks' => Http::response($idp->jwks()),
        'idp.example.com/token' => Http::response(['id_token' => $idToken, 'token_type' => 'Bearer']),
    ]);
}

function ssoCallback(string $state, string $code = 'auth-code'): TestResponse
{
    return test()->postJson('/api/v1/auth/sso/callback', ['code' => $code, 'state' => $state]);
}

function ssoAuditCount(string $action, ?string $tenantId = null): int
{
    return AuditLedgerEntry::query()->where('action', $action)->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))->count();
}

/**
 * Runs a whole login with the claims given and returns the callback response.
 *
 * @param  array<string, mixed>  $overrides
 */
function ssoLogin(Tenant $tenant, SsoProvider $provider, OidcTestIdp $idp, array $overrides = []): TestResponse
{
    $start = ssoStart($tenant);
    ssoFakeProvider($idp, $idp->idToken(ssoClaims($provider, $start['nonce'], $overrides)));

    return ssoCallback($start['state']);
}

test('the start URL carries PKCE S256, state, nonce and the client id of the tenant provider', function (): void {
    $start = ssoStart($this->tenant);

    expect($start['query'])->toMatchArray([
        'response_type' => 'code',
        'client_id' => $this->provider->client_id,
        'redirect_uri' => $this->provider->redirect_uri,
        'code_challenge_method' => 'S256',
    ])->and(strlen($start['query']['code_challenge']))->toBeGreaterThan(40)
        ->and(DB::table('sso_login_states')->count())->toBe(1)
        ->and(DB::table('sso_login_states')->value('state_hash'))->not->toBe($start['state']);
});

test('a member signs in through SSO, the identity is linked and the access is bound to the tenant', function (): void {
    $response = ssoLogin($this->tenant, $this->provider, $this->idp)->assertOk();

    expect($response->json('data.auth_mode'))->toBe('token')
        ->and($response->json('data.token'))->not->toBeNull()
        ->and($response->json('data.active_tenant.id'))->toBe($this->tenant->id)
        ->and(SsoIdentity::withoutGlobalScopes()->where('user_id', $this->member->id)->count())->toBe(1)
        ->and(DB::table('personal_access_tokens')->value('auth_method'))->toBe('sso')
        ->and(DB::table('personal_access_tokens')->value('auth_tenant_id'))->toBe($this->tenant->id)
        ->and(ssoAuditCount('SSO_LOGIN_STARTED', $this->tenant->id))->toBe(1)
        ->and(ssoAuditCount('SSO_IDENTITY_LINKED', $this->tenant->id))->toBe(1)
        ->and(ssoAuditCount('SSO_LOGIN_SUCCEEDED', $this->tenant->id))->toBe(1);

    $this->withToken($response->json('data.token'))->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/auth/profile')->assertOk();
});

test('a second login resolves the user by subject even if the email claim changed', function (): void {
    $sub = 'stable-subject';
    ssoLogin($this->tenant, $this->provider, $this->idp, ['sub' => $sub])->assertOk();
    ssoLogin($this->tenant, $this->provider, $this->idp, ['sub' => $sub, 'email' => 'renamed@example.com'])->assertOk();

    expect(SsoIdentity::withoutGlobalScopes()->count())->toBe(1)
        ->and(User::where('email', 'renamed@example.com')->exists())->toBeFalse();
});

test('just-in-time provisioning creates the user with the role mapped from the claims, never an owner', function (): void {
    $this->provider->update([
        'jit_provisioning' => true,
        'role_claim' => 'groups',
        'role_mapping' => ['erp-owners' => 'owner', 'erp-accounting' => 'accountant'],
        'default_role' => 'member',
    ]);

    ssoLogin($this->tenant, $this->provider, $this->idp, ['email' => 'new.hire@example.com', 'groups' => ['erp-accounting']])->assertOk();
    $accountant = User::where('email', 'new.hire@example.com')->firstOrFail();

    ssoLogin($this->tenant, $this->provider, $this->idp, ['email' => 'boss@example.com', 'groups' => ['erp-owners']])->assertOk();
    $boss = User::where('email', 'boss@example.com')->firstOrFail();

    expect($accountant->getRoleInTenant($this->tenant->id))->toBe('accountant')
        ->and($accountant->email_verified_at)->not->toBeNull()
        ->and($boss->getRoleInTenant($this->tenant->id))->toBe('member')
        ->and(ssoAuditCount('SSO_USER_PROVISIONED', $this->tenant->id))->toBe(2);
});

test('without just-in-time provisioning an unknown email is refused', function (): void {
    ssoLogin($this->tenant, $this->provider, $this->idp, ['email' => 'stranger@example.com'])->assertUnauthorized();

    expect(User::where('email', 'stranger@example.com')->exists())->toBeFalse()
        ->and(ssoAuditCount('SSO_LOGIN_FAILED', $this->tenant->id))->toBe(1);
});

test('the role follows the claims on login only when the tenant asked for it, and an owner is never changed', function (): void {
    $this->provider->update(['role_claim' => 'groups', 'role_mapping' => ['erp-accounting' => 'accountant'], 'sync_role_on_login' => true]);
    ssoLogin($this->tenant, $this->provider, $this->idp, ['groups' => ['erp-accounting']])->assertOk();
    expect($this->member->getRoleInTenant($this->tenant->id))->toBe('accountant');

    $owner = ssoMember($this->tenant, 'owner', 'olga.owner@example.com', $this->password);
    ssoLogin($this->tenant, $this->provider, $this->idp, ['email' => 'olga.owner@example.com', 'groups' => ['erp-accounting']])->assertOk();
    expect($owner->getRoleInTenant($this->tenant->id))->toBe('owner');
});

test('an email domain outside the allow list is refused', function (): void {
    ssoLogin($this->tenant, $this->provider, $this->idp, ['email' => 'anna.rossi@evil.test'])->assertUnauthorized();
});

test('an email the provider did not verify is refused, so it cannot be used to take over an account', function (): void {
    ssoLogin($this->tenant, $this->provider, $this->idp, ['email_verified' => false])->assertUnauthorized();
    ssoLogin($this->tenant, $this->provider, $this->idp, ['email_verified' => null])->assertUnauthorized();

    expect(SsoIdentity::withoutGlobalScopes()->count())->toBe(0);
});

test('an email that belongs to a user of another tenant is never linked', function (): void {
    $other = Tenant::factory()->create();
    ssoMember($other, 'member', 'carla.bianchi@example.com', $this->password);

    ssoLogin($this->tenant, $this->provider, $this->idp, ['email' => 'carla.bianchi@example.com'])->assertUnauthorized();

    expect(SsoIdentity::withoutGlobalScopes()->count())->toBe(0);
});

test('a deactivated account and a platform administrator cannot sign in through a tenant provider', function (): void {
    $this->member->update(['is_active' => false]);
    ssoLogin($this->tenant, $this->provider, $this->idp)->assertUnauthorized();

    $this->member->forceFill(['is_active' => true, 'is_platform_admin' => true])->save();
    ssoLogin($this->tenant, $this->provider, $this->idp)->assertUnauthorized();
});

test('an id_token signed with another key is rejected as forged', function (): void {
    $attacker = new OidcTestIdp($this->idp->kid);
    $start = ssoStart($this->tenant);
    ssoFakeProvider($this->idp, $attacker->idToken(ssoClaims($this->provider, $start['nonce'])));

    ssoCallback($start['state'])->assertUnauthorized();

    $reason = AuditLedgerEntry::query()->where('action', 'SSO_LOGIN_FAILED')->first()->payload_after['reason'] ?? null;
    expect($reason)->toBe('signature_invalid');
});

test('an unsigned token and an HMAC forgery using the public key are rejected', function (): void {
    $start = ssoStart($this->tenant);
    ssoFakeProvider($this->idp, $this->idp->unsigned(ssoClaims($this->provider, $start['nonce'])));
    ssoCallback($start['state'])->assertUnauthorized();

    $start = ssoStart($this->tenant);
    ssoFakeProvider($this->idp, $this->idp->hmacForgery(ssoClaims($this->provider, $start['nonce'])));
    ssoCallback($start['state'])->assertUnauthorized();

    expect(SsoIdentity::withoutGlobalScopes()->count())->toBe(0);
});

test('a token with a tampered payload fails the signature check', function (): void {
    $start = ssoStart($this->tenant);
    [$header, , $signature] = explode('.', $this->idp->idToken(ssoClaims($this->provider, $start['nonce'])));
    $payload = rtrim(strtr(base64_encode((string) json_encode(ssoClaims($this->provider, $start['nonce'], ['email' => 'anna.rossi@example.com', 'sub' => 'someone-else']))), '+/', '-_'), '=');
    ssoFakeProvider($this->idp, $header.'.'.$payload.'.'.$signature);

    ssoCallback($start['state'])->assertUnauthorized();
});

test('wrong audience, wrong issuer, expired and not-yet-valid tokens are rejected', function (string $claim, mixed $value): void {
    ssoLogin($this->tenant, $this->provider, $this->idp, [$claim => $value])->assertUnauthorized();

    expect(SsoIdentity::withoutGlobalScopes()->count())->toBe(0);
})->with([
    'wrong audience' => ['aud', 'another-client'],
    'audience list without the client' => ['aud', ['a', 'b']],
    'wrong issuer' => ['iss', 'https://evil.example.com'],
    'expired' => ['exp', time() - 3600],
    'not yet valid' => ['nbf', time() + 3600],
    'issued in the future' => ['iat', time() + 3600],
]);

test('several audiences need the client as authorized party', function (): void {
    ssoLogin($this->tenant, $this->provider, $this->idp, ['aud' => [$this->provider->client_id, 'other'], 'azp' => 'other'])->assertUnauthorized();
    ssoLogin($this->tenant, $this->provider, $this->idp, ['aud' => [$this->provider->client_id, 'other'], 'azp' => $this->provider->client_id])->assertOk();
});

test('a token with the nonce of another login is rejected', function (): void {
    ssoLogin($this->tenant, $this->provider, $this->idp, ['nonce' => 'nonce-of-another-login'])->assertUnauthorized();
});

test('a replayed state is refused after the first use', function (): void {
    $start = ssoStart($this->tenant);
    ssoFakeProvider($this->idp, $this->idp->idToken(ssoClaims($this->provider, $start['nonce'])));

    ssoCallback($start['state'])->assertOk();
    ssoCallback($start['state'])->assertUnauthorized();

    $reason = AuditLedgerEntry::query()->where('action', 'SSO_LOGIN_FAILED')->first()->payload_after['reason'] ?? null;
    expect($reason)->toBe('state_replayed');
});

test('an unknown or expired state is refused', function (): void {
    ssoCallback(Str::random(43))->assertUnauthorized();

    $start = ssoStart($this->tenant);
    ssoFakeProvider($this->idp, $this->idp->idToken(ssoClaims($this->provider, $start['nonce'])));
    DB::table('sso_login_states')->update(['expires_at' => now()->subMinute()]);

    ssoCallback($start['state'])->assertUnauthorized();
});

test('a token issued by the identity provider of another tenant is not accepted, whichever tenant started the login', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherIdp = new OidcTestIdp('other-key');
    $otherProvider = SsoProvider::factory()->create([
        'tenant_id' => $otherTenant->id,
        'issuer' => 'https://idp.other.example.com',
        'authorization_endpoint' => 'https://idp.other.example.com/authorize',
        'token_endpoint' => 'https://idp.other.example.com/token',
        'jwks_uri' => 'https://idp.other.example.com/jwks',
        'allowed_email_domains' => ['example.com'],
    ]);

    $start = ssoStart($this->tenant);
    $forged = $otherIdp->idToken(ssoClaims($otherProvider, $start['nonce']));
    Http::fake([
        'idp.example.com/jwks' => Http::response($this->idp->jwks()),
        'idp.example.com/token' => Http::response(['id_token' => $forged]),
    ]);

    ssoCallback($start['state'])->assertUnauthorized();

    expect(SsoIdentity::withoutGlobalScopes()->count())->toBe(0);
});

test('a tenant without a ready provider cannot start a login', function (): void {
    $bare = Tenant::factory()->create();
    $this->postJson('/api/v1/auth/sso/start', ['tenant_id' => $bare->id])->assertNotFound();

    $this->provider->update(['enabled' => false]);
    $this->postJson('/api/v1/auth/sso/start', ['tenant_id' => $this->tenant->id])->assertNotFound();
    $this->postJson('/api/v1/auth/sso/start', ['tenant_id' => 'not-a-uuid'])->assertUnprocessable();
});

test('an access opened by the SSO of one tenant does not work in the other tenants of the user', function (): void {
    $other = Tenant::factory()->create();
    $other_role = 'member';
    $this->member->tenants()->attach($other->id, ['id' => (string) Str::uuid(), 'role' => $other_role]);

    $token = ssoLogin($this->tenant, $this->provider, $this->idp)->assertOk()->json('data.token');

    $this->withToken($token)->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/auth/profile')->assertOk();
    $this->withToken($token)->withHeader('X-Tenant-Id', $other->id)->getJson('/api/v1/auth/profile')
        ->assertForbidden()->assertJsonPath('type', 'urn:fatturafacile:problem:session-tenant-mismatch');
});

test('a refreshed SSO token stays bound to its tenant', function (): void {
    $token = ssoLogin($this->tenant, $this->provider, $this->idp)->assertOk()->json('data.token');

    $new = $this->withToken($token)->withHeader('X-Tenant-Id', $this->tenant->id)->postJson('/api/v1/auth/refresh')->assertOk()->json('data.token');

    expect(DB::table('personal_access_tokens')->count())->toBe(1)
        ->and(DB::table('personal_access_tokens')->value('auth_method'))->toBe('sso')
        ->and(DB::table('personal_access_tokens')->value('auth_tenant_id'))->toBe($this->tenant->id)
        ->and($new)->not->toBe($token);
});

test('SSO-only mode refuses a password login and a password token, and lets SSO through', function (): void {
    TenantSecurityPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'sso_only' => true]);

    $this->postJson('/api/v1/auth/login', ['email' => $this->member->email, 'password' => $this->password])
        ->assertForbidden()->assertJsonPath('type', 'urn:fatturafacile:problem:sso-required');

    $legacyToken = $this->member->createToken('legacy')->plainTextToken;
    $this->withToken($legacyToken)->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/auth/profile')
        ->assertForbidden()->assertJsonPath('type', 'urn:fatturafacile:problem:sso-required');

    ssoLogin($this->tenant, $this->provider, $this->idp)->assertOk();
});

test('a user with two factors gets a challenge after SSO, and the challenge keeps the SSO method', function (): void {
    $this->member->forceFill(['two_factor_secret' => Str::random(32), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['x']])->save();

    $response = ssoLogin($this->tenant, $this->provider, $this->idp)->assertOk();

    expect($response->json('data.two_factor_required'))->toBeTrue()
        ->and($response->json('data.token'))->toBeNull()
        ->and(DB::table('personal_access_tokens')->count())->toBe(0);
});

test('the administrator configures the provider by discovery and the secret is stored encrypted and never returned', function (): void {
    $this->provider->delete();
    $admin = ssoMember($this->tenant, 'admin', 'ada.admin@example.com', $this->password);
    $secret = Str::random(40);
    Http::fake(['login.example.com/.well-known/openid-configuration' => Http::response([
        'issuer' => 'https://login.example.com',
        'authorization_endpoint' => 'https://login.example.com/authorize',
        'token_endpoint' => 'https://login.example.com/token',
        'jwks_uri' => 'https://login.example.com/keys',
        'code_challenge_methods_supported' => ['S256'],
    ])]);

    Sanctum::actingAs($admin);
    $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/settings/security/sso', [
        'name' => 'Corporate IdP',
        'issuer' => 'https://login.example.com',
        'redirect_uri' => 'https://app.example.com/auth/sso/callback',
        'client_id' => 'erp',
        'client_secret' => $secret,
        'allowed_email_domains' => ['Example.com'],
        'default_role' => 'member',
        'enabled' => true,
    ])->assertOk();

    expect($response->json('data.token_endpoint'))->toBe('https://login.example.com/token')
        ->and($response->json('data.has_client_secret'))->toBeTrue()
        ->and($response->json('data.ready'))->toBeTrue()
        ->and($response->json('data.allowed_email_domains'))->toBe(['example.com'])
        ->and($response->getContent())->not->toContain($secret)
        ->and(DB::table('sso_providers')->value('client_secret'))->not->toBe($secret)
        ->and(ssoAuditCount('SSO_PROVIDER_SAVED', $this->tenant->id))->toBe(1);

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/settings/security/sso')->assertOk()->assertJsonPath('data.client_id', 'erp');
});

test('a discovery document of another issuer, or an unsafe endpoint, is refused', function (): void {
    $admin = ssoMember($this->tenant, 'admin', 'ada.admin@example.com', $this->password);
    Sanctum::actingAs($admin);
    $payload = [
        'name' => 'IdP', 'issuer' => 'https://login.example.com', 'redirect_uri' => 'https://app.example.com/cb',
        'client_id' => 'erp', 'allowed_email_domains' => ['example.com'],
    ];

    Http::fake(['login.example.com/*' => Http::response([
        'issuer' => 'https://evil.example.com', 'authorization_endpoint' => 'https://evil.example.com/a',
        'token_endpoint' => 'https://evil.example.com/t', 'jwks_uri' => 'https://evil.example.com/k',
    ])]);
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/settings/security/sso', $payload)->assertUnprocessable()->assertJsonValidationErrors('issuer');

    config(['sso.allow_insecure_endpoints' => false]);
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/settings/security/sso', $payload + [
        'use_discovery' => false,
        'authorization_endpoint' => 'https://127.0.0.1/authorize',
        'token_endpoint' => 'https://169.254.169.254/token',
        'jwks_uri' => 'https://10.0.0.5/keys',
    ])->assertUnprocessable();

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/settings/security/sso', array_merge($payload, ['issuer' => 'http://login.example.com']))
        ->assertUnprocessable()->assertJsonValidationErrors('issuer');
});

test('roles that may not be provisioned are refused in the mapping', function (): void {
    $admin = ssoMember($this->tenant, 'admin', 'ada.admin@example.com', $this->password);
    Sanctum::actingAs($admin);

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/settings/security/sso', [
        'name' => 'IdP', 'issuer' => 'https://idp.example.com', 'redirect_uri' => 'https://app.example.com/cb', 'client_id' => 'erp',
        'allowed_email_domains' => ['example.com'], 'use_discovery' => false,
        'authorization_endpoint' => 'https://idp.example.com/authorize', 'token_endpoint' => 'https://idp.example.com/token', 'jwks_uri' => 'https://idp.example.com/jwks',
        'role_claim' => 'groups', 'role_mapping' => ['g' => 'owner'],
    ])->assertUnprocessable()->assertJsonValidationErrors('role_mapping.g');
});

test('SSO settings are tenant scoped and closed to ordinary members', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherAdmin = ssoMember($otherTenant, 'admin', 'olivia.admin@example.com', $this->password);

    Sanctum::actingAs($otherAdmin);
    $this->withHeader('X-Tenant-Id', $otherTenant->id)->getJson('/api/v1/settings/security/sso')->assertOk()->assertJsonPath('data', null);
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/settings/security/sso')->assertForbidden();

    Sanctum::actingAs($this->member);
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/settings/security/sso')->assertForbidden();
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->deleteJson('/api/v1/settings/security/sso')->assertForbidden();
    expect(SsoProvider::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->exists())->toBeTrue();
});

test('the provider cannot be disabled or removed while SSO-only mode is on', function (): void {
    $admin = ssoMember($this->tenant, 'admin', 'ada.admin@example.com', $this->password);
    $admin->forceFill(['is_platform_admin' => true])->save();
    TenantSecurityPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'sso_only' => true]);
    Sanctum::actingAs($admin);

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->deleteJson('/api/v1/settings/security/sso')->assertStatus(409);
});
