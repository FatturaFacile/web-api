<?php

declare(strict_types=1);

use App\Models\SamlProvider;
use App\Models\SsoIdentity;
use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use App\Services\Auth\Saml\SamlSettings;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SamlTestIdp;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['sso.allow_insecure_endpoints' => true]);
    $this->password = Str::password(16).chr(random_int(65, 90)).chr(random_int(97, 122)).random_int(0, 9);
    $this->idp = new SamlTestIdp;
    $this->tenant = Tenant::factory()->create();
    $this->provider = SamlProvider::factory()->create([
        'tenant_id' => $this->tenant->id,
        'idp_entity_id' => SamlTestIdp::ENTITY_ID,
        'idp_sso_url' => SamlTestIdp::SSO_URL,
        'idp_slo_url' => SamlTestIdp::SLO_URL,
        'idp_certificates' => [$this->idp->certificateBody()],
    ]);
    $this->member = samlMember($this->tenant, 'member', 'anna.rossi@example.com', $this->password);
});

function samlMember(Tenant $tenant, string $role, string $email, string $password): User
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
 * Starts a login and returns what the identity provider would read from the redirect.
 *
 * @return array{request_id: string, relay_state: string, url: string}
 */
function samlStart(Tenant $tenant): array
{
    $url = test()->postJson('/api/v1/auth/saml/start', ['tenant_id' => $tenant->id])->assertOk()->json('data.redirect_url');

    return ['request_id' => SamlTestIdp::requestIdOf($url), 'relay_state' => SamlTestIdp::relayStateOf($url), 'url' => $url];
}

