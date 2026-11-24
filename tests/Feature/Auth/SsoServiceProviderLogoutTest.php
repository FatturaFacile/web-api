<?php

declare(strict_types=1);

use App\Models\SsoIdentity;
use App\Models\SsoProvider;
use App\Models\Tenant;
use App\Services\Auth\Saml\SamlSettings;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\OidcTestIdp;
use Tests\Support\SamlTestIdp;
use Tests\Support\SsoScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['sso.allow_insecure_endpoints' => true, 'auth.token_mode.enabled' => true]);
    $this->tenant = Tenant::factory()->create();
    $this->member = SsoScenario::member($this->tenant, 'member', 'anna.rossi@example.com');
    $this->idp = new OidcTestIdp;
    $this->oidc = SsoProvider::factory()->create([
        'tenant_id' => $this->tenant->id,
        'allowed_email_domains' => ['example.com'],
        'end_session_endpoint' => 'https://idp.example.com/logout',
        'post_logout_redirect_uri' => 'https://app.example.com/logged-out',
    ]);
});

/**
 * An OIDC login with the raw id_token known, so the hint can be compared; returns the Bearer token and the id_token.
 *
 * @return array{0: string, 1: string}
 */
function logoutOidcLogin(Tenant $tenant, SsoProvider $provider, OidcTestIdp $idp): array
{
    $start = SsoScenario::oidcStart($tenant);
    $idToken = $idp->idToken(SsoScenario::oidcClaims($provider, $start['nonce'], ['sub' => 'fixed-subject']));
    SsoScenario::oidcFake($idp, $idToken);

    return [(string) SsoScenario::oidcCallback($start['state'])->assertOk()->json('data.token'), $idToken];
}

function logoutCall(string $token, Tenant $tenant): TestResponse
{
    // A new request of a new client: the guards of the application must not remember the user of the previous call.
    Auth::forgetGuards();

    return test()->withToken($token)->withHeader('X-Tenant-Id', $tenant->id)->postJson('/api/v1/auth/logout');
}

test('OIDC: the local access ends first and the response carries the RP-initiated logout URL with the id_token hint', function (): void {
    [$token, $idToken] = logoutOidcLogin($this->tenant, $this->oidc, $this->idp);

    $url = logoutCall($token, $this->tenant)->assertOk()->json('data.idp_logout_url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://idp.example.com/logout?')
        ->and($query['id_token_hint'])->toBe($idToken)
        ->and($query['client_id'])->toBe($this->oidc->client_id)
        ->and($query['post_logout_redirect_uri'])->toBe('https://app.example.com/logged-out')
        ->and($query['state'])->not->toBe('')
        ->and(PersonalAccessToken::query()->count())->toBe(0)
        ->and(SsoIdentity::withoutGlobalScopes()->first()->last_id_token)->toBeNull()
        ->and(AuditLedgerEntry::query()->where('action', 'SSO_LOGOUT')->where('tenant_id', $this->tenant->id)->count())->toBe(1);

    // The token is gone: the same call is refused, and no hint is left to be used again.
    logoutCall($token, $this->tenant)->assertUnauthorized();
});

test('OIDC: the id_token kept for the hint is encrypted at rest', function (): void {
    [, $idToken] = logoutOidcLogin($this->tenant, $this->oidc, $this->idp);

    expect(DB::table('sso_identities')->value('last_id_token'))->not->toBeNull()->not->toContain($idToken)->not->toContain(substr($idToken, 0, 40))
        ->and(SsoIdentity::withoutGlobalScopes()->first()->last_id_token)->toBe($idToken);
});

