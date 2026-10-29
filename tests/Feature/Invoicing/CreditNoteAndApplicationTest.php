<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\CustomerLedgerEntry;
use Modules\Invoicing\Models\SalesInvoice;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'Credit Note Test Tenant', 'legal_name' => 'Credit Note Test Tenant Srl']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->customer = Contact::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'company_name' => 'Adjustment Client Srl'],
        ['id' => (string) Str::uuid7(), 'type' => 'client', 'country' => 'IT']
    );

    $this->product = Product::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'sku' => 'PRD-CN-01'],
        ['id' => (string) Str::uuid7(), 'name' => 'Precision Sensor Device', 'sale_price' => 200.00, 'cost_price' => 80.00, 'tax_rate' => 22.0]
    );

    $this->invoicingService = app(InvoicingServiceInterface::class);
});

test('issues credit note and applies credit against original invoice', function (): void {
    // 1. Create and post original invoice for 10 units = 2000 net + 440 tax = 2440 gross
    $invoice = $this->invoicingService->createInvoice([
        'tenant_id' => (string) $this->tenant->id,
        'contact_id' => (string) $this->customer->id,
        'invoice_number' => 'INV-2027-ORIG',
    ], [
        [
            'product_id' => (string) $this->product->id,
            'description' => 'Precision Sensor Device',
            'quantity' => 10.0,
            'unit_price' => 200.00,
            'tax_rate' => 22.0,
        ],
    ]);

    $this->invoicingService->postInvoice($invoice, 'finance-officer');

    // 2. Customer returned 2 defective units -> Issue Credit Note for 2 units = 400 net + 88 tax = 488 gross
    $creditNote = $this->invoicingService->issueCreditNote($invoice, [
        'invoice_number' => 'CN-2027-001',
        'created_by' => 'finance-officer',
        'notes' => 'Defective sensor return credit',
    ], [
        [
            'product_id' => (string) $this->product->id,
            'description' => 'Returned Precision Sensor Device',
            'quantity' => 2.0,
            'unit_price' => 200.00,
            'tax_rate' => 22.0,
        ],
    ]);

    expect($creditNote)->toBeInstanceOf(SalesInvoice::class);
    expect($creditNote->invoice_type)->toBe('credit_note');
    expect((float) $creditNote->total_gross)->toBe(488.00);
    expect($creditNote->status)->toBe('posted');

    // Verify ledger credit entry
    $creditEntry = CustomerLedgerEntry::where('invoice_id', (string) $creditNote->id)->first();
    expect($creditEntry)->not->toBeNull();
    expect($creditEntry->entry_type)->toBe('credit_note');
    expect((float) $creditEntry->credit_amount)->toBe(488.00);

    // 3. Apply credit note to original invoice
    $application = $this->invoicingService->applyCreditNote($creditNote, $invoice, 488.00, 'finance-officer');

    expect($application)->not->toBeNull();
    expect((float) $application->applied_amount)->toBe(488.00);

    $freshInvoice = $invoice->fresh();
    expect((float) $freshInvoice->total_paid)->toBe(488.00);
    expect($freshInvoice->payment_status)->toBe('partially_paid');
    expect((float) $freshInvoice->outstanding_balance)->toBe(1952.00);
});
