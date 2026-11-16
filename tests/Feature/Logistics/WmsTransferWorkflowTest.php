<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\TranslationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Inventory\Models\StockMovement;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Logistics\Enums\DiscrepancyStatus;
use Modules\Logistics\Enums\TransferStatus;
use Modules\Logistics\Models\ConsignmentAgreement;
use Modules\Logistics\Models\StockTransferOrder;
use Modules\Logistics\Models\TransferDiscrepancy;
use Modules\Logistics\Queries\InventoryValuationReportQuery;
use Modules\Logistics\Services\ConsignmentService;
use Modules\Logistics\Services\StockTransferService;
use Modules\Logistics\Services\ThirdPartyStockRegisterService;
use Modules\Logistics\Services\WarehouseStockService;
use Modules\Procurement\Models\PurchaseOrder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(TranslationSeeder::class);
    app()->setLocale('en');
    ['tenant' => $this->tenant, 'user' => $this->user] = wmsTenant('WMS');
    app(TenantContext::class)->setTenant($this->tenant);
    $this->stock = app(WarehouseStockService::class);
    $this->transfers = app(StockTransferService::class);
    $this->consignment = app(ConsignmentService::class);
    $this->product = wmsProduct($this->tenant->id, 'WMS-1', 10, 30);
    $this->main = wmsWarehouse($this->tenant->id, 'MAIN');
    $this->branch = wmsWarehouse($this->tenant->id, 'BRANCH');
    $this->stock->receiveOpeningStock($this->main->id, $this->product->id, 100);
});

function wmsTransfer(object $t, string $purpose, string $origin, string $destination, float $qty = 10, bool $approve = true): StockTransferOrder
{
    $order = $t->transfers->create([
        'purpose' => $purpose, 'origin_warehouse_id' => $origin, 'destination_warehouse_id' => $destination,
    ], [['product_id' => $t->product->id, 'quantity' => $qty]]);

    return $approve ? $t->transfers->approve($order) : $order;
}

test('state machine: ship requires approval, cancel works before dispatch and closed is terminal', function (): void {
    $draft = wmsTransfer($this, 'inter_warehouse', $this->main->id, $this->branch->id, 5, approve: false);
    expect($draft->status)->toBe('draft')
        ->and(fn () => $this->transfers->ship($draft))->toThrow(DomainException::class, 'Only approved transfers can be shipped')
        ->and(fn () => $this->transfers->close($draft))->toThrow(DomainException::class);

    $approved = $this->transfers->approve($draft, (string) $this->user->id);
    expect($approved->status)->toBe('approved')
        ->and($approved->approved_by)->toBe($this->user->id)
        ->and($approved->approved_at)->not->toBeNull()
        ->and(fn () => $this->transfers->approve($approved))->toThrow(DomainException::class);
    expect($this->transfers->cancel($approved)->status)->toBe('cancelled');

    $order = $this->transfers->ship(wmsTransfer($this, 'inter_warehouse', $this->main->id, $this->branch->id, 5));
    expect(fn () => $this->transfers->cancel($order))->toThrow(DomainException::class);

    $received = $this->transfers->receive($order);
    expect($received->status)->toBe('received');
    $closed = $this->transfers->close($received);
    expect($closed->status)->toBe('closed')->and($closed->closed_at)->not->toBeNull()
        ->and(fn () => $this->transfers->close($closed))->toThrow(DomainException::class)
        ->and(fn () => $this->transfers->receive($closed))->toThrow(DomainException::class);

    expect(TransferStatus::Draft->canTransitionTo(TransferStatus::InTransit))->toBeFalse()
        ->and(TransferStatus::InTransit->canTransitionTo(TransferStatus::PartiallyReceived))->toBeTrue()
        ->and(TransferStatus::Closed->allowedTransitions())->toBe([]);
});

