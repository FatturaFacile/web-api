<?php

declare(strict_types=1);

use App\Models\SsoProvider;
use App\Models\Tenant;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\OidcTestIdp;
use Tests\Support\SamlTestIdp;
use Tests\Support\SsoFlow;
use Tests\Support\SsoScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['sso.allow_insecure_endpoints' => true, 'auth.token_mode.enabled' => false]);
    $this->tenant = Tenant::factory()->create();
    $this->member = SsoScenario::member($this->tenant, 'member', 'anna.rossi@example.com');
    $this->idp = new OidcTestIdp;
    $this->oidc = SsoProvider::factory()->create(['tenant_id' => $this->tenant->id, 'allowed_email_domains' => ['example.com']]);
    $this->samlIdp = new SamlTestIdp;
    $this->saml = SsoScenario::samlProvider($this->tenant, $this->samlIdp);
});

function bindingFailureReason(): ?string
{
    return AuditLedgerEntry::query()->where('action', 'SSO_LOGIN_FAILED')->latest('event_timestamp')->first()?->payload_after['reason'] ?? null;
}

test('starting a login sets the binding cookie: HttpOnly, Secure, SameSite=Lax, and only a hash is stored', function (): void {
    $start = SsoScenario::oidcStart($this->tenant);
    $cookie = collect($start['response']->headers->getCookies())->first(fn ($cookie): bool => $cookie->getName() === 'sso_flow');

    expect($cookie->getValue())->toHaveLength(43)
        ->and($cookie->getExpiresTime())->toBeGreaterThan(time())->toBeLessThanOrEqual(time() + 601)
        ->and(DB::table('sso_login_states')->value('browser_binding_hash'))->toBe(hash('sha256', $cookie->getValue()))
        ->and(json_encode(DB::table('sso_login_states')->first()))->not->toContain($cookie->getValue());

    $saml = SsoScenario::samlStart($this->tenant);
    expect(DB::table('saml_login_states')->value('browser_binding_hash'))->not->toBeNull()->and($saml['url'])->toStartWith(SamlTestIdp::SSO_URL);
});

test('OIDC: a callback that opens the access itself must come from the browser that started the login', function (?string $cookie): void {
    config(['auth.token_mode.enabled' => true]);
    $start = SsoScenario::oidcStart($this->tenant);
    SsoScenario::oidcFake($this->idp, $this->idp->idToken(SsoScenario::oidcClaims($this->oidc, $start['nonce'])));

    SsoFlow::otherBrowser($cookie ?? '');
    SsoScenario::oidcCallback($start['state'])->assertUnauthorized();

    expect(bindingFailureReason())->toBe('browser_mismatch')
        ->and(PersonalAccessToken::query()->count())->toBe(0);
})->with([[null], ['a-cookie-of-another-flow']]);

test('OIDC: a state stolen by another browser is spent: the owner cannot finish either', function (): void {
    config(['auth.token_mode.enabled' => true]);
    $start = SsoScenario::oidcStart($this->tenant);
    $secret = SsoFlow::keepCookie($start['response']);
    SsoScenario::oidcFake($this->idp, $this->idp->idToken(SsoScenario::oidcClaims($this->oidc, $start['nonce'])));

    SsoFlow::otherBrowser();
    SsoScenario::oidcCallback($start['state'])->assertUnauthorized();

    SsoFlow::otherBrowser($secret);
    SsoScenario::oidcCallback($start['state'])->assertUnauthorized();
    expect(bindingFailureReason())->toBe('state_replayed');
});

