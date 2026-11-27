<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Modules\SwissEbill\Models\EbillSetting;

require_once __DIR__.'/EbillSupport.php';

uses(RefreshDatabase::class);

const EBILL_SETTINGS_URL = '/api/v1/swiss-ebill/settings';

test('the settings start disabled on the sandbox with a webhook address', function (): void {
    ebSetUp($this, 'accountant', false);

    $this->getJson(EBILL_SETTINGS_URL)->assertOk()
        ->assertJsonPath('data.ebill_enabled', false)
        ->assertJsonPath('data.provider', 'sandbox_simulator')
        ->assertJsonPath('data.channel_protocol', 'rest_json_xml')
        ->assertJsonPath('data.attach_courtesy_pdf', true)
        ->assertJsonPath('data.connection_status', 'untested')
        ->assertJsonPath('data.has_webhook_secret', false);

    expect($this->getJson(EBILL_SETTINGS_URL)->json('data.webhook_url'))->toContain('/api/v1/webhooks/swiss-ebill/');
});

test('saving stores the configuration and never returns a secret', function (): void {
    ebSetUp($this, 'accountant', false);

    $response = $this->putJson(EBILL_SETTINGS_URL, [
        'ebill_enabled' => true, 'biller_id' => ebBillerId(), 'provider' => 'six_paynet_direct', 'channel_protocol' => 'soap_ebinterface',
        'api_base_url' => 'https://ebill.example.test/api', 'client_id' => 'client-1', 'client_secret' => 'super-secret-value',
        'private_key_pem' => "-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----", 'signing_certificate_pem' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----",
        'auto_send_on_invoice_issue' => true, 'attach_courtesy_pdf' => false, 'default_ebinterface_version' => 'ebInterface 6.1',
    ])->assertOk()
        ->assertJsonPath('data.ebill_enabled', true)
        ->assertJsonPath('data.provider', 'six_paynet_direct')
        ->assertJsonPath('data.channel_protocol', 'soap_ebinterface')
        ->assertJsonPath('data.has_client_secret', true)
        ->assertJsonPath('data.has_private_key', true)
        ->assertJsonPath('data.has_signing_certificate', true)
        ->assertJsonPath('data.has_webhook_secret', true)
        ->assertJsonPath('data.auto_send_on_invoice_issue', true)
        ->assertJsonPath('data.attach_courtesy_pdf', false);

    expect($response->getContent())->not->toContain('super-secret-value')->and($response->getContent())->not->toContain('PRIVATE KEY');
});

test('secrets are encrypted at rest', function (): void {
    ebSetUp($this, 'accountant', false);
    $this->putJson(EBILL_SETTINGS_URL, ['ebill_enabled' => false, 'client_secret' => 'plain-client-secret', 'webhook_secret' => 'plain-webhook-secret-123'])->assertOk();

    $row = DB::table('ebill_settings')->first();

    expect($row->client_secret)->not->toBe('plain-client-secret')
        ->and(Crypt::decryptString($row->client_secret))->toBe('plain-client-secret')
        ->and($row->webhook_secret)->not->toBe('plain-webhook-secret-123')
        ->and(EbillSetting::query()->first()->webhook_secret)->toBe('plain-webhook-secret-123');
});

test('a secret left out keeps the stored one', function (): void {
    ebSetUp($this, 'accountant', false);
    $this->putJson(EBILL_SETTINGS_URL, ['client_secret' => 'keep-me'])->assertOk();
    $this->putJson(EBILL_SETTINGS_URL, ['client_secret' => '', 'client_id' => 'changed'])->assertOk();

    expect(EbillSetting::query()->first()->client_secret)->toBe('keep-me')->and(EbillSetting::query()->first()->client_id)->toBe('changed');
});

test('the biller id is checked when the channel is enabled', function (): void {
    ebSetUp($this, 'accountant', false);

    $this->putJson(EBILL_SETTINGS_URL, ['ebill_enabled' => true])->assertStatus(422);
    $this->putJson(EBILL_SETTINGS_URL, ['ebill_enabled' => true, 'biller_id' => '12345678901234567'])->assertStatus(422);
    $this->putJson(EBILL_SETTINGS_URL, ['ebill_enabled' => true, 'biller_id' => 'abc'])->assertStatus(422);
    $this->putJson(EBILL_SETTINGS_URL, ['ebill_enabled' => true, 'biller_id' => ebBillerId()])->assertOk();
});