test('ledger entries carry legal ownership, third party and in-transit tracking separate from the physical location', function (): void {
    $customer = wmsCustomer($this->tenant->id);
    $deposit = wmsWarehouse($this->tenant->id, 'CLI-DEP', ['warehouse_type' => 'customer_consignment', 'linked_partner_id' => $customer->id]);
    $supplierId = (string) Str::uuid7();
    $terzista = wmsWarehouse($this->tenant->id, 'TERZISTA', ['warehouse_type' => 'subcontractor', 'linked_partner_id' => $supplierId]);

    $toCustomer = $this->transfers->receive($this->transfers->ship(wmsTransfer($this, 'customer_consignment', $this->main->id, $deposit->id, 10)));
    $this->transfers->receive($this->transfers->ship(wmsTransfer($this, 'subcontracting', $this->main->id, $terzista->id, 7)));

    $movements = StockMovement::query()->where('origin_document_id', $toCustomer->id)->get();
    $shipOut = $movements->firstWhere('movement_type', 'transfer_ship_out');
    $inTransit = $movements->firstWhere('movement_type', 'transfer_ship_in');
    $receiveIn = $movements->firstWhere('movement_type', 'transfer_receive_in');

    expect($shipOut->ownership_type)->toBe('owned')->and((bool) $shipOut->in_transit)->toBeFalse()
        ->and((bool) $inTransit->in_transit)->toBeTrue()->and($inTransit->ownership_type)->toBe('owned')
        ->and($receiveIn->ownership_type)->toBe('customer_consignment')->and($receiveIn->third_party_id)->toBe($customer->id)->and((bool) $receiveIn->in_transit)->toBeFalse();

    $atTerzista = StockMovement::query()->where('warehouse_id', $terzista->id)->first();
    expect($atTerzista->ownership_type)->toBe('subcontractor_held')->and($atTerzista->third_party_id)->toBe($supplierId);

    $vendorWh = wmsWarehouse($this->tenant->id, 'VEND', ['warehouse_type' => 'vendor_consignment', 'legal_owner_type' => 'vendor', 'linked_partner_id' => (string) Str::uuid7()]);
    $this->consignment->receiveVendorStock($vendorWh->id, [['product_id' => $this->product->id, 'quantity' => 4]]);
    expect(StockMovement::query()->where('warehouse_id', $vendorWh->id)->value('ownership_type'))->toBe('vendor_consignment');
});

test('partial receipts keep the rest in transit and the last one completes the transfer', function (): void {
    $order = $this->transfers->ship(wmsTransfer($this, 'inter_warehouse', $this->main->id, $this->branch->id, 40));
    $line = $order->lines->first();

    $partial = $this->transfers->receive($order, [['line_id' => $line->id, 'received_qty' => 15]], final: false);
    expect($partial->status)->toBe('partially_received')
        ->and($partial->discrepancies()->count())->toBe(0)
        ->and($this->stock->onHand($this->branch->id, $this->product->id))->toBe(15.0)
        ->and($this->stock->onHand($partial->transit_warehouse_id, $this->product->id))->toBe(25.0);

    // cannot receive more than what is still outstanding
    expect(fn () => $this->transfers->receive($partial, [['line_id' => $line->id, 'received_qty' => 26]], final: false))->toThrow(DomainException::class);

    $done = $this->transfers->receive($partial, [['line_id' => $line->id, 'received_qty' => 25]], final: false);
    expect($done->status)->toBe('received')
        ->and((float) $done->lines->first()->received_qty)->toBe(40.0)
        ->and($this->stock->onHand($this->branch->id, $this->product->id))->toBe(40.0)
        ->and($this->stock->onHand($done->transit_warehouse_id, $this->product->id))->toBe(0.0);
});

