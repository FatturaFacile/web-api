<?php

declare(strict_types=1);

use App\Models\PassiveInvoice;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\StockReorderRule;
use Modules\Inventory\Models\StockSerialNumber;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Models\PurchaseReturn;

require_once __DIR__.'/ProcurementEnterpriseFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = procTenant('Ricevimento Alfa');
    $this->otherTenant = procTenant('Ricevimento Beta');
    $this->buyer = procMember($this->tenant, 'admin');
    $this->receiver = procMember($this->tenant, 'procurement_manager');
    procActAs($this->buyer, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * An approved order of the supplier for the catalogue entry.
 */
function procApprovedOrder(string $supplierId, string $catalogId, string $quantity, string $price, array $extra = []): string
{
    $order = procOrder($supplierId, [['quantity_ordered' => $quantity, 'unit_price' => $price, 'supplier_catalog_id' => $catalogId, 'tax_rate' => '22', ...$extra]]);
    test()->postJson("/api/v1/procurement/purchase-orders/{$order}/submit-approval")->assertOk()->assertJsonPath('status', 'approved');

    return $order;
}

/**
 * Quantity on hand of the item in the bins of a zone type.
 */
function procOnHand(StockItem $item, string $zoneType): string
{
    $value = DB::table('stock_quants as q')
        ->join('warehouse_bins as b', 'b.id', '=', 'q.warehouse_bin_id')
        ->join('warehouse_racks as r', 'r.id', '=', 'b.warehouse_rack_id')
        ->join('warehouse_aisles as a', 'a.id', '=', 'r.warehouse_aisle_id')
        ->join('warehouse_zones as z', 'z.id', '=', 'a.warehouse_zone_id')
        ->where('q.stock_item_id', $item->id)->where('z.zone_type', $zoneType)->whereNull('q.deleted_at')
        ->sum('q.quantity_on_hand');

    return number_format((float) $value, 4, '.', '');
}

/**
 * Debit and credit of the account in the entry of the source document.
 *
 * @return array{debit: string, credit: string}
 */
function procLedger(string $sourceType, string $sourceId, string $accountCode): array
{
    $row = DB::table('journal_lines as l')
        ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
        ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
        ->where('e.source_document_type', $sourceType)->where('e.source_document_id', $sourceId)->where('a.account_code', $accountCode)
        ->selectRaw('COALESCE(SUM(l.debit_amount), 0) as debit, COALESCE(SUM(l.credit_amount), 0) as credit')
        ->first();

    return ['debit' => number_format((float) $row->debit, 2, '.', ''), 'credit' => number_format((float) $row->credit, 2, '.', '')];
}

test('a partial receipt books stock on the single ledger, accrues GR/IR and respects the over-receipt tolerance', function (): void {
    $supplierId = procSupplier();
    ['item' => $item, 'warehouse' => $warehouse, 'catalog_id' => $catalogId] = procStockedCatalog($this->tenant, $supplierId, 'VITE-M6');
    $order = procApprovedOrder($supplierId, $catalogId, '10', '2.50');
    $orderLine = PurchaseOrder::find($order)->lines()->sole();

    $this->postJson("/api/v1/procurement/purchase-orders/{$order}/goods-receipt", ['supplier_delivery_note_ref' => 'DDT-1', 'received_date' => now()->toDateString(), 'warehouse_id' => $warehouse->id, 'lines' => [['purchase_order_line_id' => $orderLine->id, 'quantity_received' => '4']]])
        ->assertForbidden();

    procActAs($this->receiver, $this->tenant);
    $receipt = $this->postJson("/api/v1/procurement/purchase-orders/{$order}/goods-receipt", [
        'supplier_delivery_note_ref' => 'DDT-1', 'received_date' => now()->toDateString(), 'warehouse_id' => $warehouse->id,
        'lines' => [['purchase_order_line_id' => $orderLine->id, 'quantity_received' => '4']],
    ])->assertCreated()->assertJsonPath('receipt_number', 'GR-'.now()->year.'-0001')->assertJsonPath('lines.0.unit_cost', '2.5000')->json('id');

    expect(procOnHand($item, 'receiving'))->toBe('4.0000')
        ->and(PurchaseOrder::find($order)->status)->toBe('partially_received')
        ->and(procLedger('goods_receipt', $receipt, '1200'))->toBe(['debit' => '10.00', 'credit' => '0.00'])
        ->and(procLedger('goods_receipt', $receipt, '2010'))->toBe(['debit' => '0.00', 'credit' => '10.00'])
        ->and((string) StockItem::find($item->id)->current_moving_average_cost)->toBe('2.5000');

    $this->postJson("/api/v1/procurement/purchase-orders/{$order}/goods-receipt", ['supplier_delivery_note_ref' => 'DDT-2', 'received_date' => now()->toDateString(), 'warehouse_id' => $warehouse->id, 'lines' => [['purchase_order_line_id' => $orderLine->id, 'quantity_received' => '7']]])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity_accepted');

    procActAs($this->buyer, $this->tenant);
    $this->putJson('/api/v1/procurement/settings', ['over_receipt_tolerance_percent' => '10'])->assertOk();
    procActAs($this->receiver, $this->tenant);
    $this->postJson("/api/v1/procurement/purchase-orders/{$order}/goods-receipt", ['supplier_delivery_note_ref' => 'DDT-2', 'received_date' => now()->toDateString(), 'warehouse_id' => $warehouse->id, 'lines' => [['purchase_order_line_id' => $orderLine->id, 'quantity_received' => '7']]])
        ->assertCreated();

    expect(PurchaseOrder::find($order)->status)->toBe('received')->and(procOnHand($item, 'receiving'))->toBe('11.0000');
    $this->postJson("/api/v1/procurement/purchase-orders/{$order}/goods-receipt", ['supplier_delivery_note_ref' => 'DDT-3', 'received_date' => now()->toDateString(), 'lines' => [['purchase_order_line_id' => $orderLine->id, 'quantity_received' => '1']]])
        ->assertForbidden();
});

test('lots and serial numbers are recorded through the inventory module', function (): void {
    $supplierId = procSupplier();
    ['item' => $lotItem, 'warehouse' => $warehouse, 'catalog_id' => $lotCatalog] = procStockedCatalog($this->tenant, $supplierId, 'COLLA', 'lot');
    ['item' => $serialItem, 'catalog_id' => $serialCatalog] = procStockedCatalog($this->tenant, $supplierId, 'TRAPANO', 'serial');
    $order = procOrder($supplierId, [
        ['quantity_ordered' => '5', 'unit_price' => '3', 'supplier_catalog_id' => $lotCatalog],
        ['quantity_ordered' => '2', 'unit_price' => '90', 'supplier_catalog_id' => $serialCatalog],
    ]);
    $this->postJson("/api/v1/procurement/purchase-orders/{$order}/submit-approval")->assertOk();
    [$lotLine, $serialLine] = PurchaseOrder::find($order)->lines()->get()->all();

    procActAs($this->receiver, $this->tenant);
    $this->postJson("/api/v1/procurement/purchase-orders/{$order}/goods-receipt", ['supplier_delivery_note_ref' => 'D', 'received_date' => now()->toDateString(), 'warehouse_id' => $warehouse->id, 'lines' => [['purchase_order_line_id' => $lotLine->id, 'quantity_received' => '5']]])
        ->assertUnprocessable();
    $this->postJson("/api/v1/procurement/purchase-orders/{$order}/goods-receipt", ['supplier_delivery_note_ref' => 'D', 'received_date' => now()->toDateString(), 'warehouse_id' => $warehouse->id, 'lines' => [['purchase_order_line_id' => $serialLine->id, 'quantity_received' => '2', 'serial_numbers' => ['SN-1']]]])
        ->assertUnprocessable();

    $this->postJson("/api/v1/procurement/purchase-orders/{$order}/goods-receipt", ['supplier_delivery_note_ref' => 'D', 'received_date' => now()->toDateString(), 'warehouse_id' => $warehouse->id, 'lines' => [
        ['purchase_order_line_id' => $lotLine->id, 'quantity_received' => '5', 'lot_number' => 'L-2027-01'],
        ['purchase_order_line_id' => $serialLine->id, 'quantity_received' => '2', 'serial_numbers' => ['SN-1', 'SN-2']],
    ]])->assertCreated()->assertJsonPath('lines.1.serial_numbers', ['SN-1', 'SN-2']);

    expect(StockLot::query()->where('stock_item_id', $lotItem->id)->value('lot_number'))->toBe('L-2027-01')
        ->and(StockQuant::query()->where('stock_item_id', $lotItem->id)->whereNotNull('lot_id')->sum('quantity_on_hand'))->toEqual(5)
        ->and(StockSerialNumber::query()->where('stock_item_id', $serialItem->id)->pluck('serial_number')->sort()->values()->all())->toBe(['SN-1', 'SN-2']);
});

test('goods inspected in quarantine are released or returned to the supplier with a credit note request', function (): void {
    $supplierId = procSupplier();
    ['item' => $item, 'warehouse' => $warehouse, 'catalog_id' => $catalogId] = procStockedCatalog($this->tenant, $supplierId, 'VALVOLA');
    $order = procApprovedOrder($supplierId, $catalogId, '5', '40');
    $orderLine = PurchaseOrder::find($order)->lines()->sole();

    procActAs($this->receiver, $this->tenant);
    $receipt = $this->postJson("/api/v1/procurement/purchase-orders/{$order}/goods-receipt", [
        'supplier_delivery_note_ref' => 'DDT-Q', 'received_date' => now()->toDateString(), 'warehouse_id' => $warehouse->id, 'inspection_required' => true,
        'lines' => [['purchase_order_line_id' => $orderLine->id, 'quantity_received' => '5']],
    ])->assertCreated()->assertJsonPath('inspection_status', 'pending')->json();

    expect(procOnHand($item, 'quarantine'))->toBe('5.0000')->and(procOnHand($item, 'receiving'))->toBe('0.0000');
    $receiptLineId = $receipt['lines'][0]['id'];

    $this->postJson("/api/v1/procurement/goods-receipts/{$receipt['id']}/inspection", ['results' => [['purchase_receipt_line_id' => $receiptLineId, 'quantity_released' => '9']]])->assertUnprocessable();
    $this->postJson("/api/v1/procurement/goods-receipts/{$receipt['id']}/inspection", ['results' => [['purchase_receipt_line_id' => $receiptLineId, 'quantity_released' => '3', 'notes' => '2 pezzi rigati']]])
        ->assertOk()->assertJsonPath('inspection_status', 'conditional')->assertJsonPath('lines.0.inspection_status', 'partial');
    $this->postJson("/api/v1/procurement/goods-receipts/{$receipt['id']}/inspection", ['results' => [['purchase_receipt_line_id' => $receiptLineId, 'quantity_released' => '1']]])->assertUnprocessable();

    expect(procOnHand($item, 'quarantine'))->toBe('2.0000')->and(procOnHand($item, 'receiving'))->toBe('3.0000');

    $this->postJson("/api/v1/procurement/purchase-orders/{$order}/returns", ['return_date' => now()->toDateString(), 'reason' => 'Difettosi', 'lines' => [['purchase_receipt_line_id' => $receiptLineId, 'quantity_returned' => '6']]])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity_returned');
    $returnId = $this->postJson("/api/v1/procurement/purchase-orders/{$order}/returns", ['return_date' => now()->toDateString(), 'reason' => 'Difettosi', 'lines' => [['purchase_receipt_line_id' => $receiptLineId, 'quantity_returned' => '2']]])
        ->assertCreated()->json('id');

    $return = PurchaseReturn::find($returnId);
    expect(procOnHand($item, 'quarantine'))->toBe('0.0000')
        ->and(procOnHand($item, 'receiving'))->toBe('3.0000')
        ->and($return->status)->toBe('credit_requested')
        ->and($return->total_amount)->toBe('80.00')
        ->and($return->return_number)->toBe('RTV-'.now()->year.'-0001')
        ->and(procLedger('purchase_return', $returnId, '2010'))->toBe(['debit' => '80.00', 'credit' => '0.00'])
        ->and(procLedger('purchase_return', $returnId, '1200'))->toBe(['debit' => '0.00', 'credit' => '80.00']);

    $creditNote = PassiveInvoice::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'supplier_id' => $supplierId, 'invoice_number' => 'NC-9', 'date' => now()->toDateString(), 'total_amount' => '97.60', 'total_net' => '80.00', 'total_tax' => '17.60', 'currency' => 'EUR', 'document_type' => 'TD04', 'payment_status' => 'unpaid', 'total_paid' => 0]);
    $this->postJson("/api/v1/procurement/returns/{$returnId}/credit-note", [])->assertUnprocessable();
    $this->postJson("/api/v1/procurement/returns/{$returnId}/credit-note", ['passive_invoice_id' => $creditNote->id])->assertOk();
    expect(PurchaseReturn::find($returnId))->status->toBe('credited')->credit_note_reference->toBe('NC-9');
});

