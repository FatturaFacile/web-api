<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\Warehouse;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Logistics\Queries\InventoryValuationReportQuery;
use Modules\Subcontracting\Models\SubcontractingOrder;
use Modules\Subcontracting\Models\SubcontractMaterialLedgerEntry;

require_once __DIR__.'/SubcontractingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    sctSetUp($this);
});

/**
 * An active order of 10 pieces, 2 kg of customer steel each, with the customer material received.
 */
function sctActiveWithMaterial(object $test): SubcontractingOrder
{
    $order = sctActiveOrder($test, [
        'components' => [['component_product_id' => (string) $test->steel->id, 'required_qty_per_unit' => '2', 'unit_of_measure' => 'KG']],
    ]);
    $test->postJson("/api/v1/subcontracting/orders/{$order->id}/receive-customer-materials", [
        'customer_ddt_number' => 'CL-901', 'customer_ddt_date' => now()->toDateString(),
    ])->assertOk();

    return $order->refresh();
}

it('creates an active order with a segregated customer-goods warehouse owned by the customer', function (): void {
    $response = $this->postJson('/api/v1/subcontracting/orders', sctOrderPayload($this, ['direction' => 'active', 'partner_id' => (string) $this->customer->id]))->assertCreated();

    expect($response->json('data.direction'))->toBe('active')
        ->and($response->json('data.zero_balance_sheet_value'))->toBeTrue()
        ->and($response->json('data.partner_name'))->toBe('Cliente Meccanica Srl');

    $warehouse = Warehouse::query()->findOrFail($response->json('data.subcontractor_warehouse_id'));
    expect($warehouse->legal_owner_type)->toBe('customer')
        ->and((bool) $warehouse->is_virtual)->toBeTrue()
        ->and($warehouse->linked_partner_id)->toBe((string) $this->customer->id);
});

it('refuses a TPP on an active order and a TPA on a passive order', function (): void {
    $this->postJson('/api/v1/subcontracting/orders', sctOrderPayload($this, ['direction' => 'active', 'partner_id' => (string) $this->customer->id, 'customs_regime' => 'tpp_outward_processing']))
        ->assertUnprocessable()->assertJsonPath('code', 'customs_regime_direction_mismatch');
    $this->postJson('/api/v1/subcontracting/orders', sctOrderPayload($this, ['customs_regime' => 'tpa_inward_processing']))
        ->assertUnprocessable()->assertJsonPath('code', 'customs_regime_direction_mismatch');
});

it('receives the customer material at zero balance-sheet value and logs the statutory ledger entry', function (): void {
    $before = app(InventoryValuationReportQuery::class)->execute();

    $order = sctActiveWithMaterial($this);

    expect($this->stock->onHand((string) $order->subcontractor_warehouse_id, (string) $this->steel->id))->toBe(20.0)
        ->and($order->status->value)->toBe('materials_dispatched');

    $entry = SubcontractMaterialLedgerEntry::query()->where('subcontracting_order_id', $order->id)->firstOrFail();
    expect($entry->movement_type->value)->toBe('customer_material_receipt')
        ->and($entry->direction->value)->toBe('active')
        ->and($entry->valuation_amount)->toBe('0.00')
        ->and($entry->ddt_number)->toBe('CL-901')
        ->and($entry->statutory_return_deadline?->toDateString())->toBe(now()->addYearNoOverflow()->toDateString());

    // The warehouse is owned by the customer: the valuation report leaves its stock out of the balance sheet.
    $after = app(InventoryValuationReportQuery::class)->execute();
    expect($after['total_balance_sheet_value'])->toBe($before['total_balance_sheet_value'])
        ->and($after['excluded_third_party_value'])->toBeGreaterThan($before['excluded_third_party_value']);
});

it('records the production on the customer material and delivers the processed goods with the DDT Reso lavorato', function (): void {
    $order = sctActiveWithMaterial($this);

    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/production", ['good_quantity' => '9', 'scrap_quantity' => '1'])->assertOk()
        ->assertJsonPath('data.received_quantity', '9.0000')
        ->assertJsonPath('data.scrap_quantity', '1.0000')
        ->assertJsonPath('data.status', 'in_progress');

    expect($this->stock->onHand((string) $order->subcontractor_warehouse_id, (string) $this->finished->id))->toBe(9.0)
        ->and($this->stock->onHand((string) $order->subcontractor_warehouse_id, (string) $this->steel->id))->toBe(0.0);

    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/deliver", ['carrier_name' => 'Corriere Rapido'])->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.delivered_quantity', '9.0000')
        ->assertJsonPath('data.service_billing.amount', '45.00')
        ->assertJsonPath('data.service_billing.quantity', '9.0000');

    $order->refresh();
    $ddt = SalesDeliveryNote::query()->findOrFail($order->outgoing_material_ddt_id);
    expect($ddt->type)->toBe('subcontracting_finished_return')
        ->and($ddt->transport_reason_code)->toBe('subcontracted_goods_return')
        ->and($ddt->transport_reason)->toBe('Reso lavorato')
        ->and((string) $ddt->contact_id)->toBe((string) $this->customer->id)
        ->and($ddt->ddt_number)->toStartWith('DDT-CL-')
        ->and($this->stock->onHand((string) $order->subcontractor_warehouse_id, (string) $this->finished->id))->toBe(0.0)
        ->and($order->service_billing['ddt_numbers'])->toBe([$ddt->ddt_number]);

    $types = SubcontractMaterialLedgerEntry::query()->where('subcontracting_order_id', $order->id)->orderBy('created_at')->orderBy('id')->pluck('movement_type')->map(fn ($t) => $t->value)->all();
    expect($types)->toBe(['customer_material_receipt', 'inbound_consumption_backflush', 'scrap_declared', 'customer_finished_return']);
    expect(SubcontractMaterialLedgerEntry::query()->where('subcontracting_order_id', $order->id)->sum('valuation_amount'))->toEqual(0);
});

it('delivers in steps, returns the unused customer material on request and refuses more than was processed', function (): void {
    $order = sctActiveOrder($this, [
        'components' => [['component_product_id' => (string) $this->steel->id, 'required_qty_per_unit' => '2', 'unit_of_measure' => 'KG']],
    ]);
    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/receive-customer-materials")->assertOk();
    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/production", ['good_quantity' => '4'])->assertOk();

    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/deliver", ['quantity' => '5'])->assertUnprocessable()->assertJsonPath('code', 'delivery_exceeds_processed');
    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/deliver", ['quantity' => '4', 'return_unused_materials' => true])->assertOk()
        ->assertJsonPath('data.delivered_quantity', '4.0000')
        ->assertJsonPath('data.status', 'partially_returned');

    $order->refresh();
    expect($this->stock->onHand((string) $order->subcontractor_warehouse_id, (string) $this->steel->id))->toBe(0.0)
        ->and(SubcontractMaterialLedgerEntry::query()->where('subcontracting_order_id', $order->id)->where('movement_type', 'unused_material_return')->value('returned_unused_qty'))->toBe('12.0000');
});

it('refuses the passive actions on an active order', function (): void {
    $order = sctActiveWithMaterial($this);

    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/dispatch")->assertUnprocessable()->assertJsonPath('code', 'direction_mismatch');
    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/receive", ['good_quantity' => '1'])->assertUnprocessable()->assertJsonPath('code', 'direction_mismatch');
});
