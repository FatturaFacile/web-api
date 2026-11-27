<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\Warehouse;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgBomComponent;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Subcontracting\Domain\Enums\SubcontractingType;
use Modules\Subcontracting\Models\SubcontractingOrder;
use Modules\Subcontracting\Models\SubcontractMaterialLedgerEntry;
use Modules\Subcontracting\Services\BomComponentResolver;

require_once __DIR__.'/SubcontractingSupport.php';
require_once __DIR__.'/../Manufacturing/ManufacturingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    sctSetUp($this);
});

it('creates a passive full-BOM order, numbers it and provisions the vendor virtual warehouse', function (): void {
    $response = $this->postJson('/api/v1/subcontracting/orders', sctOrderPayload($this))->assertCreated();

    expect($response->json('data.order_number'))->toBe('SCT-'.now()->format('Y').'-00001')
        ->and($response->json('data.status'))->toBe('draft')
        ->and($response->json('data.direction'))->toBe('passive')
        ->and($response->json('data.zero_balance_sheet_value'))->toBeFalse()
        ->and($response->json('data.components'))->toHaveCount(2)
        ->and($response->json('data.components.0.total_planned_qty'))->toBe('20.0000')
        ->and($response->json('data.components.1.total_planned_qty'))->toBe('5.0000');

    $virtual = Warehouse::query()->findOrFail($response->json('data.subcontractor_warehouse_id'));
    expect($virtual->warehouse_type)->toBe('subcontractor')
        ->and($virtual->legal_owner_type)->toBe('company')
        ->and((bool) $virtual->is_virtual)->toBeTrue()
        ->and($virtual->linked_partner_id)->toBe((string) $this->vendor->id);

    // A second order of the same vendor reuses the warehouse.
    $second = $this->postJson('/api/v1/subcontracting/orders', sctOrderPayload($this))->assertCreated();
    expect($second->json('data.subcontractor_warehouse_id'))->toBe($virtual->id)
        ->and($second->json('data.order_number'))->toBe('SCT-'.now()->format('Y').'-00002');
});

it('explodes the BOM of the master data with the scrap of each line', function (): void {
    $finished = mfgItem($this, 'FIN', 'finished', '0', ['product_id' => $this->finished->id]);
    $steel = mfgItem($this, 'STEEL', 'raw_material', '10', ['product_id' => $this->steel->id]);
    $phantom = mfgItem($this, 'PHANTOM', 'raw_material', '0', ['product_id' => $this->paint->id]);

    $bom = MfgBom::create(['tenant_id' => $this->tenant->id, 'bom_number' => 'BOM-1', 'mfg_item_id' => $finished->id, 'name' => 'Galvanized bracket', 'status' => 'active', 'base_quantity' => '2', 'uom' => 'PCS']);
    MfgBomComponent::create(['tenant_id' => $this->tenant->id, 'bom_id' => $bom->id, 'component_item_id' => $steel->id, 'quantity_per' => '4', 'uom' => 'KG', 'scrap_percentage' => '10']);
    MfgBomComponent::create(['tenant_id' => $this->tenant->id, 'bom_id' => $bom->id, 'component_item_id' => $phantom->id, 'quantity_per' => '1', 'uom' => 'L', 'is_phantom' => true]);

    $plan = app(BomComponentResolver::class)->resolve(SubcontractingType::FullBom, (string) $this->tenant->id, (string) $bom->id, null, null);

    // 4 kg per 2 pieces = 2 kg per piece, +10% scrap = 2.2; the phantom line is skipped.
    expect($plan['lines'])->toHaveCount(1)
        ->and($plan['lines'][0]['component_product_id'])->toBe((string) $this->steel->id)
        ->and($plan['lines'][0]['required_qty_per_unit'])->toBe('2.200000')
        ->and($plan['bom_id'])->toBe((string) $bom->id);

    $response = $this->postJson('/api/v1/subcontracting/orders', [
        'direction' => 'passive', 'subcontracting_type' => 'full_bom', 'partner_id' => (string) $this->vendor->id,
        'output_product_id' => (string) $this->finished->id, 'planned_quantity' => '10', 'bom_id' => (string) $bom->id,
    ])->assertCreated();

    expect($response->json('data.bom_id'))->toBe((string) $bom->id)
        ->and($response->json('data.components.0.total_planned_qty'))->toBe('22.0000');
});

