<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Logistics\Models\StockTransferOrder;
use Modules\Logistics\Services\ConsignmentService;
use Modules\Logistics\Services\StockTransferService;
use Modules\Logistics\Services\WarehouseStockService;
use Modules\Procurement\Models\PurchaseOrder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->user] = logisticsTenant('Consignment');
    app(TenantContext::class)->setTenant($this->tenant);
    $this->stock = app(WarehouseStockService::class);
    $this->transfers = app(StockTransferService::class);
    $this->consignment = app(ConsignmentService::class);
    $this->product = logisticsProduct($this->tenant->id, 'CD-1', 10, 30);
    $this->main = logisticsWarehouse($this->tenant->id, 'MAIN');
    $this->customer = logisticsCustomer($this->tenant->id, 'Cliente Deposito');
    $this->deposit = logisticsWarehouse($this->tenant->id, 'CLI-DEP', [
        'warehouse_type' => 'customer_consignment', 'linked_partner_id' => $this->customer->id,
    ]);
    $this->stock->receiveOpeningStock($this->main->id, $this->product->id, 100);
});

function sendToConsignment(object $t, float $qty): StockTransferOrder
{
    $order = $t->transfers->create([
        'purpose' => 'customer_consignment', 'origin_warehouse_id' => $t->main->id, 'destination_warehouse_id' => $t->deposit->id,
    ], [['product_id' => $t->product->id, 'quantity' => $qty]]);

    return $t->transfers->receive($t->transfers->ship($order));
}

test('customer consignment DDT is addressed to the customer and stock lands on the consignment warehouse', function (): void {
    $order = sendToConsignment($this, 30);
    $ddt = SalesDeliveryNote::findOrFail($order->delivery_note_id);

    expect($ddt->type)->toBe('customer_consignment')
        ->and($ddt->ddt_number)->toStartWith('DDT-CD-')
        ->and($ddt->transport_reason_code)->toBe('consignment')
        ->and($ddt->contact_id)->toBe($this->customer->id)
        ->and($this->stock->onHand($this->deposit->id, $this->product->id))->toBe(30.0);
});

test('declaring consumption discharges the consignment stock and generates a draft sales invoice', function (): void {
    sendToConsignment($this, 30);

    $report = $this->consignment->declareConsumption([
        'warehouse_id' => $this->deposit->id, 'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->toDateString(),
    ], [['product_id' => $this->product->id, 'quantity' => 12]]);

    expect($report->status)->toBe('posted')
        ->and($report->direction)->toBe('customer')
        ->and($report->generated_document_type)->toBe('sales_invoice')
        ->and($this->stock->onHand($this->deposit->id, $this->product->id))->toBe(18.0);

    $invoice = SalesInvoice::with('items')->findOrFail($report->generated_document_id);
    expect($invoice->status)->toBe('draft')
        ->and($invoice->contact_id)->toBe($this->customer->id)
        ->and((float) $invoice->items->first()->quantity)->toBe(12.0)
        ->and((float) $invoice->items->first()->unit_price)->toBe(30.0) // product sale price
        ->and((float) $invoice->total_net)->toBe(360.0)
        ->and((float) $report->total_amount)->toBe((float) $invoice->total_gross);

    // Cannot consume more than what is held at the customer
    expect(fn () => $this->consignment->declareConsumption([
        'warehouse_id' => $this->deposit->id, 'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
    ], [['product_id' => $this->product->id, 'quantity' => 19]]))->toThrow(DomainException::class, 'Insufficient stock');

    // Consumption is only allowed on consignment warehouses
    expect(fn () => $this->consignment->declareConsumption([
        'warehouse_id' => $this->main->id, 'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
    ], [['product_id' => $this->product->id, 'quantity' => 1]]))->toThrow(DomainException::class);
});

test('the register of goods at third parties reports sent, consumed, residual quantity and fiscal value with DDT references', function (): void {
    $order = sendToConsignment($this, 30);
    $ddtNumber = SalesDeliveryNote::findOrFail($order->delivery_note_id)->ddt_number;
    $this->consignment->declareConsumption([
        'warehouse_id' => $this->deposit->id, 'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
    ], [['product_id' => $this->product->id, 'quantity' => 12]]);

    $ledger = $this->consignment->ledger($this->deposit->id);
    expect($ledger)->toHaveCount(1)
        ->and($ledger[0]['on_balance_sheet'])->toBeTrue()
        ->and($ledger[0]['partner_id'])->toBe($this->customer->id);

    $row = $ledger[0]['rows'][0];
    expect($row['sent_qty'])->toBe(30.0)
        ->and($row['consumed_qty'])->toBe(12.0)
        ->and($row['returned_qty'])->toBe(0.0)
        ->and($row['residual_qty'])->toBe(18.0)
        ->and($row['unit_cost'])->toBe(10.0)
        ->and($row['fiscal_value'])->toBe(180.0)
        ->and($row['ddt_references'])->toBe([$ddtNumber]);
});