test('a real provider needs a https gateway address, the sandbox does not', function (): void {
    ebSetUp($this, 'accountant', false);

    $this->putJson(EBILL_SETTINGS_URL, ['ebill_enabled' => true, 'biller_id' => ebBillerId(), 'provider' => 'postfinance_ebill'])->assertStatus(422);
    $this->putJson(EBILL_SETTINGS_URL, ['ebill_enabled' => true, 'biller_id' => ebBillerId(), 'provider' => 'postfinance_ebill', 'api_base_url' => 'http://insecure.example.test'])->assertStatus(422);
    $this->putJson(EBILL_SETTINGS_URL, ['ebill_enabled' => true, 'biller_id' => ebBillerId(), 'provider' => 'postfinance_ebill', 'api_base_url' => 'https://ebill.example.test'])->assertOk();
    $this->putJson(EBILL_SETTINGS_URL, ['ebill_enabled' => true, 'biller_id' => ebBillerId(), 'provider' => 'sandbox_simulator'])->assertOk();
});

test('the pem fields only take certificates and keys', function (): void {
    ebSetUp($this, 'accountant', false);

    $this->putJson(EBILL_SETTINGS_URL, ['private_key_pem' => 'not a key'])->assertStatus(422);
    $this->putJson(EBILL_SETTINGS_URL, ['signing_certificate_pem' => 'not a certificate'])->assertStatus(422);
    $this->putJson(EBILL_SETTINGS_URL, ['webhook_secret' => 'short'])->assertStatus(422);
    $this->putJson(EBILL_SETTINGS_URL, ['webhook_auth_mode' => 'basic'])->assertStatus(422);
});

test('the connection test reports reachability and latency and stores the verdict', function (): void {
    ebSetUp($this);

    $this->postJson(EBILL_SETTINGS_URL.'/test-connection')->assertOk()
        ->assertJsonPath('data.result.reachable', true)
        ->assertJsonPath('data.result.authenticated', true)
        ->assertJsonPath('data.result.environment', 'sandbox')
        ->assertJsonPath('data.settings.connection_status', 'connected');

    $setting = EbillSetting::query()->first();

    expect($setting->last_connection_tested_at)->not->toBeNull()->and($setting->last_connection_latency_ms)->toBe(12);
});

test('changing the connection invalidates the last test', function (): void {
    ebSetUp($this);
    $this->postJson(EBILL_SETTINGS_URL.'/test-connection')->assertOk();

    $this->putJson(EBILL_SETTINGS_URL, ['api_base_url' => 'https://other.example.test'])->assertOk()->assertJsonPath('data.connection_status', 'untested');
});

test('rotating the webhook secret returns the new one once', function (): void {
    ebSetUp($this);
    $old = EbillSetting::query()->first()->webhook_secret;

    $rotated = $this->postJson(EBILL_SETTINGS_URL.'/rotate-webhook-secret')->assertOk();
    $secret = $rotated->json('data.webhook_secret');

    expect($secret)->toHaveLength(48)->and($secret)->not->toBe($old)->and(EbillSetting::query()->first()->webhook_secret)->toBe($secret);

    $this->getJson(EBILL_SETTINGS_URL)->assertOk()->assertJsonPath('data.webhook_secret', null)->assertJsonPath('data.has_webhook_secret', true);
});

test('each company sees its own settings only', function (): void {
    ebSetUp($this);
    $slug = EbillSetting::query()->first()->webhook_slug;

    app(TenantContext::class)->setTenant($this->otherTenant);
    $other = ebSettings(['biller_id' => ebBillerId()]);
    app(TenantContext::class)->setTenant($this->tenant);

    expect($other->webhook_slug)->not->toBe($slug)->and(EbillSetting::query()->count())->toBe(1);
    $this->getJson(EBILL_SETTINGS_URL)->assertOk()->assertJsonPath('data.webhook_url', url('/api/v1/webhooks/swiss-ebill/'.$slug));
});

test('only roles with module access can read, and only finance can write', function (): void {
    ebSetUp($this, 'technician', false);
    $this->getJson(EBILL_SETTINGS_URL)->assertForbidden();

    ebSetUp($this, 'sales', false);
    $this->getJson(EBILL_SETTINGS_URL)->assertOk();
    $this->putJson(EBILL_SETTINGS_URL, ['client_id' => 'x'])->assertForbidden();
    $this->postJson(EBILL_SETTINGS_URL.'/test-connection')->assertForbidden();
});

test('the webhook slug is unguessable and unique', function (): void {
    ebSetUp($this);
    app(TenantContext::class)->setTenant($this->otherTenant);
    $other = ebSettings();

    expect(strlen($other->webhook_slug))->toBeGreaterThanOrEqual(32)->and($other->webhook_slug)->not->toBe($this->setting->webhook_slug);
});