test('a final receipt after a partial one turns the outstanding quantity into a shortage discrepancy', function (): void {
    $order = $this->transfers->ship(wmsTransfer($this, 'inter_warehouse', $this->main->id, $this->branch->id, 40));
    $line = $order->lines->first();
    $this->transfers->receive($order, [['line_id' => $line->id, 'received_qty' => 30]], final: false);

    $final = $this->transfers->receive($order, [['line_id' => $line->id, 'received_qty' => 5, 'discrepancy_reason' => 'Pallet missing']]);
    $discrepancy = $final->discrepancies->first();

    expect($final->status)->toBe('discrepancy_pending')
        ->and((float) $discrepancy->shortage_qty)->toBe(5.0)
        ->and((float) $discrepancy->damage_qty)->toBe(0.0)
        ->and((float) $discrepancy->discrepancy_qty)->toBe(5.0)
        ->and($this->stock->onHand($this->branch->id, $this->product->id))->toBe(35.0)
        ->and($this->stock->onHand($final->transit_warehouse_id, $this->product->id))->toBe(5.0);
});

test('damaged units are quarantined in transit and reconciled together with shortages after manager approval', function (): void {
    $order = $this->transfers->ship(wmsTransfer($this, 'inter_warehouse', $this->main->id, $this->branch->id, 40));
    $line = $order->lines->first();

    $received = $this->transfers->receive($order, [['line_id' => $line->id, 'received_qty' => 30, 'damaged_qty' => 6, 'discrepancy_reason' => 'Water damage']]);
    $d = $received->discrepancies->first();

    expect($received->status)->toBe('discrepancy_pending')
        ->and((float) $d->shortage_qty)->toBe(4.0)->and((float) $d->damage_qty)->toBe(6.0)->and((float) $d->discrepancy_qty)->toBe(10.0)
        ->and($this->stock->onHand($this->branch->id, $this->product->id))->toBe(30.0)
        ->and($this->stock->onHand($received->transit_warehouse_id, $this->product->id))->toBe(10.0);

    // close is not possible while the discrepancy is open
    expect(fn () => $this->transfers->close($received))->toThrow(DomainException::class);

    $resolved = $this->transfers->resolveDiscrepancy($d, DiscrepancyStatus::CarrierClaim, (string) $this->user->id);
    expect($resolved->status)->toBe('carrier_claim')
        ->and($this->stock->onHand($received->transit_warehouse_id, $this->product->id))->toBe(0.0)
        ->and(StockMovement::query()->where('movement_type', 'transfer_carrier_claim_out')->count())->toBe(1)
        ->and($received->fresh()->status)->toBe('closed');

    // received + damaged cannot exceed what is outstanding
    $second = $this->transfers->ship(wmsTransfer($this, 'inter_warehouse', $this->main->id, $this->branch->id, 10));
    expect(fn () => $this->transfers->receive($second, [['line_id' => $second->lines->first()->id, 'received_qty' => 8, 'damaged_qty' => 3]]))->toThrow(DomainException::class);
});

test('inter-company transfers draft the inter-company invoice through Domain 01 and enforce entity rules', function (): void {
    $entityA = (string) Str::uuid7();
    $entityB = (string) Str::uuid7();
    $buyer = wmsCustomer($this->tenant->id, 'Group Company B Srl');
    $this->main->update(['company_entity_id' => $entityA]);
    $groupB = wmsWarehouse($this->tenant->id, 'GROUP-B', ['company_entity_id' => $entityB, 'linked_partner_id' => $buyer->id]);

    $order = $this->transfers->ship(wmsTransfer($this, 'inter_company', $this->main->id, $groupB->id, 10));

    expect($order->is_inter_company)->toBeTrue()->and($order->inter_company_invoice_id)->not->toBeNull();
    $invoice = SalesInvoice::with('items')->findOrFail($order->inter_company_invoice_id);
    expect($invoice->status)->toBe('draft')
        ->and($invoice->contact_id)->toBe($buyer->id)
        ->and((float) $invoice->items->first()->quantity)->toBe(10.0)
        ->and((float) $invoice->items->first()->unit_price)->toBe(10.0) // transfer at cost
        ->and($invoice->items->first()->description)->toContain($order->transfer_number);
    expect(SalesDeliveryNote::findOrFail($order->delivery_note_id)->type)->toBe('internal_transfer');

    // plain inter-warehouse moves never generate an invoice
    expect($this->transfers->ship(wmsTransfer($this, 'inter_warehouse', $this->main->id, $this->branch->id, 1))->inter_company_invoice_id)->toBeNull();

    $sameEntity = wmsWarehouse($this->tenant->id, 'GROUP-A2', ['company_entity_id' => $entityA, 'linked_partner_id' => $buyer->id]);
    expect(fn () => wmsTransfer($this, 'inter_company', $this->main->id, $sameEntity->id))->toThrow(DomainException::class, 'different legal entities');
    $noPartner = wmsWarehouse($this->tenant->id, 'GROUP-C', ['company_entity_id' => (string) Str::uuid7()]);
    expect(fn () => wmsTransfer($this, 'inter_company', $this->main->id, $noPartner->id))->toThrow(DomainException::class, 'linked partner');
});