test('OIDC: a provider without an end session endpoint, or one that is not usable, ends only the local access', function (): void {
    $this->oidc->update(['end_session_endpoint' => null]);
    [$token] = logoutOidcLogin($this->tenant, $this->oidc, $this->idp);
    logoutCall($token, $this->tenant)->assertOk()->assertJsonPath('data.idp_logout_url', null);
    expect(PersonalAccessToken::query()->count())->toBe(0);

    // Stored in the database in a form the settings would have refused: still never used, and the local logout still happens.
    $this->oidc->update(['end_session_endpoint' => 'http://idp.example.com/logout']);
    [$token] = logoutOidcLogin($this->tenant, $this->oidc, $this->idp);
    logoutCall($token, $this->tenant)->assertOk()->assertJsonPath('data.idp_logout_url', null);
    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('OIDC: a post-logout redirect that is not https is left out of the URL, not sent', function (): void {
    $this->oidc->update(['post_logout_redirect_uri' => 'http://app.example.com/logged-out']);
    [$token] = logoutOidcLogin($this->tenant, $this->oidc, $this->idp);

    logoutCall($token, $this->tenant)->assertOk()->assertJsonPath('data.idp_logout_url', null);
});

test('a password access has no identity provider logout', function (): void {
    $token = $this->member->createToken('password-login')->plainTextToken;

    logoutCall($token, $this->tenant)->assertOk()->assertJsonPath('data.idp_logout_url', null);
    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('an access opened through the SSO of another tenant does not start the logout of this one', function (): void {
    $other = Tenant::factory()->create();
    $this->member->tenants()->attach($other->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    [$token] = logoutOidcLogin($this->tenant, $this->oidc, $this->idp);

    // The token is bound to the first tenant: used for the second it is refused before it reaches the logout.
    logoutCall($token, $other)->assertForbidden();
    expect(PersonalAccessToken::query()->count())->toBe(1)
        ->and(SsoIdentity::withoutGlobalScopes()->first()->last_id_token)->not->toBeNull();
});

test('SAML: the logout sends a LogoutRequest with the NameID and the session index of the login', function (): void {
    $samlIdp = new SamlTestIdp;
    SsoScenario::samlProvider($this->tenant, $samlIdp);
    $start = SsoScenario::samlStart($this->tenant);
    $response = SsoScenario::samlResponse($this->tenant, $samlIdp, $start, ['nameId' => 'saml-subject-1', 'assertionId' => '_assertion-for-logout']);
    $token = (string) $this->postJson('/api/v1/auth/saml/'.$this->tenant->id.'/acs', ['SAMLResponse' => $response, 'RelayState' => $start['relay_state'], 'token_mode' => true])->assertOk()->json('data.token');

    $url = logoutCall($token, $this->tenant)->assertOk()->json('data.idp_logout_url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $xml = (string) gzinflate((string) base64_decode($query['SAMLRequest']));

    expect($url)->toStartWith(SamlTestIdp::SLO_URL.'?')
        ->and($xml)->toContain('LogoutRequest')
        ->and($xml)->toContain('saml-subject-1')
        ->and($xml)->toContain('_s_assertion-for-logout')
        ->and(PersonalAccessToken::query()->count())->toBe(0)
        ->and(SsoIdentity::withoutGlobalScopes()->whereNotNull('saml_provider_id')->first()->last_session_index)->toBeNull();
});

test('SAML: the identity provider answer to that logout brings the browser back to the SPA', function (): void {
    $samlIdp = new SamlTestIdp;
    SsoScenario::samlProvider($this->tenant, $samlIdp);
    $query = $samlIdp->logoutResponseQuery(SamlSettings::sloUrl($this->tenant->id), '_logout-request-1');

    $this->get('/api/v1/auth/saml/'.$this->tenant->id.'/slo?'.http_build_query($query))->assertRedirect('https://localhost:4200/');
});

test('SAML: a logout started by the identity provider still works and needs a signature', function (): void {
    $samlIdp = new SamlTestIdp;
    SsoScenario::samlProvider($this->tenant, $samlIdp);
    $start = SsoScenario::samlStart($this->tenant);
    $response = SsoScenario::samlResponse($this->tenant, $samlIdp, $start, ['nameId' => 'saml-subject-2']);
    $this->postJson('/api/v1/auth/saml/'.$this->tenant->id.'/acs', ['SAMLResponse' => $response, 'RelayState' => $start['relay_state'], 'token_mode' => true])->assertOk();
    $slo = SamlSettings::sloUrl($this->tenant->id);

    $this->get('/api/v1/auth/saml/'.$this->tenant->id.'/slo?'.http_build_query($samlIdp->logoutQuery($slo, 'saml-subject-2', false)))->assertStatus(400);
    expect(PersonalAccessToken::query()->count())->toBe(1);

    $this->get('/api/v1/auth/saml/'.$this->tenant->id.'/slo?'.http_build_query($samlIdp->logoutQuery($slo, 'saml-subject-2')))->assertRedirect();
    expect(PersonalAccessToken::query()->count())->toBe(0);
});