test('a supplier invoice beyond the matching tolerance is blocked for payment until the variance is approved', function (): void {
    $supplierId = procSupplier();
    ['warehouse' => $warehouse, 'catalog_id' => $catalogId] = procStockedCatalog($this->tenant, $supplierId, 'MOTORE');
    $order = procApprovedOrder($supplierId, $catalogId, '10', '100');
    $orderLine = PurchaseOrder::find($order)->lines()->sole();
    procActAs($this->receiver, $this->tenant);
    $receipt = $this->postJson("/api/v1/procurement/purchase-orders/{$order}/goods-receipt", ['supplier_delivery_note_ref' => 'D', 'received_date' => now()->toDateString(), 'warehouse_id' => $warehouse->id, 'lines' => [['purchase_order_line_id' => $orderLine->id, 'quantity_received' => '6']]])
        ->assertCreated()->json('id');

    $invoice = fn (string $net): PassiveInvoice => PassiveInvoice::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'supplier_id' => $supplierId, 'invoice_number' => 'FT-'.Str::random(4), 'date' => now()->toDateString(), 'total_net' => $net, 'total_tax' => '0', 'total_amount' => $net, 'currency' => 'EUR', 'document_type' => 'TD01', 'payment_status' => 'unpaid', 'total_paid' => 0]);
    $this->putJson('/api/v1/procurement/settings', ['price_tolerance_percent' => '1'])->assertOk();

    $withinTolerance = $invoice('605.00');
    $this->postJson('/api/v1/procurement/three-way-match/execute', ['purchase_order_id' => $order, 'purchase_receipt_id' => $receipt, 'passive_invoice_id' => $withinTolerance->id, 'invoice_total_amount' => 605])
        ->assertCreated()->assertJsonPath('match_status', 'matched');
    expect($withinTolerance->fresh())->match_status->toBe('matched')->purchase_order_id->toBe($order);

    $over = $invoice('100.00');
    $log = $this->postJson('/api/v1/procurement/three-way-match/execute', ['purchase_order_id' => $order, 'purchase_receipt_id' => $receipt, 'passive_invoice_id' => $over->id, 'invoice_total_amount' => 100])
        ->assertCreated()->assertJsonPath('match_status', 'price_variance')->json('id');

    expect($over->fresh())->approval_status->toBe('match_blocked')->isPaymentBlocked()->toBeTrue();
    procActAs($this->buyer, $this->tenant);
    $this->postJson("/api/v1/purchases/supplier-invoices/{$over->id}/mark-paid")->assertUnprocessable();

    $foreign = PassiveInvoice::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'supplier_id' => procSupplier('Altro'), 'invoice_number' => 'X', 'date' => now()->toDateString(), 'total_net' => '1', 'total_amount' => '1', 'currency' => 'EUR', 'document_type' => 'TD01', 'payment_status' => 'unpaid', 'total_paid' => 0]);
    $this->postJson('/api/v1/procurement/three-way-match/execute', ['purchase_order_id' => $order, 'purchase_receipt_id' => $receipt, 'passive_invoice_id' => $foreign->id, 'invoice_total_amount' => 1])->assertUnprocessable();

    $this->postJson("/api/v1/procurement/three-way-match/{$log}/approve-variance", [])->assertUnprocessable();
    $this->postJson("/api/v1/procurement/three-way-match/{$log}/approve-variance", ['reason' => 'Trasporto concordato'])->assertOk()->assertJsonPath('match_status', 'override_approved');
    expect($over->fresh())->isPaymentBlocked()->toBeFalse()->approval_status->toBe('not_required');
});

