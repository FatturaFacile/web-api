<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Warehouse;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Logistics\Enums\DiscrepancyStatus;
use Modules\Logistics\Events\InterCompanyTransferShipped;
use Modules\Logistics\Models\StockTransferOrder;
use Modules\Logistics\Models\TransferDiscrepancy;
use Modules\Logistics\Services\StockTransferService;
use Modules\Logistics\Services\WarehouseStockService;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->user] = wmsTenant('Transfers');
    app(TenantContext::class)->setTenant($this->tenant);
    $this->stock = app(WarehouseStockService::class);
    $this->transfers = app(StockTransferService::class);
    $this->product = wmsProduct($this->tenant->id, 'TR-1', 10, 30);
    $this->main = wmsWarehouse($this->tenant->id, 'MAIN');
    $this->branch = wmsWarehouse($this->tenant->id, 'BRANCH');
    $this->stock->receiveOpeningStock($this->main->id, $this->product->id, 100);
});

function draftTransfer(object $t, float $qty = 40, string $purpose = 'inter_warehouse', ?string $destination = null): StockTransferOrder
{
    return $t->transfers->approve($t->transfers->create([
        'purpose' => $purpose,
        'origin_warehouse_id' => $t->main->id,
        'destination_warehouse_id' => $destination ?? $t->branch->id,
    ], [['product_id' => $t->product->id, 'quantity' => $qty]]));
}

test('ship moves stock to the transit location and issues the transfer DDT', function (): void {
    $order = draftTransfer($this);
    expect($order->status)->toBe('approved')->and($order->transfer_number)->toBe('TRF-'.now()->format('Y').'-00001');

    $shipped = $this->transfers->ship($order, ['carrier_name' => 'Furgone aziendale']);

    $transit = Warehouse::query()->where('warehouse_type', 'transit')->firstOrFail();
    expect($shipped->status)->toBe('in_transit')
        ->and($shipped->transit_warehouse_id)->toBe($transit->id)
        ->and($this->stock->onHand($this->main->id, $this->product->id))->toBe(60.0)
        ->and($this->stock->onHand($transit->id, $this->product->id))->toBe(40.0)
        ->and($this->stock->onHand($this->branch->id, $this->product->id))->toBe(0.0);

    $ddt = SalesDeliveryNote::findOrFail($shipped->delivery_note_id);
    expect($ddt->type)->toBe('internal_transfer')
        ->and($ddt->status)->toBe('in_transit')
        ->and($ddt->shipping_status)->toBe('dispatched')
        ->and($ddt->ddt_number)->toBe('DDT-TR-'.now()->format('Y').'-00001')
        ->and($ddt->transport_reason_code)->toBe('internal_transfer')
        ->and($ddt->origin_warehouse_id)->toBe($this->main->id)
        ->and($ddt->destination_warehouse_id)->toBe($this->branch->id)
        ->and($ddt->stock_transfer_order_id)->toBe($shipped->id)
        ->and((float) $ddt->items->first()->quantity)->toBe(40.0);

    // The stock was moved exactly once (issuing the DDT must not duplicate the movement)
    expect(StockMovement::query()->where('movement_type', 'transfer_ship_out')->count())->toBe(1);
});

test('full receipt moves stock from transit to destination and closes the transfer', function (): void {
    $order = $this->transfers->ship(draftTransfer($this));
    $received = $this->transfers->receive($order);

    expect($received->status)->toBe('received')
        ->and($received->discrepancies)->toHaveCount(0)
        ->and($this->stock->onHand($this->branch->id, $this->product->id))->toBe(40.0)
        ->and($this->stock->onHand($received->transit_warehouse_id, $this->product->id))->toBe(0.0)
        ->and(SalesDeliveryNote::find($received->delivery_note_id)->status)->toBe('delivered');
});

