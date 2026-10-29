<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Modules\Invoicing\Contracts\SalesQuoteServiceInterface;
use Modules\Invoicing\Models\SalesQuote;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'Quote Test Tenant', 'legal_name' => 'Quote Test Tenant Srl']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->customer = Contact::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'company_name' => 'Test Customer Srl'],
        ['id' => (string) Str::uuid7(), 'type' => 'client', 'country' => 'IT']
    );

    $this->product1 = Product::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'sku' => 'PRD-QUOTE-01'],
        ['id' => (string) Str::uuid7(), 'name' => 'Professional Software License', 'sale_price' => 1000.00, 'cost_price' => 300.00, 'tax_rate' => 22.0]
    );

    $this->product2 = Product::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'sku' => 'PRD-QUOTE-02'],
        ['id' => (string) Str::uuid7(), 'name' => 'Implementation Consulting Hour', 'sale_price' => 120.00, 'cost_price' => 50.00, 'tax_rate' => 22.0]
    );

    $this->quoteService = app(SalesQuoteServiceInterface::class);
});

test('creates sales quote with accurate multi-line calculations', function (): void {
    $quote = $this->quoteService->createQuote([
        'tenant_id' => (string) $this->tenant->id,
        'contact_id' => (string) $this->customer->id,
        'quote_number' => 'Q-2027-0001',
        'notes' => 'Commercial Proposal for Q1',
    ], [
        [
            'product_id' => (string) $this->product1->id,
            'description' => 'Professional Software License',
            'quantity' => 2.0,
            'unit_price' => 1000.00,
            'tax_rate' => 22.0,
            'discount_percentage' => 10.0,
        ],
        [
            'product_id' => (string) $this->product2->id,
            'description' => 'Implementation Consulting Hour',
            'quantity' => 10.0,
            'unit_price' => 120.00,
            'tax_rate' => 22.0,
            'discount_percentage' => 0.0,
        ],
    ]);

    expect($quote)->toBeInstanceOf(SalesQuote::class);
    expect($quote->items)->toHaveCount(2);

    // Line 1: 2 * 1000 = 2000 - 10% (200) = 1800 net.
    // Line 2: 10 * 120 = 1200 - 0% = 1200 net.
    // Subtotal: 3200
    // Discount total: 200
    // Net total: 3000
    // Tax total: 3000 * 0.22 = 660
    // Gross total: 3660
    expect((float) $quote->subtotal)->toBe(3200.00);
    expect((float) $quote->discount_amount)->toBe(200.00);
    expect((float) $quote->total_net)->toBe(3000.00);
    expect((float) $quote->total_tax)->toBe(660.00);
    expect((float) $quote->total_gross)->toBe(3660.00);
    expect($quote->status)->toBe('draft');
});

test('approves and accepts sales quote, then converts into confirmed sales order', function (): void {
    $quote = $this->quoteService->createQuote([
        'tenant_id' => (string) $this->tenant->id,
        'contact_id' => (string) $this->customer->id,
        'quote_number' => 'Q-2027-CONV',
    ], [
        [
            'product_id' => (string) $this->product1->id,
            'description' => 'Professional Software License',
            'quantity' => 5.0,
            'unit_price' => 1000.00,
            'tax_rate' => 22.0,
            'discount_percentage' => 0.0,
        ],
    ]);

    $approverId = (string) Str::uuid7();
    $quote = $this->quoteService->approveQuote($quote, $approverId);
    expect($quote->status)->toBe('approved');
    expect($quote->approved_by)->toBe($approverId);

    $quote = $this->quoteService->acceptQuote($quote);
    expect($quote->status)->toBe('accepted');
    expect($quote->signed_at)->not->toBeNull();

    $order = $this->quoteService->convertToOrder($quote, 'sales-agent');

    expect($order)->not->toBeNull();
    expect($order->quote_id)->toBe((string) $quote->id);
    expect((float) $order->total_gross)->toBe((float) $quote->total_gross);
    expect($order->items)->toHaveCount(1);
    expect((float) $order->items->first()->quantity)->toBe(5.0);

    expect($quote->fresh()->status)->toBe('converted');
    expect($quote->fresh()->converted_to_order_id)->toBe((string) $order->id);
});
