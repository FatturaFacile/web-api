<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\InvoiceMatchingFixtures as Fixtures;

require_once __DIR__.'/InvoiceMatchingSupport.php';

uses(RefreshDatabase::class);

const FLOW_API = '/api/v1/procurement/document-flow';

beforeEach(function (): void {
    matchingSetUp($this);
});

/**
 * @param  array<string, mixed>  $flow
 * @return list<string>
 */
function flowTypes(array $flow): array
{
    $types = array_map(static fn (array $node): string => $node['type'], $flow['nodes']);
    sort($types);

    return $types;
}

it('requires authentication', function (): void {
    $this->refreshApplication();

    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id)->getJson(FLOW_API.'/purchase_order/'.Str::uuid7())->assertUnauthorized();
});

it('links the order, its receipt, its invoice and the 3-way match status', function (): void {
    $order = matchingReceivedOrder($this);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, [['Industrial Fan Unit', 10, '110.00'], ['Mounting Kit', 5, '20.00']]);
    $run = matchingRun($this, $invoice, (string) $order->id);

    $response = $this->getJson(FLOW_API."/purchase_order/{$order->id}")->assertOk();
    $flow = $response->json('data');

    expect(flowTypes($flow))->toBe(['goods_receipt', 'purchase_order', 'supplier_invoice']);

    $invoiceNode = collect($flow['nodes'])->firstWhere('type', 'supplier_invoice');
    expect($invoiceNode['status'])->toBe('blocked_discrepancy')
        ->and($invoiceNode['run_id'])->toBe((string) $run->id);

    $orderNode = collect($flow['nodes'])->firstWhere('type', 'purchase_order');
    expect($orderNode['is_anchor'])->toBeTrue();

    $relations = collect($flow['edges'])->pluck('relation')->sort()->values()->all();
    expect($relations)->toBe(['matched_by', 'received_by']);
});

it('reports the ordered, received, invoiced and remaining quantities', function (): void {
    $order = Fixtures::order($this->tenant, $this->supplier, $this->poLines);
    Fixtures::receive($this->tenant, $order, [1 => 4, 2 => 0], (string) $this->owner->id);

    $quantities = $this->getJson(FLOW_API."/purchase_order/{$order->id}")->assertOk()->json('data.quantities');

    expect((float) $quantities['ordered'])->toBe(15.0)
        ->and((float) $quantities['received'])->toBe(4.0)
        ->and((float) $quantities['remaining'])->toBe(11.0);
});

it('resolves the same flow from the receipt and from the invoice', function (): void {
    $order = matchingReceivedOrder($this);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, $this->poLines);
    matchingRun($this, $invoice, (string) $order->id);
    $receiptId = (string) $order->receipts()->firstOrFail()->id;

    $fromReceipt = $this->getJson(FLOW_API."/goods_receipt/{$receiptId}")->assertOk()->json('data');
    $fromInvoice = $this->getJson(FLOW_API."/supplier_invoice/{$invoice->id}")->assertOk()->json('data');

    expect(flowTypes($fromReceipt))->toBe(['goods_receipt', 'purchase_order', 'supplier_invoice'])
        ->and(flowTypes($fromInvoice))->toBe(flowTypes($fromReceipt))
        ->and(collect($fromInvoice['nodes'])->firstWhere('is_anchor', true)['type'])->toBe('supplier_invoice');
});

it('pulls every order settled by one consolidated invoice into the flow', function (): void {
    $first = matchingReceivedOrder($this);
    $second = matchingReceivedOrder($this);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, $this->poLines);
    matchingRun($this, $invoice, (string) $first->id);
    matchingRun($this, $invoice, (string) $second->id);

    $flow = $this->getJson(FLOW_API."/purchase_order/{$first->id}")->assertOk()->json('data');

    expect(collect($flow['nodes'])->where('type', 'purchase_order')->count())->toBe(2)
        ->and($flow['orders'])->toHaveCount(2);
});

it('rejects unknown document types and returns 404 for missing documents', function (): void {
    $this->getJson(FLOW_API.'/payment/'.Str::uuid7())->assertNotFound();
    $this->getJson(FLOW_API.'/purchase_order/'.Str::uuid7())->assertNotFound();
    $this->getJson(FLOW_API.'/purchase_order/not-a-uuid')->assertNotFound();
});

it('keeps the document flow of a company away from the others', function (): void {
    $order = matchingReceivedOrder($this);

    treasuryActor($this, 'owner', $this->otherTenant);

    $this->getJson(FLOW_API."/purchase_order/{$order->id}")->assertNotFound();
});