test('short receipt creates a discrepancy and corrective movements happen only after approval', function (): void {
    $order = $this->transfers->ship(draftTransfer($this, 40));
    $line = $order->lines->first();

    $received = $this->transfers->receive($order, [['line_id' => $line->id, 'received_qty' => 35, 'discrepancy_reason' => 'Colli danneggiati']]);

    $transitId = $received->transit_warehouse_id;
    expect($received->status)->toBe('discrepancy_pending')
        ->and((float) $received->lines->first()->discrepancy_qty)->toBe(5.0)
        ->and($received->lines->first()->discrepancy_reason)->toBe('Colli danneggiati')
        ->and($received->discrepancies)->toHaveCount(1)
        ->and($received->discrepancies->first()->status)->toBe('pending_approval')
        // nothing corrective yet: the missing 5 units stay on the transit location
        ->and($this->stock->onHand($this->branch->id, $this->product->id))->toBe(35.0)
        ->and($this->stock->onHand($transitId, $this->product->id))->toBe(5.0)
        ->and(StockMovement::query()->where('movement_type', 'like', 'transfer_write_off%')->count())->toBe(0);

    $discrepancy = $received->discrepancies->first();
    $resolved = $this->transfers->resolveDiscrepancy($discrepancy, DiscrepancyStatus::ApprovedWriteOff, (string) $this->user->id);

    expect($resolved->status)->toBe('approved_write_off')
        ->and($resolved->resolved_at)->not->toBeNull()
        ->and($this->stock->onHand($transitId, $this->product->id))->toBe(0.0)
        ->and(StockMovement::query()->where('movement_type', 'transfer_write_off_out')->count())->toBe(1)
        ->and($received->fresh()->status)->toBe('closed');

    // A resolved discrepancy cannot be resolved twice
    expect(fn () => $this->transfers->resolveDiscrepancy($resolved, DiscrepancyStatus::CarrierClaim))->toThrow(DomainException::class);
});

test('discrepancy can be returned to origin or claimed with the carrier', function (): void {
    $order = $this->transfers->ship(draftTransfer($this, 40));
    $line = $order->lines->first();
    $received = $this->transfers->receive($order, [['line_id' => $line->id, 'received_qty' => 0, 'discrepancy_reason' => 'Non consegnato']]);
    $d = $received->discrepancies->first();

    $this->transfers->resolveDiscrepancy($d, DiscrepancyStatus::ReturnedToOrigin);
    expect($this->stock->onHand($this->main->id, $this->product->id))->toBe(100.0)
        ->and($this->stock->onHand($received->transit_warehouse_id, $this->product->id))->toBe(0.0);

    $second = $this->transfers->ship(draftTransfer($this, 10));
    $rec = $this->transfers->receive($second, [['line_id' => $second->lines->first()->id, 'received_qty' => 8]]);
    $claim = $this->transfers->resolveDiscrepancy($rec->discrepancies->first(), DiscrepancyStatus::CarrierClaim);
    expect($claim->status)->toBe('carrier_claim')
        ->and($this->stock->onHand($this->main->id, $this->product->id))->toBe(90.0);
});

test('transfer guard rails: insufficient stock, over-receipt, wrong purpose and double shipping', function (): void {
    expect(fn () => $this->transfers->ship(draftTransfer($this, 500)))->toThrow(DomainException::class, 'Insufficient stock');
    expect($this->stock->onHand($this->main->id, $this->product->id))->toBe(100.0); // rolled back

    $order = $this->transfers->ship(draftTransfer($this, 10));
    expect(fn () => $this->transfers->ship($order))->toThrow(DomainException::class)
        ->and(fn () => $this->transfers->receive($order, [['line_id' => $order->lines->first()->id, 'received_qty' => 11]]))->toThrow(DomainException::class);

    // consignment purpose needs a customer consignment destination
    expect(fn () => draftTransfer($this, 1, 'customer_consignment'))->toThrow(DomainException::class);
    expect(fn () => $this->transfers->create(['purpose' => 'inter_warehouse', 'origin_warehouse_id' => $this->main->id, 'destination_warehouse_id' => $this->main->id], [['product_id' => $this->product->id, 'quantity' => 1]]))
        ->toThrow(InvalidArgumentException::class);
});