test('landed costs are allocated by value or weight and capitalized into stock and ledger', function (): void {
    $supplierId = procSupplier();
    ['item' => $heavy, 'warehouse' => $warehouse, 'catalog_id' => $heavyCatalog] = procStockedCatalog($this->tenant, $supplierId, 'LASTRA');
    ['item' => $light, 'catalog_id' => $lightCatalog] = procStockedCatalog($this->tenant, $supplierId, 'GUARNIZIONE');
    $order = procOrder($supplierId, [
        ['quantity_ordered' => '10', 'unit_price' => '30', 'supplier_catalog_id' => $heavyCatalog, 'unit_weight' => '9'],
        ['quantity_ordered' => '100', 'unit_price' => '1', 'supplier_catalog_id' => $lightCatalog, 'unit_weight' => '0.1'],
    ]);
    $this->postJson("/api/v1/procurement/purchase-orders/{$order}/submit-approval")->assertOk();
    [$heavyLine, $lightLine] = PurchaseOrder::find($order)->lines()->get()->all();
    procActAs($this->receiver, $this->tenant);
    $receipt = $this->postJson("/api/v1/procurement/purchase-orders/{$order}/goods-receipt", ['supplier_delivery_note_ref' => 'D', 'received_date' => now()->toDateString(), 'warehouse_id' => $warehouse->id, 'lines' => [
        ['purchase_order_line_id' => $heavyLine->id, 'quantity_received' => '10'],
        ['purchase_order_line_id' => $lightLine->id, 'quantity_received' => '100'],
    ]])->assertCreated()->json('id');

    $byValue = $this->postJson('/api/v1/procurement/landed-costs', ['cost_type' => 'freight', 'allocation_method' => 'value', 'amount' => '100.00', 'cost_date' => now()->toDateString(), 'purchase_receipt_ids' => [$receipt]])
        ->assertCreated()->json('data');
    expect(collect($byValue['allocations'])->pluck('allocated_amount')->sort()->values()->all())->toBe(['25.00', '75.00']);

    $byWeight = $this->postJson('/api/v1/procurement/landed-costs', ['cost_type' => 'duty', 'allocation_method' => 'weight', 'amount' => '10.00', 'cost_date' => now()->toDateString(), 'purchase_receipt_ids' => [$receipt]])
        ->assertCreated()->json('data');
    expect(collect($byWeight['allocations'])->pluck('allocated_amount')->sort()->values()->all())->toBe(['1.00', '9.00']);
    $this->postJson("/api/v1/procurement/landed-costs/{$byWeight['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

    $this->postJson("/api/v1/procurement/landed-costs/{$byValue['id']}/post")->assertOk()->assertJsonPath('data.status', 'posted');
    $this->postJson("/api/v1/procurement/landed-costs/{$byValue['id']}/post")->assertUnprocessable();

    expect((string) StockItem::find($heavy->id)->current_moving_average_cost)->toBe('37.5000')
        ->and((string) StockItem::find($light->id)->current_moving_average_cost)->toBe('1.2500')
        ->and(PurchaseReceipt::find($receipt)->landed_cost_amount)->toBe('100.00')
        ->and(procLedger('landed_cost', $byValue['id'], '1200'))->toBe(['debit' => '100.00', 'credit' => '0.00'])
        ->and(procLedger('landed_cost', $byValue['id'], '2015'))->toBe(['debit' => '0.00', 'credit' => '100.00']);

    $this->postJson('/api/v1/procurement/landed-costs', ['cost_type' => 'teleport', 'allocation_method' => 'mood', 'amount' => '0', 'cost_date' => 'x', 'purchase_receipt_ids' => [(string) Str::uuid()]])
        ->assertUnprocessable()->assertJsonValidationErrors(['cost_type', 'allocation_method', 'amount', 'cost_date', 'purchase_receipt_ids.0']);
});

test('supplier performance, analytics and price variance are computed from the documents', function (): void {
    $supplierId = procSupplier('Puntuale');
    ['warehouse' => $warehouse, 'catalog_id' => $catalogId] = procStockedCatalog($this->tenant, $supplierId, 'FILTRO');
    $this->postJson("/api/v1/procurement/catalogs/{$catalogId}/prices", ['unit_price' => '10', 'valid_from' => now()->subMonth()->toDateString()])->assertCreated();
    $order = procApprovedOrder($supplierId, $catalogId, '10', '12');
    $orderLine = PurchaseOrder::find($order)->lines()->sole();

    procActAs($this->receiver, $this->tenant);
    $this->postJson("/api/v1/procurement/purchase-orders/{$order}/goods-receipt", ['supplier_delivery_note_ref' => 'D', 'received_date' => now()->toDateString(), 'warehouse_id' => $warehouse->id, 'lines' => [['purchase_order_line_id' => $orderLine->id, 'quantity_received' => '5', 'quantity_rejected' => '1']]])
        ->assertCreated();

    $from = now()->subMonth()->toDateString();
    $to = now()->addDay()->toDateString();
    $this->getJson("/api/v1/procurement/suppliers/{$supplierId}/performance?from={$from}&to={$to}")->assertOk()
        ->assertJsonPath('data.on_time_delivery_rate', '100.00')
        ->assertJsonPath('data.quality_acceptance_rate', '80.00')
        ->assertJsonPath('data.price_competitiveness_score', '83.33')
        ->assertJsonPath('data.overall_rating', 'B');
    $this->postJson("/api/v1/procurement/suppliers/{$supplierId}/evaluations/compute", ['from' => $from, 'to' => $to])->assertCreated()->assertJsonPath('data.overall_rating', 'B');

    $this->getJson("/api/v1/procurement/analytics/spend?from={$from}&to={$to}")->assertOk()->assertJsonPath('data.0.key', $supplierId)->assertJsonPath('data.0.spend', '120.00');
    $this->getJson("/api/v1/procurement/analytics/spend?dimension=category&from={$from}&to={$to}")->assertOk()->assertJsonPath('data.0.spend', '120.00');
    $this->getJson('/api/v1/procurement/analytics/open-orders')->assertOk()->assertJsonPath('data.0.purchase_order_id', $order)->assertJsonPath('data.0.open_value', '72.00');
    $this->getJson("/api/v1/procurement/analytics/price-variance?from={$from}&to={$to}")->assertOk()
        ->assertJsonPath('data.0.variance_amount', '20.00')->assertJsonPath('data.0.variance_percent', '20.00');
    $this->getJson('/api/v1/procurement/analytics/spend?dimension=planet')->assertUnprocessable();
});

test('reorder rules generate purchase suggestions with the cheapest offer, converted into one order per supplier', function (): void {
    $cheap = procSupplier('Economico');
    $dear = procSupplier('Caro');
    ['item' => $item, 'warehouse' => $warehouse, 'catalog_id' => $cheapCatalog] = procStockedCatalog($this->tenant, $cheap, 'OLIO');
    $dearCatalog = $this->postJson('/api/v1/procurement/catalogs', ['supplier_id' => $dear, 'supplier_part_number' => 'OL-X', 'internal_sku' => 'OLIO', 'name' => 'Olio'])->json('data.id');
    $this->postJson("/api/v1/procurement/catalogs/{$cheapCatalog}/prices", ['unit_price' => '4.00', 'valid_from' => now()->subDay()->toDateString()])->assertCreated();
    $this->postJson("/api/v1/procurement/catalogs/{$dearCatalog}/prices", ['unit_price' => '5.00', 'valid_from' => now()->subDay()->toDateString()])->assertCreated();

    StockReorderRule::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $warehouse->id, 'stock_item_id' => $item->id, 'min_stock_level' => 10, 'max_stock_level' => 100, 'reorder_point' => 20, 'economic_order_quantity' => 50, 'lead_time_days' => 5]);

    $suggestion = $this->postJson('/api/v1/procurement/purchase-suggestions/generate')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.supplier_id', $cheap)->assertJsonPath('data.0.quantity', '100.0000')->assertJsonPath('data.0.unit_price', '4.0000')->json('data.0.id');
    $this->postJson('/api/v1/procurement/purchase-suggestions/generate')->assertOk()->assertJsonCount(0, 'data');

    $orders = $this->postJson('/api/v1/procurement/purchase-suggestions/convert', ['suggestion_ids' => [$suggestion]])->assertCreated()->json('data');
    expect($orders)->toHaveCount(1)->and($orders[0]['supplier_id'])->toBe($cheap)->and($orders[0]['net_amount'])->toBe('400.00');
    $this->getJson('/api/v1/procurement/purchase-suggestions?status=converted')->assertOk()->assertJsonCount(1, 'data');

    // The open order now covers the item: nothing more is suggested.
    $this->postJson('/api/v1/procurement/purchase-suggestions/generate')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/procurement/purchase-suggestions/{$suggestion}/dismiss")->assertUnprocessable();
});

