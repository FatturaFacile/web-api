<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Services\Portal\PortalTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    $this->customer = Contact::create(['company_name' => 'Cliente']);
    $this->other = Contact::create(['company_name' => 'Altro']);
    $this->warehouse = logisticsWarehouse(['warehouse_type' => 'consignment', 'owner_contact_id' => $this->customer->id]);
    $this->bin = logisticsBin($this->warehouse);
    $this->item = logisticsItem(['name' => 'Bobina']);
    $this->lot = logisticsLot($this->item, ['lot_number' => 'L-1']);
    logisticsQuant($this->bin, $this->item, '10', $this->lot);
    $this->token = app(PortalTokenService::class)->createToken($this->customer)['token'];
});

test('the portal shows the stock of the customer and nothing else', function (): void {
    $theirs = logisticsWarehouse(['owner_contact_id' => $this->other->id]);
    logisticsQuant(logisticsBin($theirs), logisticsItem(), '99');
    logisticsQuant(logisticsBin(logisticsWarehouse()), logisticsItem(), '99');

    $this->getJson("/api/v1/portal/stock/{$this->token}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Bobina')
        ->assertJsonPath('data.0.lot_number', 'L-1')
        ->assertJsonPath('data.0.quantity_available', '10.0000');
});

test('the history lists the movements of its warehouses', function (): void {
    logisticsUser($this->tenant);
    $other = logisticsBin($this->warehouse, bin: 'B2');
    $this->postJson('/api/v1/inventory/movements/transfer', ['stock_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 2, 'movement_type' => 'INTERNAL_TRANSFER', 'source_bin_id' => $this->bin->id, 'destination_bin_id' => $other->id, 'stock_lot_id' => $this->lot->id, 'unit_cost' => 1])->assertCreated();
    $this->postJson('/api/v1/inventory/movements/transfer', ['stock_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 1, 'movement_type' => 'SALES_DELIVERY', 'source_bin_id' => $this->bin->id, 'stock_lot_id' => $this->lot->id, 'unit_cost' => 1])->assertCreated();

    $response = $this->getJson("/api/v1/portal/stock-movements/{$this->token}")->assertOk()->assertJsonCount(2, 'data');

    expect(array_column($response->json('data'), 'direction'))->toEqualCanonicalizing(['internal', 'out']);
});

test('a customer asks for a withdrawal and approving it reserves the stock', function (): void {
    $request = $this->postJson("/api/v1/portal/movement-requests/{$this->token}", [
        'warehouse_id' => $this->warehouse->id, 'stock_item_id' => $this->item->id, 'request_type' => 'withdrawal', 'quantity' => 4, 'notes' => 'Per lunedì',
    ])->assertCreated()->assertJsonPath('status', 'pending');

    $this->getJson("/api/v1/portal/movement-requests/{$this->token}")->assertOk()->assertJsonCount(1, 'data');

    logisticsUser($this->tenant, 'warehouse');
    $this->getJson('/api/v1/inventory/portal-requests?status=pending')->assertOk()->assertJsonCount(1, 'data');

    $approved = $this->postJson("/api/v1/inventory/portal-requests/{$request->json('id')}/approve", ['decision_note' => 'Ok'])->assertOk()->assertJsonPath('status', 'approved');

    expect($approved->json('stock_reservation_id'))->not->toBeNull();
    $this->getJson("/api/v1/portal/stock/{$this->token}")->assertJsonPath('data.0.quantity_available', '6.0000');
    $this->postJson("/api/v1/inventory/portal-requests/{$request->json('id')}/reject")->assertStatus(422);
});

test('approval is refused without stock, and a rejection or a return reserves nothing', function (): void {
    logisticsUser($this->tenant);
    $ask = fn (string $type, int $quantity): string => $this->postJson("/api/v1/portal/movement-requests/{$this->token}", ['warehouse_id' => $this->warehouse->id, 'stock_item_id' => $this->item->id, 'request_type' => $type, 'quantity' => $quantity])->assertCreated()->json('id');

    $this->postJson('/api/v1/inventory/portal-requests/'.$ask('withdrawal', 50).'/approve')->assertStatus(422);
    $this->postJson('/api/v1/inventory/portal-requests/'.$ask('return', 50).'/approve')->assertOk()->assertJsonPath('stock_reservation_id', null);
    $this->postJson('/api/v1/inventory/portal-requests/'.$ask('withdrawal', 1).'/reject')->assertOk()->assertJsonPath('status', 'rejected');

    $this->getJson("/api/v1/portal/stock/{$this->token}")->assertJsonPath('data.0.quantity_reserved', '0.0000');
});

test('a customer cannot ask on a warehouse it does not own', function (): void {
    $warehouse = logisticsWarehouse(['owner_contact_id' => $this->other->id]);

    $this->postJson("/api/v1/portal/movement-requests/{$this->token}", ['warehouse_id' => $warehouse->id, 'stock_item_id' => $this->item->id, 'request_type' => 'withdrawal', 'quantity' => 1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('warehouse_id');
});

test('only a live dashboard link opens the pages', function (): void {
    $tokens = app(PortalTokenService::class);
    $scoped = $tokens->createToken($this->customer, 'invoice_view')['token'];
    $expired = $tokens->createToken($this->customer, expiresAt: now()->subDay())['token'];

    $this->getJson("/api/v1/portal/stock/{$scoped}")->assertForbidden();
    $this->getJson("/api/v1/portal/stock/{$expired}")->assertStatus(410);
    $this->getJson('/api/v1/portal/stock/nothing')->assertNotFound();
});

test('a portal link of another tenant sees its own world', function (): void {
    $other = logisticsTenant('Beta');
    $contact = Contact::create(['company_name' => 'Beta cliente']);
    $token = app(PortalTokenService::class)->createToken($contact)['token'];

    $this->getJson("/api/v1/portal/stock/{$token}")->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/portal/movement-requests/{$token}", ['warehouse_id' => $this->warehouse->id, 'stock_item_id' => $this->item->id, 'request_type' => 'withdrawal', 'quantity' => 1])->assertStatus(422);

    logisticsUser($other);
    $this->getJson('/api/v1/inventory/portal-requests')->assertOk()->assertJsonCount(0, 'data');
});

test('deciding requests needs write access to inventory', function (): void {
    $request = $this->postJson("/api/v1/portal/movement-requests/{$this->token}", ['warehouse_id' => $this->warehouse->id, 'stock_item_id' => $this->item->id, 'request_type' => 'withdrawal', 'quantity' => 1]);

    logisticsUser($this->tenant, 'sales');
    $this->postJson("/api/v1/inventory/portal-requests/{$request->json('id')}/approve")->assertForbidden();
});