test('every movement type produces its DDT with the fiscal transport reason and progressive numbering', function (): void {
    $customer = wmsCustomer($this->tenant->id);
    $deposit = wmsWarehouse($this->tenant->id, 'CLI-DEP', ['warehouse_type' => 'customer_consignment', 'linked_partner_id' => $customer->id]);
    $supplierId = (string) Str::uuid7();
    $terzista = wmsWarehouse($this->tenant->id, 'TERZISTA', ['warehouse_type' => 'subcontractor', 'linked_partner_id' => $supplierId]);
    $year = now()->format('Y');

    $ddt = fn (StockTransferOrder $o) => SalesDeliveryNote::findOrFail($o->delivery_note_id);
    $run = fn (string $purpose, string $from, string $to, float $qty) => $this->transfers->receive($this->transfers->ship(wmsTransfer($this, $purpose, $from, $to, $qty)));

    $internal = $ddt($run('inter_warehouse', $this->main->id, $this->branch->id, 5));
    $internal2 = $ddt($run('technician_van', $this->main->id, wmsWarehouse($this->tenant->id, 'VAN', ['warehouse_type' => 'mobile_van'])->id, 5));
    $consign = $ddt($run('customer_consignment', $this->main->id, $deposit->id, 20));
    $consignReturn = $ddt($run('consignment_return', $deposit->id, $this->branch->id, 5));
    $subOut = $ddt($run('subcontracting', $this->main->id, $terzista->id, 10));
    $subBack = $ddt($run('subcontracting_return', $terzista->id, $this->branch->id, 4));

    expect($internal->transport_reason_code)->toBe('internal_transfer')->and($internal->ddt_number)->toBe("DDT-TR-{$year}-00001")
        ->and($internal2->ddt_number)->toBe("DDT-TR-{$year}-00002")
        ->and($consign->type)->toBe('customer_consignment')->and($consign->transport_reason_code)->toBe('consignment_delivery')->and($consign->contact_id)->toBe($customer->id)
        ->and($consign->ddt_number)->toBe("DDT-CD-{$year}-00001")
        ->and($consignReturn->type)->toBe('customer_consignment_return')->and($consignReturn->transport_reason_code)->toBe('consignment_return')->and($consignReturn->contact_id)->toBe($customer->id)
        ->and($consignReturn->ddt_number)->toBe("DDT-CD-{$year}-00002")
        ->and($subOut->type)->toBe('subcontracting_out')->and($subOut->transport_reason_code)->toBe('subcontracting')->and($subOut->ddt_number)->toBe("DDT-CL-{$year}-00001")
        ->and($subBack->type)->toBe('subcontracting_return')->and($subBack->transport_reason_code)->toBe('subcontractor_return')->and($subBack->supplier_id)->toBe($supplierId)
        ->and($subBack->ddt_number)->toBe("DDT-CL-{$year}-00002");

    // goods are back on our premises: 100 - 5 - 5 - 20 - 10 + 5 + 4 on MAIN/BRANCH/VAN/deposit/terzista
    expect($this->stock->onHand($deposit->id, $this->product->id))->toBe(15.0)
        ->and($this->stock->onHand($terzista->id, $this->product->id))->toBe(6.0)
        ->and($this->stock->onHand($this->branch->id, $this->product->id))->toBe(14.0);

    // returns must start from the matching third-party location and land on our own premises
    expect(fn () => wmsTransfer($this, 'consignment_return', $this->main->id, $this->branch->id))->toThrow(DomainException::class, 'customer_consignment')
        ->and(fn () => wmsTransfer($this, 'subcontracting_return', $terzista->id, $deposit->id))->toThrow(DomainException::class);
});