test('inter-company transfers raise the intercompany domain hook', function (): void {
    Event::fake([InterCompanyTransferShipped::class]);
    $entityA = (string) Str::uuid7();
    $entityB = (string) Str::uuid7();
    $this->main->update(['company_entity_id' => $entityA]);
    $other = wmsWarehouse($this->tenant->id, 'GROUP-B', ['company_entity_id' => $entityB]);

    $order = $this->transfers->ship(draftTransfer($this, 5, 'inter_warehouse', $other->id));

    expect($order->is_inter_company)->toBeTrue();
    Event::assertDispatched(InterCompanyTransferShipped::class, fn ($e) => $e->fromCompanyEntityId === $entityA && $e->toCompanyEntityId === $entityB && $e->transferId === $order->id);

    // same entity: no hook
    $same = wmsWarehouse($this->tenant->id, 'GROUP-A2', ['company_entity_id' => $entityA]);
    $o2 = $this->transfers->ship(draftTransfer($this, 5, 'inter_warehouse', $same->id));
    expect($o2->is_inter_company)->toBeFalse();
});

test('subcontracting transfer produces a conto lavoro DDT addressed to the subcontractor', function (): void {
    $supplierId = (string) Str::uuid7();
    $sub = wmsWarehouse($this->tenant->id, 'TERZISTA', ['warehouse_type' => 'subcontractor', 'linked_partner_id' => $supplierId]);

    $order = $this->transfers->ship(draftTransfer($this, 20, 'subcontracting', $sub->id));
    $ddt = SalesDeliveryNote::findOrFail($order->delivery_note_id);

    expect($ddt->type)->toBe('subcontracting_out')
        ->and($ddt->transport_reason_code)->toBe('subcontracting')
        ->and($ddt->ddt_number)->toStartWith('DDT-CL-')
        ->and($ddt->supplier_id)->toBe($supplierId)
        ->and($ddt->contact_id)->toBeNull()
        ->and((float) $ddt->items->first()->unit_price)->toBe(10.0); // fiscal value at cost

    $this->transfers->receive($order);
    expect($this->stock->onHand($sub->id, $this->product->id))->toBe(20.0);
});

test('transfer lifecycle and discrepancy approval over the API', function (): void {
    Sanctum::actingAs($this->user);
    $h = ['X-Tenant-Id' => $this->tenant->id];

    $id = $this->postJson('/api/v1/logistics/transfers', [
        'purpose' => 'inter_warehouse', 'origin_warehouse_id' => $this->main->id, 'destination_warehouse_id' => $this->branch->id,
        'lines' => [['product_id' => $this->product->id, 'quantity' => 12]],
    ], $h)->assertCreated()->json('data.id');

    $this->postJson("/api/v1/logistics/transfers/{$id}/ship", [], $h)->assertUnprocessable(); // must be approved first
    $this->postJson("/api/v1/logistics/transfers/{$id}/approve", [], $h)->assertOk()->assertJsonPath('data.status', 'approved');
    $ship = $this->postJson("/api/v1/logistics/transfers/{$id}/ship", ['carrier_name' => 'Van', 'packages_count' => 2], $h)->assertOk()->assertJsonPath('data.status', 'in_transit');
    $lineId = $ship->json('data.lines.0.id');

    $this->postJson("/api/v1/logistics/transfers/{$id}/receive", ['lines' => [['line_id' => $lineId, 'received_qty' => 10, 'discrepancy_reason' => 'Rotti']]], $h)
        ->assertOk()->assertJsonPath('data.status', 'discrepancy_pending');

    $discrepancyId = TransferDiscrepancy::query()->firstOrFail()->id;
    $this->getJson('/api/v1/logistics/transfer-discrepancies?status=pending_approval', $h)->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/logistics/transfer-discrepancies/{$discrepancyId}/resolve", ['resolution' => 'bogus'], $h)->assertUnprocessable();
    $this->postJson("/api/v1/logistics/transfer-discrepancies/{$discrepancyId}/resolve", ['resolution' => 'approved_write_off'], $h)->assertOk()->assertJsonPath('data.status', 'approved_write_off');
    $this->getJson("/api/v1/logistics/transfers/{$id}", $h)->assertOk()->assertJsonPath('data.status', 'closed');
    $this->postJson("/api/v1/logistics/transfers/{$id}/ship", [], $h)->assertUnprocessable();
});
