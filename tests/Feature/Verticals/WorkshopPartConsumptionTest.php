<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\StockReservation;
use Modules\Workshop\Contracts\WorkshopStockGateway;
use Modules\Workshop\Models\WorkshopPartConsumption;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-09 08:00:00');
    $this->tenant = verticalTenant();
    $this->customer = verticalContact($this->tenant);
    $this->vehicle = Vehicle::factory()->create(['tenant_id' => $this->tenant->id, 'client_id' => $this->customer->id, 'license_plate' => 'TI 123456']);
    $this->user = verticalMember($this->tenant, 'admin');
    verticalApi($this->user, $this->tenant);

    Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'MAIN', 'name' => 'Main warehouse', 'is_primary' => true]);
    $this->part = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'BRK-1', 'name' => 'Brake pads', 'cost_price' => 40, 'sale_price' => 80, 'tax_rate' => 8.1, 'track_inventory' => true]);
    $this->service = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'LAB-1', 'name' => 'Labour', 'cost_price' => 0, 'sale_price' => 120, 'tax_rate' => 8.1, 'track_inventory' => false]);
    $this->postJson('/api/v1/inventory/stock-adjustments', ['product_id' => $this->part->id, 'quantity' => 10])->assertCreated();

    $this->folder = $this->postJson('/api/v1/workshop/folders', ['vehicle_id' => $this->vehicle->id, 'kind' => 'mechanics', 'currency' => 'CHF'])->assertCreated()->json('data.id');
});

test('a part is reserved, shown as unavailable to others, and issued out of stock when fitted', function (): void {
    $this->getJson("/api/v1/workshop/parts/{$this->part->id}/availability")->assertOk()->assertJsonPath('data.tracked', true)->assertJsonPath('data.available', '10.0000');

    $consumption = $this->postJson("/api/v1/workshop/folders/{$this->folder}/parts", ['product_id' => $this->part->id, 'quantity' => '4'])->assertCreated()
        ->assertJsonPath('data.status', 'reserved')->assertJsonPath('data.quantity', '4.0000');
    $id = $consumption->json('data.id');
    expect($consumption->json('data.stock_reservation_id'))->not->toBeNull()
        ->and(StockReservation::query()->findOrFail($consumption->json('data.stock_reservation_id'))->status)->toBe('active');

    $this->getJson("/api/v1/workshop/parts/{$this->part->id}/availability")->assertJsonPath('data.available', '6.0000');
    $this->postJson("/api/v1/workshop/folders/{$this->folder}/parts", ['product_id' => $this->part->id, 'quantity' => '7'])->assertUnprocessable();

    $issued = $this->postJson("/api/v1/workshop/part-consumptions/{$id}/issue")->assertOk()->assertJsonPath('data.status', 'issued');
    expect($issued->json('data.stock_movement_id'))->not->toBeNull()
        ->and(StockReservation::query()->findOrFail($consumption->json('data.stock_reservation_id'))->status)->toBe('released')
        ->and(app(StockService::class)->quantityOf($this->part->id))->toBe(6.0);

    $this->postJson("/api/v1/workshop/part-consumptions/{$id}/issue")->assertUnprocessable();
    $this->postJson("/api/v1/workshop/part-consumptions/{$id}/release")->assertUnprocessable();
    $this->getJson("/api/v1/workshop/folders/{$this->folder}/parts")->assertOk()->assertJsonCount(1, 'data');
});

test('a released part goes back to stock and untracked products are refused', function (): void {
    $id = $this->postJson("/api/v1/workshop/folders/{$this->folder}/parts", ['product_id' => $this->part->id, 'quantity' => '3'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/workshop/part-consumptions/{$id}/release")->assertOk()->assertJsonPath('data.status', 'released');
    $this->getJson("/api/v1/workshop/parts/{$this->part->id}/availability")->assertJsonPath('data.available', '10.0000');
    $this->postJson("/api/v1/workshop/part-consumptions/{$id}/issue")->assertUnprocessable();

    $this->postJson("/api/v1/workshop/folders/{$this->folder}/parts", ['product_id' => $this->service->id, 'quantity' => '1'])->assertUnprocessable();
    $this->getJson("/api/v1/workshop/parts/{$this->service->id}/availability")->assertJsonPath('data.tracked', false);
    $this->postJson("/api/v1/workshop/folders/{$this->folder}/parts", ['product_id' => $this->part->id, 'quantity' => '0'])->assertUnprocessable()->assertJsonValidationErrors('quantity');
});

test('cancelling a folder releases its reservations but keeps issued parts', function (): void {
    $kept = $this->postJson("/api/v1/workshop/folders/{$this->folder}/parts", ['product_id' => $this->part->id, 'quantity' => '2'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/workshop/part-consumptions/{$kept}/issue")->assertOk();
    $open = $this->postJson("/api/v1/workshop/folders/{$this->folder}/parts", ['product_id' => $this->part->id, 'quantity' => '3'])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/workshop/folders/{$this->folder}/status", ['status' => 'cancelled'])->assertOk();
    expect(WorkshopPartConsumption::query()->findOrFail($open)->status)->toBe('released')
        ->and(WorkshopPartConsumption::query()->findOrFail($kept)->status)->toBe('issued');
    $this->getJson("/api/v1/workshop/parts/{$this->part->id}/availability")->assertJsonPath('data.available', '8.0000');
    $this->postJson("/api/v1/workshop/folders/{$this->folder}/parts", ['product_id' => $this->part->id, 'quantity' => '1'])->assertUnprocessable();
});

test('the delivery note does not move again the stock already issued through the folder', function (): void {
    $this->postJson("/api/v1/workshop/folders/{$this->folder}/lines", ['line_type' => 'part', 'product_id' => $this->part->id, 'description' => 'Brake pads', 'quantity' => '2', 'unit_price' => '80', 'tax_rate' => '8.1'])->assertCreated();
    $id = $this->postJson("/api/v1/workshop/folders/{$this->folder}/parts", ['product_id' => $this->part->id, 'quantity' => '2'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/workshop/part-consumptions/{$id}/issue")->assertOk();
    $this->postJson("/api/v1/workshop/folders/{$this->folder}/status", ['status' => 'in_progress'])->assertOk();
    $this->postJson("/api/v1/workshop/folders/{$this->folder}/status", ['status' => 'completed'])->assertOk();

    $this->postJson("/api/v1/workshop/folders/{$this->folder}/delivery-note")->assertCreated();
    expect(app(StockService::class)->quantityOf($this->part->id))->toBe(8.0);
});

test('the stock gateway is resolved through the workshop contract', function (): void {
    expect(app(WorkshopStockGateway::class)->isTracked($this->part->id))->toBeTrue()
        ->and(app(WorkshopStockGateway::class)->isTracked($this->service->id))->toBeFalse();
});