test('valuation segregates owned on-hand, in-transit, at customer, at subcontractor and vendor consignment held stock', function (): void {
    $customer = wmsCustomer($this->tenant->id);
    $deposit = wmsWarehouse($this->tenant->id, 'CLI-DEP', ['warehouse_type' => 'customer_consignment', 'linked_partner_id' => $customer->id]);
    $terzista = wmsWarehouse($this->tenant->id, 'TERZISTA', ['warehouse_type' => 'subcontractor', 'linked_partner_id' => (string) Str::uuid7()]);
    $vendorWh = wmsWarehouse($this->tenant->id, 'VEND', ['warehouse_type' => 'vendor_consignment', 'legal_owner_type' => 'vendor', 'linked_partner_id' => (string) Str::uuid7()]);

    $this->transfers->receive($this->transfers->ship(wmsTransfer($this, 'customer_consignment', $this->main->id, $deposit->id, 20)));   // 200 at customer
    $this->transfers->receive($this->transfers->ship(wmsTransfer($this, 'subcontracting', $this->main->id, $terzista->id, 10)));        // 100 at subcontractor
    $this->transfers->ship(wmsTransfer($this, 'inter_warehouse', $this->main->id, $this->branch->id, 5));                            // 50 in transit
    $this->consignment->receiveVendorStock($vendorWh->id, [['product_id' => $this->product->id, 'quantity' => 30]]);                   // 300 vendor owned

    $report = app(InventoryValuationReportQuery::class)->execute();
    expect($report['buckets'])->toBe([
        'owned_on_hand' => 650.0,
        'owned_in_transit' => 50.0,
        'owned_at_customer_consignment' => 200.0,
        'owned_at_subcontractor' => 100.0,
        'third_party_vendor_consignment_held' => 300.0,
    ])->and($report['total_balance_sheet_value'])->toBe(1000.0)->and($report['excluded_third_party_value'])->toBe(300.0);

    $register = app(ThirdPartyStockRegisterService::class)->register();
    expect($register['goods_at_customers'])->toHaveCount(1)->and($register['goods_at_customers'][0]['rows'][0]['residual_qty'])->toBe(20.0)
        ->and($register['goods_at_subcontractors'])->toHaveCount(1)->and($register['goods_at_subcontractors'][0]['total_fiscal_value'])->toBe(100.0)
        ->and($register['vendor_consignment_held'])->toHaveCount(1)->and($register['vendor_consignment_held'][0]['on_balance_sheet'])->toBeFalse()
        ->and($register['valuation']['buckets']['owned_in_transit'])->toBe(50.0);
});

