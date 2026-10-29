<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Modules\Invoicing\Contracts\CustomerLedgerServiceInterface;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Listeners\TreasuryCollectionEventListener;
use Modules\Treasury\Events\CollectionReceivedEvent;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'Aging Test Tenant', 'legal_name' => 'Aging Test Tenant Srl']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->customer = Contact::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'company_name' => 'Overdue Risk Client SpA'],
        ['id' => (string) Str::uuid7(), 'type' => 'client', 'country' => 'IT']
    );

    $this->product = Product::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'sku' => 'PRD-AGING-01'],
        ['id' => (string) Str::uuid7(), 'name' => 'Standard Component', 'sale_price' => 1000.00, 'cost_price' => 400.00, 'tax_rate' => 0.0]
    );

    $this->invoicingService = app(InvoicingServiceInterface::class);
    $this->ledgerService = app(CustomerLedgerServiceInterface::class);
});

test('computes accurate overdue aging buckets across standard credit intervals', function (): void {
    $tenantId = (string) $this->tenant->id;
    $customerId = (string) $this->customer->id;

    // Invoice 1: Current (due in 15 days) -> 1,000 EUR
    $inv1 = $this->invoicingService->createInvoice([
        'tenant_id' => $tenantId,
        'contact_id' => $customerId,
        'invoice_number' => 'INV-CURRENT',
        'date' => now()->toDateString(),
        'due_date' => now()->addDays(15)->toDateString(),
    ], [
        ['product_id' => (string) $this->product->id, 'description' => 'Item', 'quantity' => 1.0, 'unit_price' => 1000.00, 'tax_rate' => 0.0],
    ]);
    $this->invoicingService->postInvoice($inv1, 'auditor');

    // Invoice 2: 15 days overdue (due 15 days ago) -> 2,000 EUR
    $inv2 = $this->invoicingService->createInvoice([
        'tenant_id' => $tenantId,
        'contact_id' => $customerId,
        'invoice_number' => 'INV-15-DAYS',
        'date' => now()->subDays(45)->toDateString(),
        'due_date' => now()->subDays(15)->toDateString(),
    ], [
        ['product_id' => (string) $this->product->id, 'description' => 'Item', 'quantity' => 2.0, 'unit_price' => 1000.00, 'tax_rate' => 0.0],
    ]);
    $this->invoicingService->postInvoice($inv2, 'auditor');

    // Invoice 3: 45 days overdue (due 45 days ago) -> 3,000 EUR
    $inv3 = $this->invoicingService->createInvoice([
        'tenant_id' => $tenantId,
        'contact_id' => $customerId,
        'invoice_number' => 'INV-45-DAYS',
        'date' => now()->subDays(75)->toDateString(),
        'due_date' => now()->subDays(45)->toDateString(),
    ], [
        ['product_id' => (string) $this->product->id, 'description' => 'Item', 'quantity' => 3.0, 'unit_price' => 1000.00, 'tax_rate' => 0.0],
    ]);
    $this->invoicingService->postInvoice($inv3, 'auditor');

    // Invoice 4: 95 days overdue (due 95 days ago) -> 4,000 EUR
    $inv4 = $this->invoicingService->createInvoice([
        'tenant_id' => $tenantId,
        'contact_id' => $customerId,
        'invoice_number' => 'INV-95-DAYS',
        'date' => now()->subDays(125)->toDateString(),
        'due_date' => now()->subDays(95)->toDateString(),
    ], [
        ['product_id' => (string) $this->product->id, 'description' => 'Item', 'quantity' => 4.0, 'unit_price' => 1000.00, 'tax_rate' => 0.0],
    ]);
    $this->invoicingService->postInvoice($inv4, 'auditor');

    $aging = $this->ledgerService->calculateAging($customerId, $tenantId);

    expect($aging['current'])->toBe(1000.00);
    expect($aging['days_1_30'])->toBe(2000.00);
    expect($aging['days_31_60'])->toBe(3000.00);
    expect($aging['days_90_plus'])->toBe(4000.00);
    expect($aging['total_overdue'])->toBe(9000.00);
    expect($aging['total_outstanding'])->toBe(10000.00);
});

test('processes Treasury CollectionReceivedEvent and clears open customer receivable', function (): void {
    $tenantId = (string) $this->tenant->id;
    $customerId = (string) $this->customer->id;

    $invoice = $this->invoicingService->createInvoice([
        'tenant_id' => $tenantId,
        'contact_id' => $customerId,
        'invoice_number' => 'INV-PAY-CLEAR',
    ], [
        ['product_id' => (string) $this->product->id, 'description' => 'Item', 'quantity' => 1.0, 'unit_price' => 1000.00, 'tax_rate' => 0.0],
    ]);
    $this->invoicingService->postInvoice($invoice, 'auditor');

    expect($invoice->fresh()->payment_status)->toBe('unpaid');

    // Simulate Module 24 Treasury emitting CollectionReceivedEvent
    $event = new CollectionReceivedEvent(
        collectionId: (string) Str::uuid7(),
        customerId: $customerId,
        invoiceId: (string) $invoice->id,
        collectedAmount: 1000.00,
        collectionDate: now()->toDateString(),
        tenantId: $tenantId,
        companyId: $tenantId
    );

    $listener = app(TreasuryCollectionEventListener::class);
    $listener->handle($event);

    $freshInvoice = $invoice->fresh();
    expect((float) $freshInvoice->total_paid)->toBe(1000.00);
    expect($freshInvoice->payment_status)->toBe('paid');
});
