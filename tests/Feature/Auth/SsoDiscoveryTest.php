<?php

declare(strict_types=1);

use App\Models\SsoProvider;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\SamlTestIdp;
use Tests\Support\SsoScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['sso.discovery_min_milliseconds' => 0]);
    $this->oidcTenant = Tenant::factory()->create();
    $this->oidc = SsoProvider::factory()->create(['tenant_id' => $this->oidcTenant->id, 'allowed_email_domains' => ['acme.example']]);
    $this->samlTenant = Tenant::factory()->create();
    SsoScenario::samlProvider($this->samlTenant, new SamlTestIdp, ['allowed_email_domains' => ['saml.example']]);
});

function discover(array $payload): TestResponse
{
    return test()->postJson('/api/v1/auth/sso/discover', $payload);
}

test('an email of a domain with an OIDC provider says to start the OIDC flow of that tenant', function (): void {
    discover(['email' => 'anna.rossi@acme.example'])
        ->assertOk()
        ->assertJsonPath('data.sso.available', true)
        ->assertJsonPath('data.sso.protocol', 'oidc')
        ->assertJsonPath('data.sso.tenant_id', $this->oidcTenant->id)
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('a domain with a SAML provider says to start the SAML flow, whether an email or a domain is given', function (): void {
    foreach ([['email' => 'x@SAML.example'], ['domain' => ' saml.example ']] as $payload) {
        discover($payload)->assertOk()->assertJsonPath('data.sso.protocol', 'saml')->assertJsonPath('data.sso.tenant_id', $this->samlTenant->id);
    }
});

test('only the domain matters: the local part is never looked at', function (): void {
    $known = discover(['email' => 'anna.rossi@acme.example'])->json();
    $unknownUser = discover(['email' => 'nobody.at.all@acme.example'])->json();

    expect($unknownUser)->toBe($known);
});

test('an unknown domain, a domain without SSO and a provider that is not ready answer exactly the same', function (): void {
    $disabledTenant = Tenant::factory()->create();
    SsoProvider::factory()->create(['tenant_id' => $disabledTenant->id, 'allowed_email_domains' => ['disabled.example'], 'enabled' => false]);
    $incompleteTenant = Tenant::factory()->create();
    SsoProvider::factory()->create(['tenant_id' => $incompleteTenant->id, 'allowed_email_domains' => ['incomplete.example'], 'jwks_uri' => null]);

    $answers = collect(['nobody-knows.example', 'gmail.com', 'disabled.example', 'incomplete.example'])
        ->map(fn (string $domain): string => (string) discover(['email' => "anna@{$domain}"])->assertOk()->getContent());

    expect($answers->unique())->toHaveCount(1)
        ->and(json_decode($answers->first(), true)['data']['sso'])->toBe(['available' => false, 'protocol' => null, 'tenant_id' => null]);
});

test('a domain claimed by several tenants is ambiguous and is answered as unavailable', function (): void {
    $other = Tenant::factory()->create();
    SsoProvider::factory()->create(['tenant_id' => $other->id, 'allowed_email_domains' => ['acme.example']]);

    discover(['email' => 'anna@acme.example'])->assertOk()->assertJsonPath('data.sso.available', false)->assertJsonPath('data.sso.tenant_id', null);
});

test('the answer carries nothing but the protocol and the tenant id', function (): void {
    $body = (string) discover(['email' => 'anna@acme.example'])->getContent();

    expect($body)->not->toContain($this->oidc->issuer)
        ->and($body)->not->toContain($this->oidc->name)
        ->and($body)->not->toContain($this->oidc->client_id)
        ->and($body)->not->toContain('idp.example.com');
});

test('a malformed input is a 422 whatever the tenants are', function (): void {
    discover([])->assertUnprocessable();
    discover(['email' => 'not-an-email'])->assertUnprocessable()->assertJsonValidationErrors('email');
    discover(['domain' => 'localhost'])->assertUnprocessable()->assertJsonValidationErrors('domain');
    discover(['email' => 'a@-bad.example'])->assertUnprocessable();
});

test('the call takes the same minimum time for a domain with SSO and for one without', function (): void {
    config(['sso.discovery_min_milliseconds' => 80]);

    $time = function (string $email): float {
        $start = hrtime(true);
        discover(['email' => $email])->assertOk();

        return (hrtime(true) - $start) / 1_000_000;
    };

    expect($time('anna@acme.example'))->toBeGreaterThanOrEqual(80.0)
        ->and($time('anna@unknown.example'))->toBeGreaterThanOrEqual(80.0);
});

test('the endpoint is rate limited per client address', function (): void {
    for ($call = 0; $call < 20; $call++) {
        discover(['email' => "user{$call}@acme.example"])->assertOk();
    }

    discover(['email' => 'one.more@acme.example'])->assertStatus(429);
});
