<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Logistics\Models\DeliveryNoteInvoiceLink;
use Modules\Logistics\Services\DeferredInvoicingService;
use Modules\Logistics\Services\DeliveryNoteService;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->user] = logisticsTenant('Fulfillment');
    app(TenantContext::class)->setTenant($this->tenant);
    $this->customer = logisticsCustomer($this->tenant->id);
    $this->product = logisticsProduct($this->tenant->id, 'FF-1', 10, 50);
    $this->ddt = app(DeliveryNoteService::class);
    $this->orders = app(SalesOrderServiceInterface::class);
});

function logisticsOrder(string $tenantId, string $customerId, string $productId, float $qty, string $number): SalesOrder
{
    return app(SalesOrderServiceInterface::class)->createOrder([
        'tenant_id' => $tenantId, 'contact_id' => $customerId, 'order_number' => $number,
    ], [['product_id' => $productId, 'description' => 'Gateway', 'quantity' => $qty, 'unit_price' => 50.0, 'tax_rate' => 22.0]]);
}

test('a DDT fulfils an order partially then totally, updating delivered quantities', function (): void {
    $order = logisticsOrder($this->tenant->id, $this->customer->id, $this->product->id, 10, 'SO-A');
    $line = $order->items->first();

    $first = $this->ddt->createFromOrders([['sales_order_id' => $order->id, 'items' => [['sales_order_item_id' => $line->id, 'quantity' => 4]]]]);
    expect($first->items)->toHaveCount(1)
        ->and((float) $first->items->first()->quantity)->toBe(4.0)
        ->and($order->fresh('items')->items->first()->quantity_delivered)->toEqual('4.0000')
        ->and($order->fresh()->fulfillment_status)->toBe('partial');

    // Over-shipping is rejected
    expect(fn () => $this->ddt->createFromOrders([['sales_order_id' => $order->id, 'items' => [['sales_order_item_id' => $line->id, 'quantity' => 7]]]]))
        ->toThrow(DomainException::class);

    // Without explicit lines the remaining quantity is shipped
    $second = $this->ddt->createFromOrders([['sales_order_id' => $order->id]]);
    expect((float) $second->items->first()->quantity)->toBe(6.0)
        ->and($order->fresh()->fulfillment_status)->toBe('fulfilled')
        ->and($second->ddt_number)->toBe('DDT-'.now()->format('Y').'-00002');
});

test('one DDT can fulfil several orders of the same customer but never of different customers', function (): void {
    $o1 = logisticsOrder($this->tenant->id, $this->customer->id, $this->product->id, 2, 'SO-B1');
    $o2 = logisticsOrder($this->tenant->id, $this->customer->id, $this->product->id, 3, 'SO-B2');
    $other = logisticsCustomer($this->tenant->id, 'Altro Cliente');
    $o3 = logisticsOrder($this->tenant->id, $other->id, $this->product->id, 1, 'SO-B3');

    $note = $this->ddt->createFromOrders([['sales_order_id' => $o1->id], ['sales_order_id' => $o2->id]]);
    expect($note->items)->toHaveCount(2)->and($note->sales_order_id)->toBeNull();

    expect(fn () => $this->ddt->createFromOrders([['sales_order_id' => $o3->id], ['sales_order_id' => logisticsOrder($this->tenant->id, $this->customer->id, $this->product->id, 1, 'SO-B4')->id]]))
        ->toThrow(DomainException::class, 'same customer');
});

test('cancelling a draft DDT releases the delivered quantities back to the order', function (): void {
    $order = logisticsOrder($this->tenant->id, $this->customer->id, $this->product->id, 5, 'SO-C');
    $note = $this->ddt->createFromOrders([['sales_order_id' => $order->id]]);
    expect($order->fresh()->fulfillment_status)->toBe('fulfilled');

    $cancelled = $this->ddt->cancel($note);
    expect($cancelled->status)->toBe('cancelled')
        ->and($order->fresh('items')->items->first()->quantity_delivered)->toEqual('0.0000')
        ->and($order->fresh()->fulfillment_status)->toBe('unfulfilled');
});

test('cumulative deferred invoice groups several DDTs and tracks the line links and statuses', function (): void {
    $order = logisticsOrder($this->tenant->id, $this->customer->id, $this->product->id, 10, 'SO-D');
    $line = $order->items->first();
    $a = $this->ddt->createFromOrders([['sales_order_id' => $order->id, 'items' => [['sales_order_item_id' => $line->id, 'quantity' => 4]]]]);
    $b = $this->ddt->createFromOrders([['sales_order_id' => $order->id]]);
    $a = $this->ddt->issue($a);
    $b = $this->ddt->issue($b);
    expect($a->status)->toBe('issued');

    $invoice = app(DeferredInvoicingService::class)->invoice([$a->id, $b->id]);

    expect($invoice)->toBeInstanceOf(SalesInvoice::class)
        ->and($invoice->items)->toHaveCount(2)
        ->and((float) $invoice->items->sum('quantity'))->toBe(10.0)
        ->and($invoice->items->first()->description)->toContain($a->ddt_number)
        ->and(DeliveryNoteInvoiceLink::count())->toBe(2)
        ->and($a->fresh()->status)->toBe('invoiced')
        ->and($b->fresh()->status)->toBe('invoiced');

    $link = DeliveryNoteInvoiceLink::query()->where('delivery_note_id', $a->id)->first();
    expect($link->invoice_id)->toBe($invoice->id)
        ->and($link->delivery_note_item_id)->toBe($a->items->first()->id)
        ->and($invoice->items->pluck('id')->contains($link->invoice_item_id))->toBeTrue();

    // Already fully invoiced: cannot be billed twice
    expect(fn () => app(DeferredInvoicingService::class)->invoice([$a->id]))->toThrow(DomainException::class);
});