test('vendor consignment: receipt keeps vendor ownership, declaration shifts it to us and drafts the purchase document', function (): void {
    $supplier = Contact::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'company_name' => 'Fornitore Conto Deposito', 'type' => 'supplier', 'country' => 'IT']);
    $vendorWh = wmsWarehouse($this->tenant->id, 'VEND', ['warehouse_type' => 'vendor_consignment', 'legal_owner_type' => 'vendor', 'linked_partner_id' => $supplier->id]);

    expect(fn () => $this->consignment->receiveVendorStock($this->main->id, [['product_id' => $this->product->id, 'quantity' => 1]]))->toThrow(DomainException::class);
    $this->consignment->receiveVendorStock($vendorWh->id, [['product_id' => $this->product->id, 'quantity' => 50]]);
    expect(app(InventoryValuationReportQuery::class)->execute()['excluded_third_party_value'])->toBe(500.0);

    $agreement = $this->consignment->createAgreement(['warehouse_id' => $vendorWh->id, 'valid_from' => now()->toDateString(), 'billing_frequency' => 'quarterly']);
    expect($agreement->direction)->toBe('vendor')->and($agreement->partner_id)->toBe($supplier->id)->and($agreement->status)->toBe('active');

    $declaration = $this->consignment->declareConsumption([
        'warehouse_id' => $vendorWh->id, 'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
    ], [['product_id' => $this->product->id, 'quantity' => 20]]);

    expect($declaration->agreement_id)->toBe($agreement->id)
        ->and($declaration->ownership_from)->toBe('vendor_consignment')->and($declaration->ownership_to)->toBe('owned')
        ->and($declaration->generated_document_type)->toBe('purchase_order')
        ->and(PurchaseOrder::findOrFail($declaration->generated_document_id)->supplier_id)->toBe($supplier->id)
        ->and($this->stock->onHand($vendorWh->id, $this->product->id))->toBe(30.0);
    $consumed = StockMovement::query()->where('movement_type', 'consignment_consumption_out')->firstOrFail();
    expect($consumed->ownership_type)->toBe('vendor_consignment')->and($consumed->third_party_id)->toBe($supplier->id);

    $this->consignment->terminateAgreement($agreement);
    expect($agreement->fresh()->status)->toBe('terminated')
        ->and(fn () => $this->consignment->declareConsumption([
            'warehouse_id' => $vendorWh->id, 'agreement_id' => $agreement->id, 'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'quantity' => 1]]))->toThrow(DomainException::class, 'not active');
});

test('customer consignment declaration ships ownership to the customer and bills a draft sales invoice', function (): void {
    $customer = wmsCustomer($this->tenant->id);
    $deposit = wmsWarehouse($this->tenant->id, 'CLI-DEP', ['warehouse_type' => 'customer_consignment', 'linked_partner_id' => $customer->id]);
    $this->transfers->receive($this->transfers->ship(wmsTransfer($this, 'customer_consignment', $this->main->id, $deposit->id, 30)));

    $declaration = $this->consignment->declareConsumption([
        'warehouse_id' => $deposit->id, 'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->toDateString(),
    ], [['product_id' => $this->product->id, 'quantity' => 12]]);

    $invoice = SalesInvoice::findOrFail($declaration->generated_document_id);
    expect($declaration->ownership_from)->toBe('customer_consignment')->and($declaration->ownership_to)->toBe('transferred_to_customer')
        ->and($invoice->contact_id)->toBe($customer->id)
        ->and(app(InventoryValuationReportQuery::class)->execute()['buckets']['owned_at_customer_consignment'])->toBe(180.0);
});

test('WMS API: approval is a manager permission, discrepancies resolve through the nested route and scans match lines', function (): void {
    Sanctum::actingAs($this->user);
    $h = ['X-Tenant-Id' => $this->tenant->id];
    $this->product->update(['barcode' => '8001234567890']);

    $id = $this->postJson('/api/v1/wms/transfers', [
        'purpose' => 'inter_warehouse', 'origin_warehouse_id' => $this->main->id, 'destination_warehouse_id' => $this->branch->id,
        'lines' => [['product_id' => $this->product->id, 'quantity' => 12]],
    ], $h)->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

    // the warehouse operator can dispatch/receive but cannot approve or resolve
    $operator = wmsUserWithRole($this->tenant->id, 'warehouse_operator');
    Sanctum::actingAs($operator);
    $this->postJson("/api/v1/wms/transfers/{$id}/approve", [], $h)->assertForbidden()->assertJsonPath('required_permission', 'logistics.transfers.approve');
    $this->getJson('/api/v1/wms/transfers?status=draft', $h)->assertOk()->assertJsonCount(1, 'data');

    Sanctum::actingAs($this->user);
    $this->postJson("/api/v1/wms/transfers/{$id}/approve", [], $h)->assertOk()->assertJsonPath('data.status', 'approved');

    Sanctum::actingAs($operator);
    $ship = $this->postJson("/api/v1/wms/transfers/{$id}/ship", ['carrier_name' => 'Van'], $h)->assertOk()->assertJsonPath('data.status', 'in_transit');
    $lineId = $ship->json('data.lines.0.id');

    $this->getJson("/api/v1/wms/transfers?status=in_transit&warehouse_id={$this->branch->id}&direction=inbound", $h)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/wms/transfers?status=in_transit&warehouse_id={$this->branch->id}&direction=outbound", $h)->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/wms/transfers/{$id}/scan", ['code' => '8001234567890'], $h)->assertOk()->assertJsonPath('data.line.id', $lineId)->assertJsonPath('data.outstanding_qty', 12);
    $this->postJson("/api/v1/wms/transfers/{$id}/scan", ['code' => 'WMS-1'], $h)->assertOk()->assertJsonPath('data.line.id', $lineId);
    $this->postJson("/api/v1/wms/transfers/{$id}/scan", ['code' => 'unknown'], $h)->assertUnprocessable();

    $this->postJson("/api/v1/wms/transfers/{$id}/receive", ['lines' => [['line_id' => $lineId, 'received_qty' => 8, 'damaged_qty' => 2]]], $h)
        ->assertOk()->assertJsonPath('data.status', 'discrepancy_pending')->assertJsonPath('data.discrepancies.0.shortage_qty', '2.0000')->assertJsonPath('data.discrepancies.0.damage_qty', '2.0000');
    $discrepancyId = TransferDiscrepancy::query()->firstOrFail()->id;

    $this->postJson("/api/v1/wms/transfers/{$id}/discrepancies/{$discrepancyId}/resolve", ['resolution' => 'approved_write_off'], $h)->assertForbidden();
    Sanctum::actingAs($this->user);
    $this->postJson("/api/v1/wms/transfers/{$id}/discrepancies/".Str::uuid().'/resolve', ['resolution' => 'approved_write_off'], $h)->assertNotFound();
    $this->postJson("/api/v1/wms/transfers/{$id}/discrepancies/{$discrepancyId}/resolve", ['resolution' => 'nope'], $h)->assertUnprocessable();
    $this->postJson("/api/v1/wms/transfers/{$id}/discrepancies/{$discrepancyId}/resolve", ['resolution' => 'returned_to_origin'], $h)->assertOk()->assertJsonPath('data.status', 'returned_to_origin');
    $this->getJson("/api/v1/wms/transfers/{$id}", $h)->assertOk()->assertJsonPath('data.status', 'closed');
    $this->postJson("/api/v1/wms/transfers/{$id}/close", [], $h)->assertUnprocessable();
});

