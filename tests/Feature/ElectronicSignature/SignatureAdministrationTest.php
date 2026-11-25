<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\ElectronicSignature\Enums\SignatureLevel;
use Modules\ElectronicSignature\Models\SignatureProviderConfig;
use Modules\ElectronicSignature\Services\SignaturePolicyResolver;

require_once __DIR__.'/ElectronicSignatureSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    esigSetUp($this);
});

it('requires authentication on the staff endpoints', function (): void {
    $this->refreshApplication();

    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id)->getJson('/api/v1/signatures/envelopes')->assertUnauthorized();
});

it('lets every member follow the signatures but keeps the provider settings to owners and administrators', function (string $role, int $read, int $configure): void {
    treasuryActor($this, $role);

    $this->getJson('/api/v1/signatures/envelopes')->assertStatus($read);
    $this->getJson('/api/v1/signatures/dashboard')->assertStatus($read);
    $this->postJson('/api/v1/signatures/providers', ['provider' => 'infocert', 'jurisdiction' => 'EU', 'name' => 'InfoCert', 'is_sandbox' => true, 'is_active' => true, 'is_default' => false])
        ->assertStatus($configure);
})->with([
    'owner' => ['owner', 200, 201],
    'admin' => ['admin', 200, 201],
    'accountant' => ['accountant', 200, 403],
    'plain member' => ['member', 200, 403],
]);

it('encrypts the provider credentials at rest and never returns them', function (): void {
    $response = $this->postJson('/api/v1/signatures/providers', [
        'provider' => 'infocert',
        'jurisdiction' => 'EU',
        'name' => 'InfoCert production',
        'is_sandbox' => false,
        'is_active' => true,
        'is_default' => true,
        'credentials' => ['client_id' => 'client-123', 'client_secret' => 'super-secret-value', 'webhook_secret' => 'whsec-top-secret'],
    ])->assertCreated();

    expect($response->getContent())->not->toContain('super-secret-value')->not->toContain('whsec-top-secret')
        ->and($response->json('data.configured_credentials'))->toContain('client_secret');

    $stored = (string) DB::table('signature_provider_configs')->where('id', $response->json('data.id'))->value('credentials');

    expect($stored)->not->toContain('super-secret-value')->not->toContain('client-123');

    $config = SignatureProviderConfig::query()->findOrFail($response->json('data.id'));

    expect($config->credential('client_secret'))->toBe('super-secret-value');
});

it('keeps a credential that an update leaves empty', function (): void {
    $id = $this->postJson('/api/v1/signatures/providers', [
        'provider' => 'namirial', 'jurisdiction' => 'EU', 'name' => 'Namirial', 'is_sandbox' => true, 'is_active' => true, 'is_default' => false,
        'credentials' => ['api_key' => 'key-one', 'webhook_secret' => 'secret-one'],
    ])->assertCreated()->json('data.id');

    $this->putJson("/api/v1/signatures/providers/{$id}", [
        'name' => 'Namirial renamed', 'jurisdiction' => 'EU', 'is_sandbox' => true, 'is_active' => true, 'is_default' => false,
        'credentials' => ['webhook_secret' => 'secret-two'],
    ])->assertOk();

    $config = SignatureProviderConfig::query()->findOrFail($id);

    expect($config->credential('api_key'))->toBe('key-one')
        ->and($config->credential('webhook_secret'))->toBe('secret-two');
});