test('OIDC: the browser that started the login finishes it, with no token', function (): void {
    $response = SsoScenario::oidcLogin($this->tenant, $this->oidc, $this->idp, [], false);

    // Without a session and without the token mode the sign-in is handed over with a code, not with a credential.
    $response->assertOk()->assertJsonMissingPath('data.token')->assertJsonPath('data.expires_in', 60);
    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('OIDC: a sign-in code is exchanged only by the browser it was bound to, and then it is spent', function (): void {
    $start = SsoScenario::oidcStart($this->tenant);
    $secret = SsoFlow::keepCookie($start['response']);
    SsoScenario::oidcFake($this->idp, $this->idp->idToken(SsoScenario::oidcClaims($this->oidc, $start['nonce'])));
    $code = SsoScenario::oidcCallback($start['state'], false)->assertOk()->json('data.sign_in_code');

    // Another browser got hold of the code: it has no cookie, or the cookie of a flow it started itself.
    SsoFlow::otherBrowser();
    $this->withHeaders(spaHeaders())->postJson('/api/v1/auth/sso/exchange', ['code' => $code])->assertUnauthorized();
    $other = SsoScenario::oidcStart($this->tenant);
    $this->withHeaders(spaHeaders())->postJson('/api/v1/auth/sso/exchange', ['code' => $code])->assertUnauthorized();

    // The failed attempts burned the code: the right browser has lost it too, and starts again.
    SsoFlow::otherBrowser($secret);
    $this->withHeaders(spaHeaders())->postJson('/api/v1/auth/sso/exchange', ['code' => $code])->assertUnauthorized();
    expect($other['state'])->not->toBe($start['state']);
});

test('OIDC: the right browser exchanges the code for the cookie session and the binding cookie is removed', function (): void {
    $start = SsoScenario::oidcStart($this->tenant);
    SsoScenario::oidcFake($this->idp, $this->idp->idToken(SsoScenario::oidcClaims($this->oidc, $start['nonce'])));
    $code = SsoScenario::oidcCallback($start['state'], false)->assertOk()->json('data.sign_in_code');

    $response = $this->withHeaders(spaHeaders())->postJson('/api/v1/auth/sso/exchange', ['code' => $code])
        ->assertOk()->assertJsonPath('data.auth_mode', 'cookie')->assertJsonMissingPath('data.token');

    $cookie = collect($response->headers->getCookies())->first(fn ($cookie): bool => $cookie->getName() === 'sso_flow');
    expect($cookie->getExpiresTime())->toBeLessThan(time())
        ->and(PersonalAccessToken::query()->count())->toBe(0);

    $this->withHeaders(spaHeaders())->postJson('/api/v1/auth/sso/exchange', ['code' => $code])->assertUnauthorized();
});

test('SAML: the ACS posted cross-site has no cookie, redirects to the SPA with a code, and only the starting browser exchanges it', function (): void {
    $start = SsoScenario::samlStart($this->tenant);
    $secret = SsoFlow::keepCookie($start['response']);
    $response = SsoScenario::samlResponse($this->tenant, $this->samlIdp, $start, ['nameId' => 'saml-subject']);

    // The browser sends no cookie with a cross-site POST.
    SsoFlow::otherBrowser();
    $acs = SsoScenario::samlAcsForm($this->tenant, $response, $start['relay_state']);
    $acs->assertStatus(303);

    $location = (string) $acs->headers->get('Location');
    expect($location)->toStartWith('https://localhost:4200/auth/sso/complete#code=')
        ->and(PersonalAccessToken::query()->count())->toBe(0);
    $code = str_replace('https://localhost:4200/auth/sso/complete#code=', '', $location);

    SsoFlow::otherBrowser('someone-elses-cookie');
    $this->withCredentials()->withHeaders(spaHeaders())->postJson('/api/v1/auth/sso/exchange', ['code' => $code])->assertUnauthorized();

    // That attempt spent the code.
    SsoFlow::otherBrowser($secret);
    $this->withCredentials()->withHeaders(spaHeaders())->postJson('/api/v1/auth/sso/exchange', ['code' => $code])->assertUnauthorized();
});

test('SAML: the starting browser completes the cookie flow end to end, without a bearer token path', function (): void {
    $start = SsoScenario::samlStart($this->tenant);
    $secret = SsoFlow::keepCookie($start['response']);
    $response = SsoScenario::samlResponse($this->tenant, $this->samlIdp, $start, ['nameId' => 'saml-subject']);

    SsoFlow::otherBrowser();
    $code = str_replace('https://localhost:4200/auth/sso/complete#code=', '', (string) SsoScenario::samlAcsForm($this->tenant, $response, $start['relay_state'])->assertStatus(303)->headers->get('Location'));

    SsoFlow::otherBrowser($secret);
    $this->withCredentials()->withHeaders(spaHeaders())->postJson('/api/v1/auth/sso/exchange', ['code' => $code])
        ->assertOk()
        ->assertJsonPath('data.auth_mode', 'cookie')
        ->assertJsonPath('data.active_tenant.id', $this->tenant->id)
        ->assertJsonMissingPath('data.token');

    expect(PersonalAccessToken::query()->count())->toBe(0)
        ->and(DB::table('sso_identities')->whereNotNull('saml_provider_id')->count())->toBe(1);
});

test('SAML: a JSON client of the ACS gets the code, never a token; token_mode is a 422 while the mode is off', function (): void {
    $start = SsoScenario::samlStart($this->tenant);
    $response = SsoScenario::samlResponse($this->tenant, $this->samlIdp, $start);

    $this->postJson('/api/v1/auth/saml/'.$this->tenant->id.'/acs', ['SAMLResponse' => $response, 'RelayState' => $start['relay_state'], 'token_mode' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('token_mode');

    $this->postJson('/api/v1/auth/saml/'.$this->tenant->id.'/acs', ['SAMLResponse' => $response, 'RelayState' => $start['relay_state']])
        ->assertOk()->assertJsonMissingPath('data.token')->assertJsonStructure(['data' => ['sign_in_code', 'expires_in']]);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('SAML: the SPA relaying the posted values from another browser is refused', function (): void {
    $start = SsoScenario::samlStart($this->tenant);
    $response = SsoScenario::samlResponse($this->tenant, $this->samlIdp, $start);

    SsoFlow::otherBrowser();
    $this->withHeaders(spaHeaders())->postJson('/api/v1/auth/saml/'.$this->tenant->id.'/acs', ['SAMLResponse' => $response, 'RelayState' => $start['relay_state']])
        ->assertUnauthorized();

    expect(bindingFailureReason())->toBe('browser_mismatch');
});

test('a forged assertion is still refused before any code is issued, binding or not', function (): void {
    $start = SsoScenario::samlStart($this->tenant);
    $forged = SsoScenario::samlResponse($this->tenant, new SamlTestIdp, $start);

    SsoScenario::samlAcsForm($this->tenant, $forged, $start['relay_state'])->assertStatus(401);

    expect(bindingFailureReason())->toBe('assertion_invalid');
});