test('MRP planned purchase orders become suggestions and a replacement return reopens the order', function (): void {
    $supplierId = procSupplier();
    ['item' => $item, 'warehouse' => $warehouse, 'catalog_id' => $catalogId] = procStockedCatalog($this->tenant, $supplierId, 'LAMIERA');
    $this->postJson("/api/v1/procurement/catalogs/{$catalogId}/prices", ['unit_price' => '7.00', 'valid_from' => now()->subDay()->toDateString()])->assertCreated();

    $runId = (string) Str::uuid();
    $mfgItemId = (string) Str::uuid();
    DB::table('mfg_mrp_runs')->insert(['id' => $runId, 'tenant_id' => $this->tenant->id, 'run_number' => 'MRP-1', 'status' => 'completed', 'horizon_start_date' => now()->toDateString(), 'horizon_end_date' => now()->addMonth()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('mfg_items')->insert(['id' => $mfgItemId, 'tenant_id' => $this->tenant->id, 'item_code' => 'LAMIERA', 'name' => 'Lamiera', 'item_type' => 'raw_material', 'stock_item_id' => $item->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('mfg_mrp_planned_orders')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'planned_order_number' => 'PL-1', 'mrp_run_id' => $runId, 'mfg_item_id' => $mfgItemId, 'order_type' => 'buy', 'quantity' => 30, 'start_date' => now()->toDateString(), 'due_date' => now()->addDays(9)->toDateString(), 'status' => 'planned', 'created_at' => now(), 'updated_at' => now()]);

    $suggestion = $this->postJson('/api/v1/procurement/purchase-suggestions/generate')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.source', 'mrp')->assertJsonPath('data.0.quantity', '30.0000')->assertJsonPath('data.0.required_by_date', now()->addDays(9)->toDateString())->json('data.0.id');
    $order = $this->postJson('/api/v1/procurement/purchase-suggestions/convert', ['suggestion_ids' => [$suggestion]])->assertCreated()->json('data.0.id');
    $this->getJson("/api/v1/procurement/purchase-orders/{$order}")->assertOk()->assertJsonPath('lines.0.unit_price', '7.0000')->assertJsonPath('expected_delivery_date', now()->addDays(9)->toDateString());

    $this->postJson("/api/v1/procurement/purchase-orders/{$order}/submit-approval")->assertOk();
    $orderLine = PurchaseOrder::find($order)->lines()->sole();
    procActAs($this->receiver, $this->tenant);
    $this->postJson("/api/v1/procurement/purchase-orders/{$order}/goods-receipt", ['supplier_delivery_note_ref' => 'D', 'received_date' => now()->toDateString(), 'warehouse_id' => $warehouse->id, 'lines' => [['purchase_order_line_id' => $orderLine->id, 'quantity_received' => '30']]])->assertCreated();
    expect(PurchaseOrder::find($order)->status)->toBe('received');

    $this->postJson("/api/v1/procurement/purchase-orders/{$order}/returns", ['return_date' => now()->toDateString(), 'return_type' => 'replacement', 'lines' => [['purchase_order_line_id' => $orderLine->id, 'quantity_returned' => '5']]])
        ->assertCreated();
    expect(PurchaseOrder::find($order)->status)->toBe('partially_received')
        ->and(procOnHand($item, 'receiving'))->toBe('25.0000')
        ->and(PurchaseReturn::query()->where('purchase_order_id', $order)->value('status'))->toBe('replacement_requested');
});

test('a legacy order confirmed through the purchasing API needs the approval the procurement matrix asks for', function (): void {
    $supplierId = procSupplier();
    $this->postJson('/api/v1/procurement/approval-matrices', ['target' => 'purchase_order', 'name' => 'Big', 'tiers' => [['tier_level' => 1, 'approver_type' => 'role', 'approver_value' => 'owner', 'threshold_min' => '1000']]])->assertCreated();

    $small = $this->postJson('/api/v1/purchases/orders', ['supplier_id' => $supplierId, 'date' => now()->toDateString(), 'currency' => 'EUR', 'items' => [['description' => 'A', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22]]])->json('data.id');
    $big = $this->postJson('/api/v1/purchases/orders', ['supplier_id' => $supplierId, 'date' => now()->toDateString(), 'currency' => 'EUR', 'items' => [['description' => 'B', 'quantity' => 10, 'unit_price' => 500, 'tax_rate' => 22]]])->json('data.id');

    $this->postJson("/api/v1/purchases/orders/{$small}/confirm")->assertOk()->assertJsonPath('data.status', 'sent');
    $this->postJson("/api/v1/purchases/orders/{$big}/confirm")->assertUnprocessable();
    expect(PurchaseOrder::find($big)->total_amount)->toBe('6100.00');
    $this->postJson("/api/v1/purchases/orders/{$small}/receive", ['lines' => []])->assertCreated();
    expect(PurchaseOrder::find($small)->status)->toBe('received');
});

test('a read-only member reads procurement but cannot write anything', function (): void {
    $supplierId = procSupplier();
    $order = procOrder($supplierId, [['description' => 'A', 'quantity_ordered' => '1', 'unit_price' => '1', 'uom' => 'PCE']]);
    procActAs(procMember($this->tenant, 'accountant_readonly'), $this->tenant);
    $id = (string) Str::uuid();

    foreach (['/api/v1/procurement/purchase-orders', "/api/v1/procurement/purchase-orders/{$order}", '/api/v1/procurement/settings', '/api/v1/procurement/approval-matrices', '/api/v1/procurement/catalogs', '/api/v1/procurement/rfqs', '/api/v1/procurement/landed-costs', '/api/v1/procurement/purchase-suggestions', '/api/v1/procurement/analytics/spend', '/api/v1/procurement/analytics/open-orders', '/api/v1/procurement/supplier-documents/expiring'] as $readable) {
        $this->getJson($readable)->assertOk();
    }

    $writes = [
        ['put', '/api/v1/procurement/settings'], ['post', '/api/v1/procurement/approval-matrices'], ['delete', "/api/v1/procurement/approval-matrices/{$id}"],
        ['post', '/api/v1/procurement/catalogs'], ['put', "/api/v1/procurement/catalogs/{$id}"], ['post', "/api/v1/procurement/catalogs/{$id}/prices"],
        ['put', "/api/v1/procurement/suppliers/{$supplierId}/qualification"], ['post', "/api/v1/procurement/suppliers/{$supplierId}/documents"], ['delete', "/api/v1/procurement/supplier-documents/{$id}"],
        ['post', '/api/v1/procurement/supplier-qualifications/expire'], ['post', "/api/v1/procurement/suppliers/{$supplierId}/evaluations/compute"],
        ['post', '/api/v1/procurement/rfqs'], ['post', "/api/v1/procurement/rfqs/{$id}/issue"], ['post', "/api/v1/procurement/rfqs/{$id}/quotes"], ['post', "/api/v1/procurement/rfqs/{$id}/award"], ['post', "/api/v1/procurement/rfqs/{$id}/cancel"],
        ['post', "/api/v1/procurement/contracts/{$id}/lines"], ['post', "/api/v1/procurement/contracts/{$id}/call-offs"],
        ['post', '/api/v1/procurement/purchase-orders'], ['put', "/api/v1/procurement/purchase-orders/{$order}"], ['post', "/api/v1/procurement/purchase-orders/{$order}/submit-approval"], ['post', "/api/v1/procurement/purchase-orders/{$order}/send"],
        ['post', "/api/v1/procurement/goods-receipts/{$id}/inspection"], ['post', "/api/v1/procurement/returns/{$id}/credit-note"], ['post', "/api/v1/procurement/three-way-match/{$id}/approve-variance"],
        ['post', '/api/v1/procurement/landed-costs'], ['post', "/api/v1/procurement/landed-costs/{$id}/post"], ['post', "/api/v1/procurement/landed-costs/{$id}/cancel"],
        ['post', '/api/v1/procurement/purchase-suggestions/generate'], ['post', '/api/v1/procurement/purchase-suggestions/convert'], ['post', "/api/v1/procurement/purchase-suggestions/{$id}/dismiss"],
        ['post', '/api/v1/purchases/orders'],
    ];

    foreach ($writes as [$method, $uri]) {
        $this->json($method, $uri, [])->assertForbidden();
    }
});

test('records of another company are not found and cannot be referenced', function (): void {
    $foreignAdmin = procMember($this->otherTenant, 'admin');
    procActAs($foreignAdmin, $this->otherTenant);
    $foreignSupplier = procSupplier('Estero');
    ['catalog_id' => $foreignCatalog] = procStockedCatalog($this->otherTenant, $foreignSupplier, 'EST');
    $foreignOrder = procOrder($foreignSupplier, [['description' => 'A', 'quantity_ordered' => '1', 'unit_price' => '1', 'uom' => 'PCE']]);
    $foreignRfq = $this->postJson('/api/v1/procurement/rfqs', ['title' => 'E', 'response_deadline' => now()->addDay()->toDateString(), 'supplier_ids' => [$foreignSupplier], 'lines' => [['description' => 'X', 'quantity' => '1']]])->json('data.id');
    $foreignLanded = DB::table('procurement_landed_costs')->insertGetId(['id' => (string) Str::uuid(), 'tenant_id' => $this->otherTenant->id, 'landed_cost_number' => 'LC-X', 'cost_type' => 'freight', 'allocation_method' => 'value', 'status' => 'draft', 'amount' => 1, 'currency' => 'EUR', 'cost_date' => now()->toDateString()], 'id');

    procActAs($this->buyer, $this->tenant);
    foreach (["/api/v1/procurement/purchase-orders/{$foreignOrder}", "/api/v1/procurement/catalogs/{$foreignCatalog}", "/api/v1/procurement/rfqs/{$foreignRfq}", "/api/v1/procurement/rfqs/{$foreignRfq}/comparison", "/api/v1/procurement/landed-costs/{$foreignLanded}", "/api/v1/procurement/suppliers/{$foreignSupplier}/documents", "/api/v1/procurement/suppliers/{$foreignSupplier}/performance"] as $uri) {
        $this->getJson($uri)->assertNotFound();
    }

    $this->postJson("/api/v1/procurement/rfqs/{$foreignRfq}/issue")->assertNotFound();
    $this->postJson("/api/v1/procurement/landed-costs/{$foreignLanded}/post")->assertNotFound();
    $this->postJson("/api/v1/procurement/purchase-orders/{$foreignOrder}/send")->assertNotFound();
    $this->putJson("/api/v1/procurement/suppliers/{$foreignSupplier}/qualification", ['qualification_status' => 'qualified'])->assertNotFound();
    $this->postJson('/api/v1/procurement/purchase-orders', ['supplier_id' => $foreignSupplier, 'order_date' => now()->toDateString(), 'expected_delivery_date' => now()->toDateString(), 'lines' => [['quantity_ordered' => '1', 'unit_price' => '1']]])
        ->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
    $this->postJson('/api/v1/procurement/landed-costs', ['cost_type' => 'freight', 'allocation_method' => 'value', 'amount' => '1', 'cost_date' => now()->toDateString(), 'purchase_receipt_ids' => [(string) Str::uuid()]])
        ->assertUnprocessable()->assertJsonValidationErrors('purchase_receipt_ids.0');
    $this->getJson('/api/v1/procurement/analytics/spend')->assertOk()->assertJsonCount(0, 'data');
});