test('WMS API: third-party register, valuation report, agreements and declarations', function (): void {
    $customer = wmsCustomer($this->tenant->id);
    $deposit = wmsWarehouse($this->tenant->id, 'CLI-DEP', ['warehouse_type' => 'customer_consignment', 'linked_partner_id' => $customer->id]);
    $this->transfers->receive($this->transfers->ship(wmsTransfer($this, 'customer_consignment', $this->main->id, $deposit->id, 30)));
    Sanctum::actingAs($this->user);
    $h = ['X-Tenant-Id' => $this->tenant->id];

    $this->getJson('/api/v1/wms/reports/third-party-register', $h)->assertOk()
        ->assertJsonPath('data.goods_at_customers.0.rows.0.residual_qty', 30)
        ->assertJsonPath('data.valuation.buckets.owned_at_customer_consignment', 300);
    $this->getJson('/api/v1/wms/reports/inventory-valuation', $h)->assertOk()->assertJsonPath('data.buckets.owned_on_hand', 700);

    $this->postJson('/api/v1/wms/consignment/agreements', ['warehouse_id' => $deposit->id, 'valid_from' => now()->toDateString()], $h)
        ->assertCreated()->assertJsonPath('data.direction', 'customer')->assertJsonPath('data.billing_frequency', 'monthly');
    $this->postJson('/api/v1/wms/consignment/agreements', ['warehouse_id' => $this->main->id, 'valid_from' => now()->toDateString()], $h)->assertUnprocessable();
    $this->getJson('/api/v1/wms/consignment/agreements?direction=customer', $h)->assertOk()->assertJsonCount(1, 'data');

    $this->postJson('/api/v1/wms/consignment/declarations', [
        'warehouse_id' => $deposit->id, 'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->toDateString(),
        'lines' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 40]],
    ], $h)->assertCreated()->assertJsonPath('data.status', 'posted')->assertJsonPath('data.ownership_to', 'transferred_to_customer');
    $this->getJson('/api/v1/wms/consignment/declarations', $h)->assertOk()->assertJsonCount(1, 'data');

    $vendorWh = wmsWarehouse($this->tenant->id, 'VEND', ['warehouse_type' => 'vendor_consignment', 'legal_owner_type' => 'vendor', 'linked_partner_id' => (string) Str::uuid7()]);
    $this->postJson('/api/v1/wms/consignment/vendor-receipts', ['warehouse_id' => $vendorWh->id, 'lines' => [['product_id' => $this->product->id, 'quantity' => 9]]], $h)->assertCreated();
    $this->getJson('/api/v1/wms/reports/third-party-register', $h)->assertOk()->assertJsonPath('data.valuation.buckets.third_party_vendor_consignment_held', 90);

    $agreementId = ConsignmentAgreement::query()->firstOrFail()->id;
    $this->postJson("/api/v1/wms/consignment/agreements/{$agreementId}/terminate", [], $h)->assertOk()->assertJsonPath('data.status', 'terminated');
});

