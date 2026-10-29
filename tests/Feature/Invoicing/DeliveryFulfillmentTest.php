<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Modules\Invoicing\Contracts\DeliveryFulfillmentServiceInterface;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'Fulfillment Test Tenant', 'legal_name' => 'Fulfillment Test Tenant Srl']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->customer = Contact::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'company_name' => 'Logistics Client SpA'],
        ['id' => (string) Str::uuid7(), 'type' => 'client', 'country' => 'IT']
    );

    $this->product = Product::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'sku' => 'PRD-FULFILL-01'],
        ['id' => (string) Str::uuid7(), 'name' => 'Industrial Gateway Unit', 'sale_price' => 500.00, 'cost_price' => 200.00, 'tax_rate' => 22.0]
    );

    $this->orderService = app(SalesOrderServiceInterface::class);
    $this->fulfillmentService = app(DeliveryFulfillmentServiceInterface::class);
});

test('creates delivery note, dispatches goods and tracks fulfillment progress', function (): void {
    $order = $this->orderService->createOrder([
        'tenant_id' => (string) $this->tenant->id,
        'contact_id' => (string) $this->customer->id,
        'order_number' => 'SO-FULFILL-01',
    ], [
        [
            'product_id' => (string) $this->product->id,
            'description' => 'Industrial Gateway Unit',
            'quantity' => 10.0,
            'unit_price' => 500.00,
            'tax_rate' => 22.0,
        ],
    ]);

    $orderItem = $order->items->first();

    // Partial fulfillment: deliver 4 out of 10
    $dn1 = $this->fulfillmentService->createDeliveryNote($order, [
        'ddt_number' => 'DDT-2027-PARTIAL',
        'carrier_name' => 'BRT Bartolini',
    ], [
        [
            'sales_order_item_id' => (string) $orderItem->id,
            'quantity' => 4.0,
            'batch_number' => 'BATCH-2027-01',
        ],
    ]);

    expect($dn1->items)->toHaveCount(1);
    expect((float) $dn1->items->first()->quantity)->toBe(4.0);

    $freshOrder = $order->fresh(['items']);
    expect((float) $freshOrder->items->first()->quantity_delivered)->toBe(4.0);
    expect($freshOrder->fulfillment_status)->toBe('partial');

    // Dispatch delivery note 1
    $dispatchedDn1 = $this->fulfillmentService->dispatchDelivery($dn1, 'warehouse-manager');
    expect($dispatchedDn1->shipping_status)->toBe('dispatched');
    expect($dispatchedDn1->status)->toBe('issued');

    // Confirm receipt
    $confirmedDn1 = $this->fulfillmentService->confirmDelivery($dispatchedDn1, 'logistics-agent');
    expect($confirmedDn1->shipping_status)->toBe('delivered');

    // Second delivery note: deliver remaining 6 out of 10
    $dn2 = $this->fulfillmentService->createDeliveryNote($order, [
        'ddt_number' => 'DDT-2027-FINAL',
    ], [
        [
            'sales_order_item_id' => (string) $orderItem->id,
            'quantity' => 6.0,
        ],
    ]);

    $fullyFulfilledOrder = $order->fresh(['items']);
    expect((float) $fullyFulfilledOrder->items->first()->quantity_delivered)->toBe(10.0);
    expect($fullyFulfilledOrder->fulfillment_status)->toBe('fulfilled');
    expect($fullyFulfilledOrder->status)->toBe('shipped');
});