/**
 * The options of a valid response for the login started, to be overridden by each test.
 *
 * @param  array{request_id: string, relay_state: string, url: string}  $start
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function samlOptions(Tenant $tenant, array $start, array $overrides = []): array
{
    return array_merge([
        'destination' => SamlSettings::acsUrl((string) $tenant->id),
        'audience' => SamlSettings::entityId((string) $tenant->id),
        'inResponseTo' => $start['request_id'],
        'nameId' => 'subject-'.Str::random(8),
        'attributes' => ['email' => ['anna.rossi@example.com'], 'name' => ['Anna Rossi']],
    ], $overrides);
}

function samlPost(Tenant $tenant, SamlTestIdp $idp, array $start, array $overrides = [], ?string $relayState = null, ?Tenant $postTo = null): TestResponse
{
    $response = $idp->response(samlOptions($tenant, $start, $overrides));

    return test()->postJson('/api/v1/auth/saml/'.($postTo ?? $tenant)->id.'/acs', ['SAMLResponse' => $response, 'RelayState' => $relayState ?? $start['relay_state']]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function samlLogin(Tenant $tenant, SamlTestIdp $idp, array $overrides = []): TestResponse
{
    return samlPost($tenant, $idp, samlStart($tenant), $overrides);
}

function samlAuditCount(string $action, ?string $tenantId = null): int
{
    return AuditLedgerEntry::query()->where('action', $action)->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))->count();
}

function samlLastFailureReason(): ?string
{
    return AuditLedgerEntry::query()->where('action', 'SSO_LOGIN_FAILED')->latest('event_timestamp')->first()?->payload_after['reason'] ?? null;
}

test('the service provider metadata is public, per tenant, and names the ACS and SLO of that tenant', function (): void {
    $response = $this->get('/api/v1/auth/saml/'.$this->tenant->id.'/metadata')->assertOk();
    $xml = $response->getContent();

    expect($response->headers->get('Content-Type'))->toContain('samlmetadata+xml')
        ->and($xml)->toContain('entityID="'.SamlSettings::entityId($this->tenant->id).'"')
        ->and($xml)->toContain(SamlSettings::acsUrl($this->tenant->id))
        ->and($xml)->toContain(SamlSettings::sloUrl($this->tenant->id));

    $this->get('/api/v1/auth/saml/'.Tenant::factory()->create()->id.'/metadata')->assertNotFound();
});

test('a login start redirects to the identity provider with a request and a stored state', function (): void {
    $start = samlStart($this->tenant);

    expect($start['url'])->toStartWith(SamlTestIdp::SSO_URL.'?')
        ->and($start['request_id'])->not->toBe('')
        ->and(DB::table('saml_login_states')->count())->toBe(1)
        ->and(DB::table('saml_login_states')->value('relay_state_hash'))->not->toBe($start['relay_state']);

    $this->postJson('/api/v1/auth/saml/start', ['tenant_id' => Tenant::factory()->create()->id])->assertNotFound();
    $this->provider->update(['enabled' => false]);
    $this->postJson('/api/v1/auth/saml/start', ['tenant_id' => $this->tenant->id])->assertNotFound();
});

test('a member signs in through SAML, the identity is linked and the access is bound to the tenant', function (): void {
    $response = samlLogin($this->tenant, $this->idp)->assertOk();

    expect($response->json('data.token'))->not->toBeNull()
        ->and($response->json('data.active_tenant.id'))->toBe($this->tenant->id)
        ->and(SsoIdentity::withoutGlobalScopes()->where('user_id', $this->member->id)->whereNotNull('saml_provider_id')->count())->toBe(1)
        ->and(DB::table('personal_access_tokens')->value('auth_method'))->toBe('sso')
        ->and(DB::table('personal_access_tokens')->value('auth_tenant_id'))->toBe($this->tenant->id)
        ->and(samlAuditCount('SSO_LOGIN_STARTED', $this->tenant->id))->toBe(1)
        ->and(samlAuditCount('SSO_IDENTITY_LINKED', $this->tenant->id))->toBe(1)
        ->and(samlAuditCount('SSO_LOGIN_SUCCEEDED', $this->tenant->id))->toBe(1);

    $this->withToken($response->json('data.token'))->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/auth/profile')->assertOk();
});

test('the email is read from the NameID when no attribute carries it', function (): void {
    samlLogin($this->tenant, $this->idp, ['nameId' => 'anna.rossi@example.com', 'attributes' => ['department' => ['finance']]])->assertOk();
});

test('just-in-time provisioning creates the user with the role mapped from the attributes, never an owner', function (): void {
    $this->provider->update(['jit_provisioning' => true, 'role_attribute' => 'groups', 'role_mapping' => ['erp-owners' => 'owner', 'erp-accounting' => 'accountant'], 'default_role' => 'member']);

    samlLogin($this->tenant, $this->idp, ['attributes' => ['email' => ['new.hire@example.com'], 'groups' => ['erp-accounting']]])->assertOk();
    samlLogin($this->tenant, $this->idp, ['attributes' => ['email' => ['boss@example.com'], 'groups' => ['erp-owners']]])->assertOk();

    expect(User::where('email', 'new.hire@example.com')->first()->getRoleInTenant($this->tenant->id))->toBe('accountant')
        ->and(User::where('email', 'boss@example.com')->first()->getRoleInTenant($this->tenant->id))->toBe('member')
        ->and(samlAuditCount('SSO_USER_PROVISIONED', $this->tenant->id))->toBe(2);
});

test('without just-in-time provisioning an unknown email is refused', function (): void {
    samlLogin($this->tenant, $this->idp, ['attributes' => ['email' => ['stranger@example.com']]])->assertUnauthorized();

    expect(User::where('email', 'stranger@example.com')->exists())->toBeFalse()
        ->and(samlLastFailureReason())->toBe('user_not_provisioned');
});

test('an email domain outside the allow list, or of a user of another tenant, is refused', function (): void {
    samlLogin($this->tenant, $this->idp, ['attributes' => ['email' => ['anna.rossi@evil.test']]])->assertUnauthorized();

    samlMember(Tenant::factory()->create(), 'member', 'carla.bianchi@example.com', $this->password);
    samlLogin($this->tenant, $this->idp, ['attributes' => ['email' => ['carla.bianchi@example.com']]])->assertUnauthorized();

    expect(SsoIdentity::withoutGlobalScopes()->count())->toBe(0);
});

test('an assertion signed by another key is refused as forged', function (): void {
    $attacker = new SamlTestIdp;

    samlPost($this->tenant, $attacker, samlStart($this->tenant))->assertUnauthorized();

    expect(samlLastFailureReason())->toBe('assertion_invalid')
        ->and(SsoIdentity::withoutGlobalScopes()->count())->toBe(0);
});

test('an unsigned assertion is refused', function (): void {
    samlLogin($this->tenant, $this->idp, ['sign' => false])->assertUnauthorized();

    expect(SsoIdentity::withoutGlobalScopes()->count())->toBe(0);
});

test('an assertion changed after it was signed is refused', function (): void {
    samlLogin($this->tenant, $this->idp, ['nameId' => 'subject-a', 'tamper' => 'subject-of-the-victim'])->assertUnauthorized();

    expect(SsoIdentity::withoutGlobalScopes()->count())->toBe(0);
});

test('wrong audience, wrong destination, expired and not yet valid assertions are refused', function (string $case): void {
    $overrides = match ($case) {
        'audience' => ['audience' => 'https://other.example.com/entity'],
        'destination' => ['destination' => 'https://evil.example.com/acs'],
        'expired' => ['notOnOrAfter' => time() - 3600],
        'not yet valid' => ['notBefore' => time() + 3600],
    };

    samlLogin($this->tenant, $this->idp, $overrides)->assertUnauthorized();

    expect(SsoIdentity::withoutGlobalScopes()->count())->toBe(0);
})->with(['audience', 'destination', 'expired', 'not yet valid']);

test('a response to another request (InResponseTo) is refused', function (): void {
    samlLogin($this->tenant, $this->idp, ['inResponseTo' => '_another-request'])->assertUnauthorized();
});

test('a replayed response is refused: the state is spent', function (): void {
    $start = samlStart($this->tenant);
    $options = ['assertionId' => '_same-assertion', 'nameId' => 'subject-1'];

    samlPost($this->tenant, $this->idp, $start, $options)->assertOk();
    samlPost($this->tenant, $this->idp, $start, $options)->assertUnauthorized();

    expect(samlLastFailureReason())->toBe('state_replayed');
});

test('an assertion seen before is refused even with a fresh state', function (): void {
    $options = ['assertionId' => '_same-assertion', 'nameId' => 'subject-1'];

    samlLogin($this->tenant, $this->idp, $options)->assertOk();
    $second = samlStart($this->tenant);
    // The same assertion id, as an attacker replaying a captured assertion into a new login would present it.
    samlPost($this->tenant, $this->idp, $second, $options)->assertUnauthorized();

    expect(samlLastFailureReason())->toBe('assertion_replayed');
});

test('an IdP-initiated response, with an unknown or missing RelayState, is refused', function (): void {
    $start = samlStart($this->tenant);

    samlPost($this->tenant, $this->idp, $start, [], Str::random(43))->assertUnauthorized();
    expect(samlLastFailureReason())->toBe('state_unknown');

    $this->postJson('/api/v1/auth/saml/'.$this->tenant->id.'/acs', ['SAMLResponse' => $this->idp->response(samlOptions($this->tenant, $start))])->assertUnprocessable();
});

test('a state of one tenant cannot be used at the ACS of another', function (): void {
    $other = Tenant::factory()->create();
    SamlProvider::factory()->create(['tenant_id' => $other->id, 'idp_entity_id' => SamlTestIdp::ENTITY_ID, 'idp_sso_url' => SamlTestIdp::SSO_URL, 'idp_certificates' => [$this->idp->certificateBody()]]);
    samlMember($other, 'member', 'other.member@example.com', $this->password);

    $start = samlStart($this->tenant);
    samlPost($other, $this->idp, $start, [], $start['relay_state'], $other)->assertUnauthorized();

    expect(samlLastFailureReason())->toBe('state_tenant_mismatch')
        ->and(SsoIdentity::withoutGlobalScopes()->count())->toBe(0);
});

test('an assertion for one tenant is not valid at the ACS of another tenant, even with the same identity provider', function (): void {
    $other = Tenant::factory()->create();
    SamlProvider::factory()->create(['tenant_id' => $other->id, 'idp_entity_id' => SamlTestIdp::ENTITY_ID, 'idp_sso_url' => SamlTestIdp::SSO_URL, 'idp_certificates' => [$this->idp->certificateBody()]]);

    $startOther = samlStart($other);
    // Valid and signed, but issued for the audience and destination of the first tenant.
    $options = samlOptions($this->tenant, $startOther);
    $response = $this->idp->response($options);

    $this->postJson('/api/v1/auth/saml/'.$other->id.'/acs', ['SAMLResponse' => $response, 'RelayState' => $startOther['relay_state']])->assertUnauthorized();

    expect(samlLastFailureReason())->toBe('assertion_invalid');
});

test('an access opened by the SAML of one tenant does not work in the other tenants of the user', function (): void {
    $other = Tenant::factory()->create();
    $this->member->tenants()->attach($other->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    $token = samlLogin($this->tenant, $this->idp)->assertOk()->json('data.token');

    $this->withToken($token)->withHeader('X-Tenant-Id', $other->id)->getJson('/api/v1/auth/profile')
        ->assertForbidden()->assertJsonPath('type', 'urn:fatturafacile:problem:session-tenant-mismatch');
});

test('SSO-only mode refuses the password and lets SAML through', function (): void {
    TenantSecurityPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'sso_only' => true]);

    $this->postJson('/api/v1/auth/login', ['email' => $this->member->email, 'password' => $this->password])->assertForbidden();
    samlLogin($this->tenant, $this->idp)->assertOk();
});

test('a signed logout request from the identity provider ends the SAML accesses of the user', function (): void {
    $subject = 'subject-for-logout';
    $token = samlLogin($this->tenant, $this->idp, ['nameId' => $subject])->assertOk()->json('data.token');
    $this->member->createToken('password-login');

    $query = $this->idp->logoutQuery(SamlSettings::sloUrl($this->tenant->id), $subject);
    $response = $this->get('/api/v1/auth/saml/'.$this->tenant->id.'/slo?'.http_build_query($query, '', '&', PHP_QUERY_RFC1738));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith(SamlTestIdp::SLO_URL)
        ->and(DB::table('personal_access_tokens')->where('auth_method', 'sso')->count())->toBe(0)
        ->and($token)->not->toBeNull();
});

test('an unsigned or wrongly signed logout request changes nothing', function (): void {
    $subject = 'subject-for-logout';
    samlLogin($this->tenant, $this->idp, ['nameId' => $subject])->assertOk();
    $slo = SamlSettings::sloUrl($this->tenant->id);

    $this->get('/api/v1/auth/saml/'.$this->tenant->id.'/slo?'.http_build_query($this->idp->logoutQuery($slo, $subject, false)))->assertStatus(400);

    $attacker = new SamlTestIdp;
    $this->get('/api/v1/auth/saml/'.$this->tenant->id.'/slo?'.http_build_query($attacker->logoutQuery($slo, $subject)))->assertStatus(400);

    expect(DB::table('personal_access_tokens')->where('auth_method', 'sso')->count())->toBe(1);
});

test('the administrator configures the provider from pasted metadata, and the service provider key is stored encrypted', function (): void {
    $this->provider->delete();
    $admin = samlMember($this->tenant, 'admin', 'ada.admin@example.com', $this->password);
    $sp = new SamlTestIdp;
    Sanctum::actingAs($admin);

    $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/settings/security/saml', [
        'name' => 'Corporate SAML',
        'idp_metadata_xml' => $this->idp->metadataXml(),
        'allowed_email_domains' => ['Example.com'],
        'default_role' => 'member',
        'sp_private_key' => $sp->privateKey,
        'sp_certificate' => $sp->certificate,
        'enabled' => true,
    ])->assertOk();

    expect($response->json('data.idp_entity_id'))->toBe(SamlTestIdp::ENTITY_ID)
        ->and($response->json('data.idp_certificates_count'))->toBe(1)
        ->and($response->json('data.ready'))->toBeTrue()
        ->and($response->json('data.has_sp_signing_key'))->toBeTrue()
        ->and($response->json('data.sp.acs_url'))->toBe(SamlSettings::acsUrl($this->tenant->id))
        ->and($response->getContent())->not->toContain('PRIVATE KEY')
        ->and(DB::table('saml_providers')->value('sp_private_key'))->not->toContain('PRIVATE KEY')
        ->and(samlAuditCount('SSO_PROVIDER_SAVED', $this->tenant->id))->toBe(1);

    // With a signing key, the login request is signed and the metadata publishes the certificate.
    $url = $this->postJson('/api/v1/auth/saml/start', ['tenant_id' => $this->tenant->id])->assertOk()->json('data.redirect_url');
    expect($url)->toContain('Signature=');
    expect($this->get('/api/v1/auth/saml/'.$this->tenant->id.'/metadata')->getContent())->toContain('KeyDescriptor');
});

test('the metadata can be fetched from a URL through the outbound guard, and refreshed', function (): void {
    $this->provider->delete();
    $admin = samlMember($this->tenant, 'admin', 'ada.admin@example.com', $this->password);
    Http::fake(['saml.idp.example.com/metadata' => Http::response($this->idp->metadataXml())]);
    Sanctum::actingAs($admin);

    $payload = ['name' => 'Corporate SAML', 'idp_metadata_url' => 'https://saml.idp.example.com/metadata', 'allowed_email_domains' => ['example.com'], 'enabled' => true];
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/settings/security/saml', $payload)->assertOk()->assertJsonPath('data.idp_metadata_url', 'https://saml.idp.example.com/metadata');

    $fresh = new SamlTestIdp;
    Http::swap(new Factory);
    Http::fake(['saml.idp.example.com/metadata' => Http::response($fresh->metadataXml())]);
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->postJson('/api/v1/settings/security/saml/metadata')->assertOk();

    expect(SamlProvider::withoutGlobalScopes()->first()->idp_certificates)->toBe([$fresh->certificateBody()]);
});

test('unsafe metadata URLs, endpoints and broken certificates are refused', function (): void {
    $this->provider->delete();
    $admin = samlMember($this->tenant, 'admin', 'ada.admin@example.com', $this->password);
    Sanctum::actingAs($admin);
    $base = ['name' => 'Corporate SAML', 'allowed_email_domains' => ['example.com']];
    config(['sso.allow_insecure_endpoints' => false]);

    foreach (['https://127.0.0.1/metadata', 'https://169.254.169.254/latest', 'http://saml.idp.example.com/metadata'] as $url) {
        $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/settings/security/saml', $base + ['idp_metadata_url' => $url])->assertUnprocessable();
    }

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/settings/security/saml', $base + [
        'idp_entity_id' => 'https://idp.example.com', 'idp_sso_url' => 'https://10.0.0.5/sso', 'idp_certificates' => [$this->idp->certificateBody()],
    ])->assertUnprocessable();

    config(['sso.allow_insecure_endpoints' => true]);
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/settings/security/saml', $base + [
        'idp_entity_id' => 'https://idp.example.com', 'idp_sso_url' => 'https://idp.example.com/sso', 'idp_certificates' => ['not a certificate'],
    ])->assertUnprocessable();
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/settings/security/saml', $base + ['idp_metadata_xml' => '<not-metadata/>'])->assertUnprocessable();
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/settings/security/saml', $base)->assertUnprocessable()->assertJsonValidationErrors('idp_metadata_url');

    expect(SamlProvider::withoutGlobalScopes()->count())->toBe(0);
});

test('SAML settings are tenant scoped and closed to ordinary members', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherAdmin = samlMember($otherTenant, 'admin', 'olivia.admin@example.com', $this->password);

    Sanctum::actingAs($otherAdmin);
    $this->withHeader('X-Tenant-Id', $otherTenant->id)->getJson('/api/v1/settings/security/saml')->assertOk()->assertJsonPath('data', null);
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/settings/security/saml')->assertForbidden();

    Sanctum::actingAs($this->member);
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/settings/security/saml')->assertForbidden();
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->deleteJson('/api/v1/settings/security/saml')->assertForbidden();
    expect(SamlProvider::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->exists())->toBeTrue();
});

test('the provider cannot be removed while SSO-only mode is on, and can be otherwise', function (): void {
    $admin = samlMember($this->tenant, 'admin', 'ada.admin@example.com', $this->password);
    $admin->forceFill(['is_platform_admin' => true])->save();
    Sanctum::actingAs($admin);
    $policy = TenantSecurityPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'sso_only' => true]);

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->deleteJson('/api/v1/settings/security/saml')->assertStatus(409);

    $policy->update(['sso_only' => false]);
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->deleteJson('/api/v1/settings/security/saml')->assertOk();
    expect(SamlProvider::withoutGlobalScopes()->count())->toBe(0);
});

test('SSO-only can be turned on for a tenant whose ready provider is SAML', function (): void {
    $admin = samlMember($this->tenant, 'admin', 'ada.admin@example.com', $this->password);
    Sanctum::actingAs($admin);

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/settings/security/policy', ['sso_only' => true])->assertStatus(409);

    $identity = new SsoIdentity(['saml_provider_id' => $this->provider->id, 'user_id' => $admin->id, 'subject' => 'admin-subject']);
    $identity->forceFill(['tenant_id' => $this->tenant->id])->save();
    $admin->forceFill(['is_platform_admin' => true])->save();
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/settings/security/policy', ['sso_only' => true])->assertOk()->assertJsonPath('data.sso_only', true);
});
