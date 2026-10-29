<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Modules\Invoicing\Contracts\CustomerCreditServiceInterface;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Models\SalesOrderHold;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'Credit Hold Test Tenant', 'legal_name' => 'Credit Hold Test Tenant Srl']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->customer = Contact::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'company_name' => 'Credit Sensitive Client Srl'],
        ['id' => (string) Str::uuid7(), 'type' => 'client', 'country' => 'IT']
    );

    $this->product = Product::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'sku' => 'PRD-CREDIT-01'],
        ['id' => (string) Str::uuid7(), 'name' => 'High Value Server Hardware', 'sale_price' => 10000.00, 'cost_price' => 6000.00, 'tax_rate' => 22.0]
    );

    $this->orderService = app(SalesOrderServiceInterface::class);
    $this->creditService = app(CustomerCreditServiceInterface::class);
});

test('places sales order on credit hold when order total exceeds customer credit limit', function (): void {
    // Set a strict credit limit of 15,000 EUR
    $this->creditService->updateCreditLimit((string) $this->customer->id, 15000.00, 'credit-manager', (string) $this->tenant->id);

    // Create an order of 2 servers: 2 * 10,000 = 20,000 + 22% tax = 24,400 EUR (exceeds 15,000)
    $order = $this->orderService->createOrder([
        'tenant_id' => (string) $this->tenant->id,
        'contact_id' => (string) $this->customer->id,
        'order_number' => 'SO-CREDIT-HOLD-01',
    ], [
        [
            'product_id' => (string) $this->product->id,
            'description' => 'High Value Server Hardware',
            'quantity' => 2.0,
            'unit_price' => 10000.00,
            'tax_rate' => 22.0,
        ],
    ]);

    // Submit order -> should trigger credit check and place hold
    $submittedOrder = $this->orderService->submitOrder($order, 'sales-rep');

    expect($submittedOrder->status)->toBe('on_hold');
    expect($submittedOrder->credit_status)->toBe('on_hold');
    expect($submittedOrder->credit_hold_reason)->toContain('credit limit');

    $activeHold = SalesOrderHold::where('sales_order_id', (string) $order->id)->where('status', 'active')->first();
    expect($activeHold)->not->toBeNull();
    expect($activeHold->hold_type)->toBe('credit_limit');
});

test('releases credit hold and transitions order to confirmed status', function (): void {
    $this->creditService->updateCreditLimit((string) $this->customer->id, 10000.00, 'credit-manager', (string) $this->tenant->id);

    $order = $this->orderService->createOrder([
        'tenant_id' => (string) $this->tenant->id,
        'contact_id' => (string) $this->customer->id,
        'order_number' => 'SO-RELEASE-01',
    ], [
        [
            'product_id' => (string) $this->product->id,
            'description' => 'High Value Server Hardware',
            'quantity' => 1.0,
            'unit_price' => 10000.00,
            'tax_rate' => 22.0,
        ],
    ]);

    $this->orderService->submitOrder($order, 'sales-rep');
    $hold = SalesOrderHold::where('sales_order_id', (string) $order->id)->first();

    expect($hold)->not->toBeNull();

    // Manager releases the hold
    $releasedHold = $this->creditService->releaseHold($hold, 'risk-officer', 'Exception approved by CFO');

    expect($releasedHold->status)->toBe('released');
    expect($releasedHold->release_notes)->toBe('Exception approved by CFO');

    $freshOrder = $order->fresh();
    expect($freshOrder->status)->toBe('confirmed');
    expect($freshOrder->credit_status)->toBe('approved');
});

test('reserves inventory and updates line item reservation quantities', function (): void {
    $order = $this->orderService->createOrder([
        'tenant_id' => (string) $this->tenant->id,
        'contact_id' => (string) $this->customer->id,
        'order_number' => 'SO-RESERVE-01',
    ], [
        [
            'product_id' => (string) $this->product->id,
            'description' => 'High Value Server Hardware',
            'quantity' => 3.0,
            'unit_price' => 10000.00,
            'tax_rate' => 22.0,
        ],
    ]);

    $reservedOrder = $this->orderService->reserveInventory($order);

    expect($reservedOrder->inventory_status)->toBe('reserved');
    $item = $reservedOrder->items->first();
    expect((float) $item->quantity_reserved)->toBe(3.0);
});