it('creates a single routing operation order from the semi-finished input', function (): void {
    $response = $this->postJson('/api/v1/subcontracting/orders', sctOrderPayload($this, [
        'subcontracting_type' => 'single_operation',
        'routing_operation_label' => 'OP30 Galvanizing',
        'components' => [['component_product_id' => (string) $this->steel->id, 'required_qty_per_unit' => '1', 'unit_of_measure' => 'PCS']],
    ]))->assertCreated();

    expect($response->json('data.subcontracting_type'))->toBe('single_operation')
        ->and($response->json('data.routing_operation_label'))->toBe('OP30 Galvanizing')
        ->and($response->json('data.components'))->toHaveCount(1);
});

it('refuses an order without component lines', function (): void {
    $this->postJson('/api/v1/subcontracting/orders', sctOrderPayload($this, ['components' => []]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'no_components');
});

it('dispatches the material to the vendor warehouse with a DDT Conto lavoro and a one-year statutory deadline', function (): void {
    $order = sctPassiveOrder($this);

    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/dispatch")->assertOk()
        ->assertJsonPath('data.status', 'materials_dispatched')
        ->assertJsonPath('data.components.0.dispatched_qty', '20.0000');

    $order->refresh();
    $ddt = SalesDeliveryNote::query()->findOrFail($order->outgoing_material_ddt_id);

    expect($ddt->type)->toBe('subcontracting_out')
        ->and($ddt->transport_reason_code)->toBe('subcontracting')
        ->and($ddt->ddt_number)->toStartWith('DDT-CL-')
        ->and($ddt->status)->toBe('issued')
        ->and($this->stock->onHand((string) $this->main->id, (string) $this->steel->id))->toBe(480.0)
        ->and($this->stock->onHand((string) $order->subcontractor_warehouse_id, (string) $this->steel->id))->toBe(20.0)
        ->and($this->stock->onHand((string) $order->subcontractor_warehouse_id, (string) $this->paint->id))->toBe(5.0);

    $entry = SubcontractMaterialLedgerEntry::query()->where('subcontracting_order_id', $order->id)->where('component_product_id', $this->steel->id)->firstOrFail();
    expect($entry->movement_type->value)->toBe('outbound_dispatch')
        ->and($entry->ddt_number)->toBe($ddt->ddt_number)
        ->and($entry->statutory_return_deadline?->toDateString())->toBe($ddt->date->copy()->addYearNoOverflow()->toDateString())
        ->and($entry->remaining_at_subcontractor_qty)->toBe('20.0000')
        ->and($entry->valuation_amount)->toBe('200.00');
});

it('dispatches in several steps and refuses more than planned', function (): void {
    $order = sctPassiveOrder($this);
    $component = $order->components()->where('component_product_id', $this->steel->id)->firstOrFail();

    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/dispatch", ['lines' => [['component_id' => $component->id, 'quantity' => '12']]])->assertOk();
    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/dispatch", ['lines' => [['component_id' => $component->id, 'quantity' => '9']]])
        ->assertUnprocessable()->assertJsonPath('code', 'dispatch_exceeds_plan');
    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/dispatch", ['lines' => [['component_id' => $component->id, 'quantity' => '8']]])->assertOk();

    expect($order->refresh()->components()->where('component_product_id', $this->steel->id)->firstOrFail()->dispatched_qty)->toBe('20.0000');
});

it('refuses to dispatch more than the company warehouse holds', function (): void {
    $order = sctPassiveOrder($this, ['planned_quantity' => '1000']);

    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/dispatch")->assertUnprocessable()->assertJsonPath('code', 'ddt_failed');
    expect($order->refresh()->status->value)->toBe('confirmed');
});

it('receives the processed goods, backflushes the components, declares the scrap and capitalizes the service fee', function (): void {
    $order = sctDispatchAll($this, sctPassiveOrder($this));

    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/receive", [
        'good_quantity' => '8', 'scrap_quantity' => '2', 'vendor_ddt_number' => 'TZ-77', 'vendor_ddt_date' => now()->toDateString(),
    ])->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.received_quantity', '8.0000')
        ->assertJsonPath('data.scrap_quantity', '2.0000');

    $order->refresh();

    // Consumed for 8 good units: 16 steel and 4 paint; scrap of 2 units: 4 steel and 1 paint. Value = 20 x 10 + 5 x 4 = 220, fee 8 x 5 = 40.
    expect($order->capitalized_service_amount)->toBe('40.0000')
        ->and($order->capitalized_unit_cost)->toBe('32.500000')
        ->and($order->inbound_return_document_number)->toBe('TZ-77')
        ->and($order->completed_at)->not->toBeNull()
        ->and($this->stock->onHand((string) $order->subcontractor_warehouse_id, (string) $this->steel->id))->toBe(0.0)
        ->and($this->stock->onHand((string) $this->main->id, (string) $this->finished->id))->toBe(8.0);

    $steel = $order->components()->where('component_product_id', $this->steel->id)->firstOrFail();
    expect($steel->consumed_qty)->toBe('16.0000')->and($steel->scrap_qty)->toBe('4.0000');

    $types = SubcontractMaterialLedgerEntry::query()->where('subcontracting_order_id', $order->id)->where('component_product_id', $this->steel->id)->orderBy('created_at')->orderBy('id')->pluck('movement_type')->map(fn ($t) => $t->value)->all();
    expect($types)->toBe(['outbound_dispatch', 'inbound_consumption_backflush', 'scrap_declared']);

    $scrap = SubcontractMaterialLedgerEntry::query()->where('movement_type', 'scrap_declared')->where('component_product_id', $this->steel->id)->firstOrFail();
    expect($scrap->scrap_reported_by_subcontractor_qty)->toBe('4.0000')->and($scrap->remaining_at_subcontractor_qty)->toBe('0.0000');
});