test('multi-tenant isolation: transfers, discrepancies, declarations, agreements and registers never leak across tenants', function (): void {
    $customer = wmsCustomer($this->tenant->id);
    $deposit = wmsWarehouse($this->tenant->id, 'CLI-DEP', ['warehouse_type' => 'customer_consignment', 'linked_partner_id' => $customer->id]);
    $order = $this->transfers->receive($this->transfers->ship(wmsTransfer($this, 'customer_consignment', $this->main->id, $deposit->id, 30)), [['line_id' => StockTransferOrder::query()->firstOrFail()->lines()->firstOrFail()->id, 'received_qty' => 28]]);
    $discrepancy = $order->discrepancies->first();
    $declaration = $this->consignment->declareConsumption([
        'warehouse_id' => $deposit->id, 'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
    ], [['product_id' => $this->product->id, 'quantity' => 3]]);
    $agreement = $this->consignment->createAgreement(['warehouse_id' => $deposit->id, 'valid_from' => now()->toDateString()]);

    ['tenant' => $other, 'user' => $intruder] = wmsTenant('Other WMS');
    Sanctum::actingAs($intruder);
    $h = ['X-Tenant-Id' => $other->id];

    $this->getJson('/api/v1/wms/transfers', $h)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/wms/transfers/{$order->id}", $h)->assertNotFound();
    foreach (['approve', 'ship', 'receive', 'close', 'cancel'] as $action) {
        $this->postJson("/api/v1/wms/transfers/{$order->id}/{$action}", [], $h)->assertNotFound();
    }
    $this->postJson("/api/v1/wms/transfers/{$order->id}/discrepancies/{$discrepancy->id}/resolve", ['resolution' => 'approved_write_off'], $h)->assertNotFound();
    $this->postJson("/api/v1/wms/transfer-discrepancies/{$discrepancy->id}/resolve", ['resolution' => 'approved_write_off'], $h)->assertNotFound();
    $this->getJson('/api/v1/wms/transfer-discrepancies', $h)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/wms/consignment/declarations', $h)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/wms/consignment/agreements', $h)->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/wms/consignment/agreements/{$agreement->id}/terminate", [], $h)->assertNotFound();
    $this->getJson('/api/v1/wms/reports/third-party-register', $h)->assertOk()
        ->assertJsonPath('data.goods_at_customers', [])->assertJsonPath('data.valuation.buckets.owned_on_hand', 0);
    $this->postJson('/api/v1/wms/consignment/declarations', [
        'warehouse_id' => $deposit->id, 'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
        'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
    ], $h)->assertNotFound();

    expect($declaration->tenant_id)->toBe($this->tenant->id);
});
