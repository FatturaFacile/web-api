<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Events\ReceivableReadyEvent;
use Modules\Invoicing\Contracts\CustomerLedgerServiceInterface;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Contracts\PricingAndDiscountServiceInterface;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\Invoicing\Models\CustomerLedgerEntry;
use Modules\Invoicing\Services\InvoicingService;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'Invoice Posting Test Tenant', 'legal_name' => 'Invoice Posting Test Tenant Srl']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->customer = Contact::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'company_name' => 'Accounting Client SpA'],
        ['id' => (string) Str::uuid7(), 'type' => 'client', 'country' => 'IT']
    );

    $this->product = Product::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'sku' => 'PRD-INV-POST-01'],
        ['id' => (string) Str::uuid7(), 'name' => 'Enterprise Server Cluster', 'sale_price' => 5000.00, 'cost_price' => 2500.00, 'tax_rate' => 22.0]
    );

    $this->orderService = app(SalesOrderServiceInterface::class);
    $this->invoicingService = app(InvoicingServiceInterface::class);
});

test('posts invoice from sales order, records customer ledger entry, and dispatches GL & Treasury readiness events', function (): void {
    $order = $this->orderService->createOrder([
        'tenant_id' => (string) $this->tenant->id,
        'contact_id' => (string) $this->customer->id,
        'order_number' => 'SO-POST-TEST-01',
    ], [
        [
            'product_id' => (string) $this->product->id,
            'description' => 'Enterprise Server Cluster',
            'quantity' => 2.0,
            'unit_price' => 5000.00,
            'tax_rate' => 22.0,
        ],
    ]);

    $dispatchedEvents = [];
    $mockDispatcher = new class($dispatchedEvents) implements EventDispatcherInterface
    {
        public function __construct(public array &$events) {}

        public function dispatch(DomainEventInterface $event): void
        {
            $this->events[] = $event;
        }

        public function publish(DomainEventInterface $event): void
        {
            $this->events[] = $event;
        }

        public function dispatchMultiple(array $events): void
        {
            foreach ($events as $event) {
                $this->dispatch($event);
            }
        }
    };
    $invoicingService = new InvoicingService(
        app(PricingAndDiscountServiceInterface::class),
        app(CustomerLedgerServiceInterface::class),
        $mockDispatcher
    );

    $invoice = $invoicingService->createInvoiceFromOrder($order, [
        'invoice_number' => 'INV-2027-POST-01',
    ]);

    expect((float) $invoice->total_net)->toBe(10000.00);
    expect((float) $invoice->total_tax)->toBe(2200.00);
    expect((float) $invoice->total_gross)->toBe(12200.00);
    expect($invoice->status)->toBe('draft');
    expect($invoice->payment_status)->toBe('unpaid');

    // Post invoice
    $postedInvoice = $invoicingService->postInvoice($invoice, 'chief-accountant');

    expect($postedInvoice->status)->toBe('posted');
    expect($postedInvoice->posted_at)->not->toBeNull();
    expect($postedInvoice->posted_by)->not->toBeNull();

    // Verify Customer Ledger Entry was created
    $ledgerEntry = CustomerLedgerEntry::where('invoice_id', (string) $invoice->id)->first();
    expect($ledgerEntry)->not->toBeNull();
    expect($ledgerEntry->entry_type)->toBe('invoice');
    expect((float) $ledgerEntry->debit_amount)->toBe(12200.00);
    expect((float) $ledgerEntry->credit_amount)->toBe(0.00);
    expect($ledgerEntry->status)->toBe('open');

    // Verify events were dispatched
    $postedEvent = collect($dispatchedEvents)->first(fn ($e) => $e instanceof InvoicePostedEvent);
    expect($postedEvent)->not->toBeNull();
    expect($postedEvent->invoiceId)->toBe((string) $invoice->id);
    expect((float) $postedEvent->totalGross)->toBe(12200.00);
    expect($postedEvent->revenueLines)->toHaveCount(1);
    expect($postedEvent->taxLines)->toHaveCount(1);

    $receivableEvent = collect($dispatchedEvents)->first(fn ($e) => $e instanceof ReceivableReadyEvent);
    expect($receivableEvent)->not->toBeNull();
    expect($receivableEvent->customerLedgerId)->toBe((string) $ledgerEntry->id);
    expect((float) $receivableEvent->openAmount)->toBe(12200.00);
});
