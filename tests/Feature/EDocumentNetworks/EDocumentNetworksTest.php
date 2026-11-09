<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\EDocumentNetworks\DTOs\EDocumentEnvelope;
use Modules\EDocumentNetworks\Enums\Network;
use Modules\EDocumentNetworks\Exceptions\NetworkNotAvailableException;
use Modules\EDocumentNetworks\Gateways\LocalGateway;
use Modules\EDocumentNetworks\Models\EDocumentTransmission;

uses(RefreshDatabase::class);

function networkActAs(Tenant $tenant, string $role = 'accountant'): void
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

function networkPayload(): array
{
    return ['document_reference' => 'INV-1', 'recipient_identifier' => '41100000000000', 'payload_base64' => base64_encode('<doc/>')];
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    networkActAs($this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('by default every network refuses and nothing is recorded as sent', function (string $network): void {
    $this->postJson("/api/v1/edocument-networks/{$network}/transmissions", networkPayload())->assertServiceUnavailable();

    expect(EDocumentTransmission::query()->count())->toBe(0);
})->with(['ebill', 'peppol', 'nso']);

test('the list says that no network is available', function (): void {
    $networks = collect($this->getJson('/api/v1/edocument-networks')->assertOk()->json('data'));

    expect($networks->pluck('network')->all())->toBe(['ebill', 'peppol', 'nso'])
        ->and($networks->pluck('driver')->unique()->all())->toBe(['refused'])
        ->and($networks->pluck('is_available')->unique()->all())->toBe([false]);
});

test('the local driver of each network simulates a delivery and says so', function (string $network): void {
    config(["edocument_networks.drivers.{$network}" => 'local']);

    $this->postJson("/api/v1/edocument-networks/{$network}/transmissions", networkPayload())->assertCreated()
        ->assertJsonPath('data.status', 'simulated')
        ->assertJsonPath('data.driver', "local-{$network}")
        ->assertJsonPath('data.payload_sha256', hash('sha256', '<doc/>'));
})->with(['ebill', 'peppol', 'nso']);

test('a simulator never runs in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $gateway = new LocalGateway(Network::Peppol);

    expect($gateway->isAvailable())->toBeFalse();
    expect(fn () => $gateway->send($this->tenant, new EDocumentEnvelope('a', 'b', 'c')))->toThrow(NetworkNotAvailableException::class);
});

test('a payload must be valid base64', function (): void {
    config(['edocument_networks.drivers.peppol' => 'local']);

    $this->postJson('/api/v1/edocument-networks/peppol/transmissions', [...networkPayload(), 'payload_base64' => '%%%'])->assertUnprocessable();
});

test('only finance roles reach the networks, and transmissions stay in their tenant', function (): void {
    config(['edocument_networks.drivers.peppol' => 'local']);
    $this->postJson('/api/v1/edocument-networks/peppol/transmissions', networkPayload())->assertCreated();

    networkActAs($this->tenant, 'member');
    $this->getJson('/api/v1/edocument-networks')->assertForbidden();
    $this->postJson('/api/v1/edocument-networks/peppol/transmissions', networkPayload())->assertForbidden();

    networkActAs(Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']), 'admin');
    $this->getJson('/api/v1/edocument-networks/transmissions')->assertOk()->assertJsonPath('data', []);
});

test('the same document is not handed to a network twice, a changed one is', function (): void {
    config(['edocument_networks.drivers.peppol' => 'local', 'edocument_networks.drivers.ebill' => 'local']);

    $this->postJson('/api/v1/edocument-networks/peppol/transmissions', networkPayload())->assertCreated();
    $this->postJson('/api/v1/edocument-networks/peppol/transmissions', networkPayload())->assertStatus(409);
    $this->postJson('/api/v1/edocument-networks/peppol/transmissions', [...networkPayload(), 'payload_base64' => base64_encode('<doc v="2"/>')])->assertCreated();
    $this->postJson('/api/v1/edocument-networks/ebill/transmissions', networkPayload())->assertCreated();

    expect(EDocumentTransmission::query()->count())->toBe(3);
});

test('transmissions are listed with filters and pagination and shown one by one within the tenant', function (): void {
    config(['edocument_networks.drivers.peppol' => 'local', 'edocument_networks.drivers.ebill' => 'local']);
    $first = $this->postJson('/api/v1/edocument-networks/peppol/transmissions', networkPayload())->assertCreated()->json('data');
    $this->postJson('/api/v1/edocument-networks/ebill/transmissions', [...networkPayload(), 'document_reference' => 'INV-2'])->assertCreated();

    $this->getJson('/api/v1/edocument-networks/transmissions?network=peppol')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/edocument-networks/transmissions?document_reference=INV-2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.network', 'ebill');
    $this->getJson('/api/v1/edocument-networks/transmissions?status=simulated&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 2);
    $this->getJson('/api/v1/edocument-networks/transmissions?network=unknown')->assertUnprocessable()->assertJsonValidationErrors('network');

    $this->getJson("/api/v1/edocument-networks/transmissions/{$first['id']}")->assertOk()->assertJsonPath('data.document_reference', 'INV-1');
    $this->getJson('/api/v1/edocument-networks/transmissions/'.Str::uuid())->assertNotFound();

    networkActAs(Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']), 'admin');
    $this->getJson("/api/v1/edocument-networks/transmissions/{$first['id']}")->assertNotFound();

    networkActAs($this->tenant, 'member');
    $this->getJson("/api/v1/edocument-networks/transmissions/{$first['id']}")->assertForbidden();
});
