<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockSerialNumber;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 15)->startOfDay());

    $this->tenant = Tenant::factory()->create(['country' => 'CH', 'default_currency' => 'CHF']);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);

    $this->consumer = Contact::factory()->create([
        'tenant_id' => $this->tenant->id,
        'country' => 'CH',
        'company_name' => null,
        'first_name' => 'Anna',
        'last_name' => 'Muster',
        'vat_number' => null,
    ]);
    $this->business = Contact::factory()->create([
        'tenant_id' => $this->tenant->id,
        'country' => 'CH',
        'company_name' => 'ACME SA',
        'vat_number' => 'CHE-123.456.789',
    ]);
});

test('CH B2C passport delivered 2025-03-10 expires 2027-03-10 under statutory_ch_24m', function () {
    $response = $this->postJson('/api/v1/esm/equipment-passports', [
        'serial_number' => 'SN-CH-B2C-1',
        'current_owner_customer_id' => $this->consumer->id,
        'delivery_date' => '2025-03-10',
        'installed_firmware_version' => '1.2.3',
        'components' => [
            ['position' => 'PSU', 'component_serial' => 'PSU-1', 'role' => 'power_supply'],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.serial_number', 'SN-CH-B2C-1')
        ->assertJsonPath('data.customer_type', 'b2c')
        ->assertJsonPath('data.jurisdiction', 'CH')
        ->assertJsonPath('data.warranty_policy_type', 'statutory_ch_24m')
        ->assertJsonPath('data.warranty_start_date', '2025-03-10')
        ->assertJsonPath('data.warranty_expiry_date', '2027-03-10')
        ->assertJsonPath('data.warranty_source', 'statutory')
        ->assertJsonPath('data.customer.name', 'Anna Muster')
        ->assertJsonPath('data.components.0.source', 'as_built');

    $this->assertDatabaseHas('transactional_outbox', [
        'tenant_id' => $this->tenant->id,
        'event_type' => 'esm.equipment_passport.registered',
        'aggregate_id' => $response->json('data.id'),
    ]);
});

test('CH B2B contract clause override shortens the warranty to 12 months', function () {
    $this->postJson('/api/v1/esm/equipment-passports', [
        'serial_number' => 'SN-CH-B2B-1',
        'current_owner_customer_id' => $this->business->id,
        'delivery_date' => '2025-03-10',
        'contract_warranty_months' => 12,
        'contract_clause_reference' => 'Framework agreement art. 7',
    ])->assertCreated()
        ->assertJsonPath('data.customer_type', 'b2b')
        ->assertJsonPath('data.warranty_expiry_date', '2026-03-10')
        ->assertJsonPath('data.warranty_source', 'contract_clause');
});

test('B2C contract clause below 24 months is rejected with 422', function () {
    $this->postJson('/api/v1/esm/equipment-passports', [
        'serial_number' => 'SN-CH-B2C-2',
        'current_owner_customer_id' => $this->consumer->id,
        'delivery_date' => '2025-03-10',
        'contract_warranty_months' => 12,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'b2c_minimum_warranty')
        ->assertJsonValidationErrors(['contract_warranty_months']);
});

test('IT B2C and IT B2B passports follow Codice del Consumo and Art. 1495 c.c.', function () {
    $this->postJson('/api/v1/esm/equipment-passports', [
        'serial_number' => 'SN-IT-B2C',
        'current_owner_customer_id' => $this->consumer->id,
        'jurisdiction' => 'IT',
        'customer_type' => 'b2c',
        'delivery_date' => '2025-03-10',
    ])->assertCreated()
        ->assertJsonPath('data.warranty_policy_type', 'statutory_it_b2c_24m')
        ->assertJsonPath('data.warranty_expiry_date', '2027-03-10');

    $this->postJson('/api/v1/esm/equipment-passports', [
        'serial_number' => 'SN-IT-B2B',
        'current_owner_customer_id' => $this->business->id,
        'jurisdiction' => 'IT',
        'delivery_date' => '2025-03-10',
    ])->assertCreated()
        ->assertJsonPath('data.warranty_policy_type', 'statutory_it_b2b_12m')
        ->assertJsonPath('data.warranty_expiry_date', '2026-03-10');
});

test('duplicate serial for the same product is rejected', function () {
    $payload = [
        'serial_number' => 'SN-DUP',
        'current_owner_customer_id' => $this->consumer->id,
        'delivery_date' => '2025-03-10',
    ];

    $this->postJson('/api/v1/esm/equipment-passports', $payload)->assertCreated();
    $this->postJson('/api/v1/esm/equipment-passports', $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'passport_exists');
});

test('an extended care pack ending after statutory expiry becomes the effective expiry', function () {
    $passportId = $this->postJson('/api/v1/esm/equipment-passports', [
        'serial_number' => 'SN-CARE',
        'current_owner_customer_id' => $this->consumer->id,
        'delivery_date' => '2025-03-10',
    ])->json('data.id');

    $this->postJson("/api/v1/esm/equipment-passports/{$passportId}/extensions", [
        'name' => 'Care Pack 12M',
        'months' => 12,
        'starts_at' => '2027-03-10',
        'reference' => 'CP-1',
        'price' => 149.90,
    ])->assertCreated()
        ->assertJsonPath('data.warranty_policy_type', 'extended_care_pack')
        ->assertJsonPath('data.warranty_source', 'extension')
        ->assertJsonPath('data.warranty_expiry_date', '2028-03-10')
        ->assertJsonPath('data.extensions.0.ends_at', '2028-03-10');
});

test('a care pack ending before delivery is rejected with 422', function () {
    $passportId = $this->postJson('/api/v1/esm/equipment-passports', [
        'serial_number' => 'SN-CARE-BAD',
        'current_owner_customer_id' => $this->consumer->id,
        'delivery_date' => '2025-03-10',
    ])->json('data.id');

    $this->postJson("/api/v1/esm/equipment-passports/{$passportId}/extensions", [
        'name' => 'Old pack',
        'months' => 1,
        'starts_at' => '2024-01-01',
    ])->assertStatus(422)
        ->assertJsonPath('code', 'extension_ends_before_delivery');
});

test('updating the contract override recomputes the persisted expiry', function () {
    $passportId = $this->postJson('/api/v1/esm/equipment-passports', [
        'serial_number' => 'SN-UPD',
        'current_owner_customer_id' => $this->business->id,
        'delivery_date' => '2025-03-10',
    ])->assertJsonPath('data.warranty_expiry_date', '2027-03-10')->json('data.id');

    $this->putJson("/api/v1/esm/equipment-passports/{$passportId}", [
        'contract_warranty_months' => 36,
        'installed_firmware_version' => '2.0.0',
    ])->assertOk()
        ->assertJsonPath('data.warranty_expiry_date', '2028-03-10')
        ->assertJsonPath('data.warranty_source', 'contract_clause')
        ->assertJsonPath('data.installed_firmware_version', '2.0.0');
});

test('lookup returns passport, warranty evaluation and prior RMAs', function () {
    $passportId = $this->postJson('/api/v1/esm/equipment-passports', [
        'serial_number' => 'SN-LOOKUP',
        'current_owner_customer_id' => $this->consumer->id,
        'delivery_date' => '2025-03-10',
    ])->json('data.id');

    $this->postJson('/api/v1/esm/rma-orders', [
        'serial_number' => 'SN-LOOKUP',
        'reported_symptom' => 'Does not power on',
    ])->assertCreated();

    $this->getJson('/api/v1/esm/equipment-passports/lookup?serial_number=SN-LOOKUP')
        ->assertOk()
        ->assertJsonPath('data.passport.id', $passportId)
        ->assertJsonPath('data.warranty.status', 'in_warranty')
        ->assertJsonPath('data.warranty.expiry_date', '2027-03-10')
        ->assertJsonPath('data.warranty.days_remaining', 115)
        ->assertJsonPath('data.warranty.policy_type', 'statutory_ch_24m')
        ->assertJsonPath('data.warranty.basis.jurisdiction', 'CH')
        ->assertJsonPath('data.warranty.basis.months', 24)
        ->assertJsonCount(1, 'data.rma_history')
        ->assertJsonPath('data.stock_hint', null);
});

test('lookup of an unknown serial returns 404 serial_not_found', function () {
    $this->getJson('/api/v1/esm/equipment-passports/lookup?serial_number=XYZ')
        ->assertNotFound()
        ->assertJsonPath('code', 'serial_not_found');
});

test('lookup of an inventory serial without passport returns 404 passport_missing with a stock hint', function () {
    $item = StockItem::factory()->serialized()->create(['tenant_id' => $this->tenant->id]);
    $stock = StockSerialNumber::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'stock_item_id' => $item->id,
        'serial_number' => 'SN-STOCK-ONLY',
        'status' => 'delivered',
    ]);

    $this->getJson('/api/v1/esm/equipment-passports/lookup?serial_number=SN-STOCK-ONLY')
        ->assertNotFound()
        ->assertJsonPath('code', 'passport_missing')
        ->assertJsonPath('stock_hint.stock_serial_number_id', $stock->id);
});

test('genealogy and listing filters work and other tenants passports are invisible', function () {
    $this->postJson('/api/v1/esm/equipment-passports', [
        'serial_number' => 'SN-GEN',
        'current_owner_customer_id' => $this->consumer->id,
        'delivery_date' => '2025-03-10',
        'components' => [['position' => 'FAN', 'component_serial' => 'FAN-1']],
    ])->assertCreated();

    $other = Tenant::factory()->create();
    $foreign = app(TenantContext::class)->runWithTenant($other, fn () => SerializedEquipmentPassport::factory()->create(['tenant_id' => $other->id]));

    $this->getJson('/api/v1/esm/equipment-passports?jurisdiction=CH&warranty=in')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.serial_number', 'SN-GEN');

    $passport = SerializedEquipmentPassport::query()->where('serial_number', 'SN-GEN')->firstOrFail();
    $this->getJson("/api/v1/esm/equipment-passports/{$passport->id}/genealogy")
        ->assertOk()
        ->assertJsonCount(1, 'data.as_built')
        ->assertJsonCount(1, 'data.as_maintained')
        ->assertJsonCount(0, 'data.history');

    $this->getJson("/api/v1/esm/equipment-passports/{$foreign->id}")->assertNotFound();
});