test('deferred invoicing enforces customer, currency, calendar month and status rules', function (): void {
    $service = app(DeferredInvoicingService::class);
    $mk = fn (string $customerId, array $extra = []) => $this->ddt->issue($this->ddt->create(array_merge([
        'type' => 'outbound_sale', 'contact_id' => $customerId,
    ], $extra), [['description' => 'Goods', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22]]));

    $other = logisticsCustomer($this->tenant->id, 'Altro');
    expect(fn () => $service->invoice([$mk($this->customer->id)->id, $mk($other->id)->id]))->toThrow(DomainException::class, 'same customer');
    expect(fn () => $service->invoice([$mk($this->customer->id)->id, $mk($this->customer->id, ['currency' => 'CHF'])->id]))->toThrow(DomainException::class, 'same currency');
    expect(fn () => $service->invoice([$mk($this->customer->id)->id, $mk($this->customer->id, ['date' => now()->subMonths(2)->toDateString()])->id]))->toThrow(DomainException::class, 'calendar month');

    // Drafts are not invoiceable
    $draft = $this->ddt->create(['type' => 'outbound_sale', 'contact_id' => $this->customer->id], [['description' => 'Goods', 'quantity' => 1, 'unit_price' => 1]]);
    expect(fn () => $service->invoice([$draft->id]))->toThrow(DomainException::class, 'not invoiceable');

    // The month rule can be relaxed explicitly
    $old = $mk($this->customer->id, ['date' => now()->subMonths(2)->toDateString()]);
    $new = $mk($this->customer->id);
    expect($service->invoice([$old->id, $new->id], enforceSameMonth: false)->items)->toHaveCount(2);
});

test('partial invoicing marks the DDT as partially invoiced', function (): void {
    $note = $this->ddt->issue($this->ddt->create(['type' => 'outbound_sale', 'contact_id' => $this->customer->id], [
        ['description' => 'A', 'quantity' => 2, 'unit_price' => 10, 'tax_rate' => 22],
        ['description' => 'B', 'quantity' => 3, 'unit_price' => 10, 'tax_rate' => 22],
    ]));
    $item = $note->items->first();
    $item->update(['invoiced_quantity' => 2]); // line A already billed elsewhere

    app(DeferredInvoicingService::class)->invoice([$note->id]);
    expect($note->fresh()->status)->toBe('invoiced');

    $note2 = $this->ddt->issue($this->ddt->create(['type' => 'outbound_sale', 'contact_id' => $this->customer->id], [
        ['description' => 'A', 'quantity' => 5, 'unit_price' => 10, 'tax_rate' => 22],
    ]));
    $note2->items->first()->update(['invoiced_quantity' => 1]);
    $inv = app(DeferredInvoicingService::class)->invoice([$note2->id]);
    expect((float) $inv->items->first()->quantity)->toBe(4.0);
});

test('delivery lifecycle over the API: create, issue, deliver with signature, then PDF', function (): void {
    Sanctum::actingAs($this->user);
    $h = ['X-Tenant-Id' => $this->tenant->id];

    $id = $this->postJson('/api/v1/logistics/delivery-notes', [
        'type' => 'outbound_sale', 'contact_id' => $this->customer->id, 'incoterms' => 'DAP', 'carriage_terms' => 'free', 'transport_by' => 'carrier',
        'carrier_name' => 'BRT', 'carrier_vat_number' => 'IT01234567890', 'packages_count' => 2, 'gross_weight_kg' => 10, 'net_weight_kg' => 9, 'volume_m3' => 0.2,
        'items' => [['product_id' => $this->product->id, 'description' => 'Widget', 'quantity' => 2, 'unit_price' => 50, 'tax_rate' => 22]],
    ], $h)->assertCreated()->json('data.id');

    $this->postJson("/api/v1/logistics/delivery-notes/{$id}/issue", [], $h)->assertOk()->assertJsonPath('data.status', 'issued');
    $this->postJson("/api/v1/logistics/delivery-notes/{$id}/deliver", ['recipient_signature_name' => 'Mario Rossi'], $h)
        ->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.recipient_signature_name', 'Mario Rossi');
    $this->postJson("/api/v1/logistics/delivery-notes/{$id}/cancel", [], $h)->assertUnprocessable();

    $this->getJson('/api/v1/logistics/delivery-notes?status=delivered&type=outbound_sale', $h)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/logistics/delivery-notes?status=draft', $h)->assertOk()->assertJsonCount(0, 'data');

    $invoice = $this->postJson('/api/v1/logistics/delivery-notes/deferred-invoice', ['delivery_note_ids' => [$id]], $h)->assertCreated();
    expect($invoice->json('data.invoice_type'))->toBe('deferred')
        ->and(SalesDeliveryNote::find($id)->status)->toBe('invoiced');
});

test('delivery note PDF renders with the extended transport block', function (): void {
    Sanctum::actingAs($this->user);
    $h = ['X-Tenant-Id' => $this->tenant->id];
    $note = $this->ddt->create([
        'type' => 'outbound_sale', 'contact_id' => $this->customer->id, 'incoterms' => 'DDP', 'carriage_terms' => 'collect', 'transport_by' => 'recipient',
    ], [['description' => 'Widget', 'quantity' => 1, 'unit_price' => 5, 'tax_rate' => 22]]);

    $response = $this->get("/api/v1/logistics/delivery-notes/{$note->id}/pdf", $h);
    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and(substr((string) $response->getContent(), 0, 4))->toBe('%PDF');
});