it('lets the actual consumption override the BOM backflush', function (): void {
    $order = sctDispatchAll($this, sctPassiveOrder($this));
    $steel = $order->components()->where('component_product_id', $this->steel->id)->firstOrFail();

    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/receive", [
        'good_quantity' => '10', 'components' => [['component_id' => $steel->id, 'consumed_qty' => '18', 'scrap_qty' => '2']],
    ])->assertOk();

    expect($steel->refresh()->consumed_qty)->toBe('18.0000')->and($steel->scrap_qty)->toBe('2.0000');
});

it('previews the backflush and the capitalized cost without writing anything', function (): void {
    $order = sctDispatchAll($this, sctPassiveOrder($this));

    $response = $this->postJson("/api/v1/subcontracting/orders/{$order->id}/receive-preview", ['good_quantity' => '8', 'scrap_quantity' => '2'])->assertOk();

    expect($response->json('data.components_value'))->toBe('220.0000')
        ->and($response->json('data.service_amount'))->toBe('40.0000')
        ->and($response->json('data.total_cost'))->toBe('260.0000')
        ->and($response->json('data.unit_cost'))->toBe('32.500000')
        ->and($order->refresh()->received_quantity)->toBe('0.0000')
        ->and(SubcontractMaterialLedgerEntry::query()->where('movement_type', 'inbound_consumption_backflush')->count())->toBe(0);
});

it('refuses a receipt above the quantity expected or a consumption above what the vendor holds', function (): void {
    $order = sctDispatchAll($this, sctPassiveOrder($this));
    $steel = $order->components()->where('component_product_id', $this->steel->id)->firstOrFail();

    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/receive", ['good_quantity' => '11'])->assertUnprocessable()->assertJsonPath('code', 'receipt_exceeds_planned');
    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/receive", ['good_quantity' => '5', 'components' => [['component_id' => $steel->id, 'consumed_qty' => '21']]])
        ->assertUnprocessable()->assertJsonPath('code', 'consumption_exceeds_held');
});