test('vendor consignment stock is excluded from the balance sheet valuation while goods at customers are included', function (): void {
    $supplierId = (string) Str::uuid7();
    $vendorWh = logisticsWarehouse($this->tenant->id, 'VEND-DEP', [
        'warehouse_type' => 'vendor_consignment', 'legal_owner_type' => 'vendor', 'linked_partner_id' => $supplierId,
    ]);
    $this->stock->receiveOpeningStock($vendorWh->id, $this->product->id, 50); // 50 x 10 = 500 owned by the vendor

    sendToConsignment($this, 30); // 30 x 10 = 300 at the customer, still ours; 70 x 10 = 700 in MAIN

    $valuation = $this->consignment->inventoryValuation();
    expect($valuation['total_balance_sheet_value'])->toBe(1000.0)
        ->and($valuation['excluded_third_party_value'])->toBe(500.0)
        ->and($valuation['by_warehouse_type']['standard'])->toBe(700.0)
        ->and($valuation['by_warehouse_type']['customer_consignment'])->toBe(300.0)
        ->and($valuation['by_warehouse_type'])->not->toHaveKey('vendor_consignment');

    $ledger = collect($this->consignment->ledger())->keyBy('warehouse_code');
    expect($ledger['VEND-DEP']['on_balance_sheet'])->toBeFalse()
        ->and($ledger['VEND-DEP']['total_fiscal_value'])->toBe(500.0);
});

test('vendor consignment withdrawal discharges the vendor stock and drafts a purchase order', function (): void {
    // purchase_orders.supplier_id references contacts in the current schema
    $supplier = Contact::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'company_name' => 'Fornitore Deposito Srl', 'type' => 'supplier', 'country' => 'IT']);
    $vendorWh = logisticsWarehouse($this->tenant->id, 'VEND-DEP', [
        'warehouse_type' => 'vendor_consignment', 'legal_owner_type' => 'vendor', 'linked_partner_id' => $supplier->id,
    ]);
    $this->stock->receiveOpeningStock($vendorWh->id, $this->product->id, 50);

    $report = $this->consignment->declareConsumption([
        'warehouse_id' => $vendorWh->id, 'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
    ], [['product_id' => $this->product->id, 'quantity' => 20]]);

    expect($report->direction)->toBe('vendor')
        ->and($report->generated_document_type)->toBe('purchase_order')
        ->and($this->stock->onHand($vendorWh->id, $this->product->id))->toBe(30.0);

    $po = PurchaseOrder::findOrFail($report->generated_document_id);
    expect($po->status)->toBe('draft')->and($po->supplier_id)->toBe($supplier->id);
});

test('consignment endpoints: ledger, valuation and consumption declaration', function (): void {
    sendToConsignment($this, 30);
    Sanctum::actingAs($this->user);
    $h = ['X-Tenant-Id' => $this->tenant->id];

    $this->getJson('/api/v1/logistics/consignment/ledger?warehouse_id='.$this->deposit->id, $h)
        ->assertOk()->assertJsonPath('data.0.rows.0.residual_qty', 30);
    $this->getJson('/api/v1/logistics/consignment/valuation', $h)->assertOk()->assertJsonPath('data.total_balance_sheet_value', 1000);

    $this->postJson('/api/v1/logistics/consignment/consumption-reports', [
        'warehouse_id' => $this->deposit->id, 'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->toDateString(),
        'lines' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 40]],
    ], $h)->assertCreated()->assertJsonPath('data.status', 'posted');

    $this->postJson('/api/v1/logistics/consignment/consumption-reports', ['warehouse_id' => $this->deposit->id], $h)->assertUnprocessable();
    $this->getJson('/api/v1/logistics/consignment/consumption-reports', $h)->assertOk()->assertJsonCount(1, 'data');
});
