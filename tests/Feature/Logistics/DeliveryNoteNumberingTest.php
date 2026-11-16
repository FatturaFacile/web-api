<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Logistics\Enums\DeliveryNoteType;
use Modules\Logistics\Services\DeliveryNoteNumberingService;
use Modules\Logistics\Services\DeliveryNoteService;

uses(RefreshDatabase::class);

test('DDT numbers are progressive per tenant, fiscal year and numbering section', function (): void {
    ['tenant' => $tenant] = logisticsTenant('Numbering A');
    app(TenantContext::class)->setTenant($tenant);
    $numbering = app(DeliveryNoteNumberingService::class);
    $year = (int) now()->format('Y');

    expect($numbering->next(DeliveryNoteType::OutboundSale)['number'])->toBe("DDT-{$year}-00001")
        ->and($numbering->next(DeliveryNoteType::OutboundSale)['number'])->toBe("DDT-{$year}-00002")
        ->and($numbering->next(DeliveryNoteType::ReturnFromCustomer)['number'])->toBe("DDT-{$year}-00003") // same section
        ->and($numbering->next(DeliveryNoteType::InternalTransfer)['number'])->toBe("DDT-TR-{$year}-00001") // own section
        ->and($numbering->next(DeliveryNoteType::SubcontractingOut)['number'])->toBe("DDT-CL-{$year}-00001")
        ->and($numbering->next(DeliveryNoteType::CustomerConsignment)['number'])->toBe("DDT-CD-{$year}-00001")
        ->and($numbering->next(DeliveryNoteType::OutboundSale, $year + 1)['number'])->toBe('DDT-'.($year + 1).'-00001');
});

test('numbering sequences are isolated between tenants', function (): void {
    ['tenant' => $a] = logisticsTenant('Tenant A');
    ['tenant' => $b] = logisticsTenant('Tenant B');
    $numbering = app(DeliveryNoteNumberingService::class);
    $year = (int) now()->format('Y');

    app(TenantContext::class)->setTenant($a);
    $numbering->next(DeliveryNoteType::OutboundSale);
    $numbering->next(DeliveryNoteType::OutboundSale);

    app(TenantContext::class)->setTenant($b);
    expect($numbering->next(DeliveryNoteType::OutboundSale)['number'])->toBe("DDT-{$year}-00001");

    app(TenantContext::class)->setTenant($a);
    expect($numbering->next(DeliveryNoteType::OutboundSale)['number'])->toBe("DDT-{$year}-00003");
});

test('creating delivery notes assigns the dedicated number and the default transport reason', function (): void {
    ['tenant' => $tenant] = logisticsTenant('Create DDT');
    app(TenantContext::class)->setTenant($tenant);
    $customer = logisticsCustomer($tenant->id);
    $product = logisticsProduct($tenant->id, 'SKU-1');

    $note = app(DeliveryNoteService::class)->create([
        'type' => 'outbound_sale',
        'contact_id' => $customer->id,
        'carriage_terms' => 'free',
        'transport_by' => 'carrier',
        'incoterms' => 'DAP',
        'packages_count' => 3,
        'gross_weight_kg' => 12.5,
    ], [['product_id' => $product->id, 'description' => 'Widget', 'quantity' => 2, 'unit_price' => 25, 'tax_rate' => 22]]);

    expect($note->ddt_number)->toBe('DDT-'.now()->format('Y').'-00001')
        ->and($note->sequential_number)->toBe(1)
        ->and($note->status)->toBe('draft')
        ->and($note->transport_reason_code)->toBe('sale')
        ->and($note->transport_reason)->toBe('Vendita')
        ->and($note->items)->toHaveCount(1);
});

test('delivery note API isolates tenants and enforces permissions', function (): void {
    ['tenant' => $a, 'user' => $userA] = logisticsTenant('API A');
    ['tenant' => $b, 'user' => $userB] = logisticsTenant('API B');

    app(TenantContext::class)->setTenant($a);
    $customerA = logisticsCustomer($a->id);
    $noteA = app(DeliveryNoteService::class)->create(['type' => 'outbound_sale', 'contact_id' => $customerA->id], [['description' => 'Item', 'quantity' => 1]]);
    app(TenantContext::class)->clear();

    // Tenant B cannot see tenant A documents
    Sanctum::actingAs($userB);
    $this->withHeader('X-Tenant-Id', $b->id)->getJson("/api/v1/logistics/delivery-notes/{$noteA->id}")->assertNotFound();
    $this->withHeader('X-Tenant-Id', $b->id)->getJson('/api/v1/logistics/delivery-notes')->assertOk()->assertJsonCount(0, 'data');

    // Tenant A owner sees it
    Sanctum::actingAs($userA);
    $this->withHeader('X-Tenant-Id', $a->id)->getJson('/api/v1/logistics/delivery-notes')->assertOk()->assertJsonCount(1, 'data');
});

test('granular permissions protect every logistics endpoint', function (): void {
    ['tenant' => $tenant, 'user' => $viewer] = logisticsTenant('RBAC', 'member', ['delivery_notes.view']);

    Sanctum::actingAs($viewer);
    $headers = ['X-Tenant-Id' => $tenant->id];

    $this->getJson('/api/v1/logistics/delivery-notes', $headers)->assertOk();
    $this->postJson('/api/v1/logistics/delivery-notes', [], $headers)->assertForbidden()->assertJsonPath('required_permission', 'delivery_notes.create');
    $this->postJson('/api/v1/logistics/delivery-notes/'.Str::uuid().'/issue', [], $headers)->assertForbidden();
    $this->postJson('/api/v1/logistics/delivery-notes/'.Str::uuid().'/cancel', [], $headers)->assertForbidden();
    $this->getJson('/api/v1/logistics/transfers', $headers)->assertForbidden();
    $this->postJson('/api/v1/logistics/consignment/consumption-reports', [], $headers)->assertForbidden();
    $this->getJson('/api/v1/logistics/consignment/ledger', $headers)->assertOk();

    // Wildcard permission grants everything
    ['tenant' => $t2, 'user' => $manager] = logisticsTenant('RBAC wildcard', 'member', ['logistics.*']);
    Sanctum::actingAs($manager);
    $this->getJson('/api/v1/logistics/transfers', ['X-Tenant-Id' => $t2->id])->assertOk();
});