it('refuses a provider settings URL that is not https', function (): void {
    $this->postJson('/api/v1/signatures/providers', [
        'provider' => 'swisscom', 'jurisdiction' => 'CH', 'name' => 'Swisscom', 'is_sandbox' => true, 'is_active' => true, 'is_default' => false,
        'settings' => ['base_url' => 'http://insecure.example.com'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['settings.base_url']);
});

it('tests the connection of the sandbox native engine without calling any service', function (): void {
    $id = $this->postJson('/api/v1/signatures/providers', [
        'provider' => 'native', 'jurisdiction' => 'BOTH', 'name' => 'Native', 'is_sandbox' => true, 'is_active' => true, 'is_default' => true,
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/signatures/providers/{$id}/test")->assertOk();

    expect(SignatureProviderConfig::query()->findOrFail($id)->last_test_status)->not->toBeNull();
});

it('keeps the providers and the envelopes of a company away from the others', function (): void {
    $providerId = $this->postJson('/api/v1/signatures/providers', [
        'provider' => 'native', 'jurisdiction' => 'BOTH', 'name' => 'Native', 'is_sandbox' => true, 'is_active' => true, 'is_default' => false,
    ])->assertCreated()->json('data.id');
    $envelopeId = esigCreate($this)->assertCreated()->json('data.id');

    treasuryActor($this, 'owner', $this->otherTenant);

    $this->getJson("/api/v1/signatures/envelopes/{$envelopeId}")->assertNotFound();
    $this->putJson("/api/v1/signatures/providers/{$providerId}", ['name' => 'Hijacked', 'jurisdiction' => 'BOTH', 'is_sandbox' => true, 'is_active' => true, 'is_default' => false])->assertNotFound();
    expect($this->getJson('/api/v1/signatures/envelopes')->json('data'))->toBeEmpty();
});

it('raises the minimum level with the document type and the amount', function (): void {
    $policy = app(SignaturePolicyResolver::class);

    expect($policy->minimumLevel('quote', '100'))->toBe(SignatureLevel::Fes)
        ->and($policy->minimumLevel('purchase_order', '1000'))->toBe(SignatureLevel::Fes)
        ->and($policy->minimumLevel('purchase_order', '6000'))->toBe(SignatureLevel::Fea)
        ->and($policy->minimumLevel('purchase_order', '30000'))->toBe(SignatureLevel::Feq)
        ->and($policy->minimumLevel('employment_contract', null))->toBe(SignatureLevel::Feq);
});

it('lets a company rule add a threshold and exposes the minimum through the API', function (): void {
    $this->postJson('/api/v1/signatures/templates', [
        'name' => 'Large quotes', 'document_type' => 'quote', 'required_level' => 'FEA', 'jurisdiction' => 'eIDAS', 'routing_mode' => 'sequential',
        'expiration_days' => 14, 'min_amount' => '10000', 'amount_currency' => 'CHF', 'is_active' => true,
    ])->assertCreated();

    $this->getJson('/api/v1/signatures/policy?document_type=quote&amount=500')->assertOk()->assertJsonPath('data.minimum_level', 'FES');
    $this->getJson('/api/v1/signatures/policy?document_type=quote&amount=12000')->assertOk()->assertJsonPath('data.minimum_level', 'FEA');
});

it('refuses a level below the policy of the document type', function (): void {
    $this->postJson('/api/v1/signatures/templates', [
        'name' => 'Uploads need FEA', 'document_type' => 'upload', 'required_level' => 'FEA', 'jurisdiction' => 'eIDAS', 'routing_mode' => 'parallel',
        'expiration_days' => 14, 'is_active' => true,
    ])->assertCreated();

    esigCreate($this, [
        ['full_name' => 'Anna Rossi', 'email' => 'anna@example.com', 'phone' => '+41791234567', 'role' => 'SIGNER', 'signing_order' => 1, 'auth_method' => 'sms_otp'],
    ], ['required_level' => 'FES'])->assertStatus(422)->assertJsonPath('code', 'level_below_policy');
});

it('does not let a duplicate recipient or a request without signers through', function (): void {
    esigCreate($this, [
        ['full_name' => 'Anna Rossi', 'email' => 'anna@example.com', 'role' => 'SIGNER', 'signing_order' => 1, 'auth_method' => 'email_link'],
        ['full_name' => 'Anna Rossi', 'email' => 'ANNA@example.com', 'role' => 'SIGNER', 'signing_order' => 2, 'auth_method' => 'email_link'],
    ])->assertStatus(422)->assertJsonPath('code', 'duplicate_recipient');

    esigCreate($this, [
        ['full_name' => 'Carla Verdi', 'email' => 'carla@example.com', 'role' => 'CC_RECIPIENT', 'signing_order' => 1, 'auth_method' => 'email_link'],
    ])->assertStatus(422)->assertJsonPath('code', 'no_signer');
});
