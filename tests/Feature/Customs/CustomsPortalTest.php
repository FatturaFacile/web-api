<?php

declare(strict_types=1);

use App\Models\PortalActivityLog;
use App\Services\Portal\PortalTokenService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Customs\Models\CustomsShipmentRequest;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = customsTenant();
    $this->customer = customsContact($this->tenant);
    $this->user = customsMember($this->tenant);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->token = app(PortalTokenService::class)->createToken($this->customer)['token'];
    app(TenantContext::class)->clear();

    $this->payload = ['service_type' => 'road', 'direction' => 'import', 'origin_country' => 'IT', 'destination_country' => 'CH', 'gross_weight_kg' => 50, 'packages' => 2];
});

afterEach(fn () => app(TenantContext::class)->clear());

test('a customer asks for a shipment through the magic link and the forwarder opens the dossier', function (): void {
    $this->postJson("/api/v1/portal/customs/{$this->token}/shipment-requests", $this->payload)->assertCreated()->assertJsonPath('data.status', 'pending');
    app(TenantContext::class)->clear();

    expect(PortalActivityLog::withoutGlobalScopes()->where('action', 'customs_shipment_requested')->count())->toBe(1);

    $id = CustomsShipmentRequest::withoutGlobalScopes()->sole()->id;
    customsApi($this->user, $this->tenant);
    $this->getJson('/api/v1/customs/shipment-requests?status=pending')->assertOk()->assertJsonCount(1, 'data');

    $dossier = $this->postJson("/api/v1/customs/shipment-requests/{$id}/accept", ['currency' => 'CHF'])->assertOk()
        ->assertJsonPath('data.customer_contact_id', $this->customer->id)
        ->assertJsonPath('data.status', 'open');

    $this->postJson("/api/v1/customs/shipment-requests/{$id}/accept", ['currency' => 'CHF'])->assertUnprocessable();
    $this->postJson("/api/v1/customs/shipment-requests/{$id}/refuse")->assertUnprocessable();

    app(TenantContext::class)->clear();
    $rows = $this->getJson("/api/v1/portal/customs/{$this->token}/dossiers")->assertOk()->json('data');

    expect($rows)->toHaveCount(1)->and($rows[0]['id'])->toBe($dossier->json('data.id'))->and($rows[0])->not->toHaveKey('notes');
});

test('a request is validated', function (): void {
    $this->postJson("/api/v1/portal/customs/{$this->token}/shipment-requests", [...$this->payload, 'gross_weight_kg' => 0])->assertUnprocessable();
});

test('a customer sees only its own', function (): void {
    $other = customsContact($this->tenant, 'Altro');
    app(TenantContext::class)->setTenant($this->tenant);
    $otherToken = app(PortalTokenService::class)->createToken($other)['token'];
    app(TenantContext::class)->clear();

    $this->postJson("/api/v1/portal/customs/{$this->token}/shipment-requests", $this->payload)->assertCreated();
    app(TenantContext::class)->clear();

    $this->getJson("/api/v1/portal/customs/{$otherToken}/shipment-requests")->assertOk()->assertJsonCount(0, 'data');
    app(TenantContext::class)->clear();
    $this->getJson("/api/v1/portal/customs/{$this->token}/shipment-requests")->assertOk()->assertJsonCount(1, 'data');
});

test('bad, expired and narrow links do not open it', function (): void {
    $this->getJson('/api/v1/portal/customs/nothing/dossiers')->assertNotFound();

    app(TenantContext::class)->setTenant($this->tenant);
    $expired = app(PortalTokenService::class)->createToken($this->customer, expiresAt: now()->subDay())['token'];
    $narrow = app(PortalTokenService::class)->createToken($this->customer, 'quote')['token'];
    app(TenantContext::class)->clear();

    $this->getJson("/api/v1/portal/customs/{$expired}/dossiers")->assertStatus(410);
    app(TenantContext::class)->clear();
    $this->getJson("/api/v1/portal/customs/{$narrow}/dossiers")->assertForbidden();
});

test('the customer never picks the contact', function (): void {
    $other = customsContact($this->tenant, 'Altro');

    $this->postJson("/api/v1/portal/customs/{$this->token}/shipment-requests", [...$this->payload, 'contact_id' => $other->id])->assertCreated()
        ->assertJsonPath('data.contact_id', $this->customer->id);
});