it('returns the unused material with a DDT Reso da terzista and closes the order', function (): void {
    $order = sctDispatchAll($this, sctPassiveOrder($this));

    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/receive", ['good_quantity' => '5'])->assertOk()
        ->assertJsonPath('data.status', 'partially_returned');
    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/return-unused")->assertOk()
        ->assertJsonPath('data.status', 'partially_returned');

    $order->refresh();
    $note = SalesDeliveryNote::query()->where('type', 'subcontracting_return')->firstOrFail();

    expect($note->transport_reason_code)->toBe('subcontractor_return')
        ->and($this->stock->onHand((string) $order->subcontractor_warehouse_id, (string) $this->steel->id))->toBe(0.0)
        ->and($this->stock->onHand((string) $this->main->id, (string) $this->steel->id))->toBe(490.0);

    $unused = SubcontractMaterialLedgerEntry::query()->where('movement_type', 'unused_material_return')->where('component_product_id', $this->steel->id)->firstOrFail();
    expect($unused->returned_unused_qty)->toBe('10.0000')->and($unused->remaining_at_subcontractor_qty)->toBe('0.0000')->and($unused->ddt_number)->toBe($note->ddt_number);

    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/return-unused")->assertUnprocessable()->assertJsonPath('code', 'nothing_to_return');
});

it('cancels an order that has not moved goods and refuses after a dispatch', function (): void {
    $draft = sctPassiveOrder($this, [], false);
    $this->postJson("/api/v1/subcontracting/orders/{$draft->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

    $moved = sctDispatchAll($this, sctPassiveOrder($this));
    $this->postJson("/api/v1/subcontracting/orders/{$moved->id}/cancel")->assertUnprocessable()->assertJsonPath('code', 'cancel_after_movement');
});

it('refuses an action that does not fit the status or the direction of the order', function (): void {
    $draft = sctPassiveOrder($this, [], false);
    $this->postJson("/api/v1/subcontracting/orders/{$draft->id}/dispatch")->assertUnprocessable()->assertJsonPath('code', 'invalid_status_for_action');

    $confirmed = sctPassiveOrder($this);
    $this->postJson("/api/v1/subcontracting/orders/{$confirmed->id}/deliver")->assertUnprocessable()->assertJsonPath('code', 'direction_mismatch');
    $this->postJson("/api/v1/subcontracting/orders/{$confirmed->id}/confirm")->assertUnprocessable()->assertJsonPath('code', 'invalid_status_transition');
});

it('drafts the service purchase order of Procurement when asked', function (): void {
    $response = $this->postJson('/api/v1/subcontracting/orders', sctOrderPayload($this, ['generate_service_purchase_order' => true]))->assertCreated();

    $purchaseOrder = PurchaseOrder::query()->findOrFail($response->json('data.linked_service_purchase_order_id'));

    expect($purchaseOrder->order_type)->toBe('subcontract')
        ->and((string) $purchaseOrder->supplier_id)->toBe((string) $this->vendor->id);
});

it('keeps the orders of a company out of reach of another and checks the permissions', function (): void {
    $order = sctPassiveOrder($this);

    app(TenantContext::class)->setTenant($this->otherTenant);
    Sanctum::actingAs($this->otherUser);
    $this->withHeader('X-Tenant-Id', (string) $this->otherTenant->id);
    $this->getJson("/api/v1/subcontracting/orders/{$order->id}")->assertNotFound();
    expect($this->getJson('/api/v1/subcontracting/orders')->json('meta.total'))->toBe(0);

    // A plain member reads nothing of the module and creates nothing.
    $member = tfrUserWithRole((string) $this->tenant->id, 'member');
    app(TenantContext::class)->setTenant($this->tenant);
    Sanctum::actingAs($member);
    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id);
    $this->postJson('/api/v1/subcontracting/orders', sctOrderPayload($this))->assertForbidden();
    expect(SubcontractingOrder::query()->count())->toBe(1);
});
