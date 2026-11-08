<?php

declare(strict_types=1);

use App\Enums\DeliveryNoteStatus;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\PaymentTerm;
use App\Models\PaymentTermInstallment;
use App\Services\ItalianSdi\FatturaPaBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\Invoicing\Models\BillingSchedule;
use Modules\Invoicing\Models\InvoiceInstallment;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesInvoiceItem;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderItem;

require_once __DIR__.'/InvoicingBillingSupport.php';

/*
 * End-to-end HTTP coverage of the enterprise billing of the invoicing module: partial order billing, down payments and
 * their deduction, deferred invoices of delivery notes, billing plans, debit notes, payment schedules, exchange rates
 * frozen at emission, sales register and receivables aging; with read-only access (403 on writes), tenant isolation
 * (404 / 422 on records of another company), validation (422) and balanced ledger postings.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    billingSetUp($this);
});

function invoiceOf(string $id): SalesInvoice
{
    return SalesInvoice::withoutGlobalScopes()->with('items')->findOrFail($id);
}

// ---------------------------------------------------------------------------------------------------------------
// Partial billing of a sales order
// ---------------------------------------------------------------------------------------------------------------

test('an order is billed in parts by quantity until nothing is left, and its billing status follows', function (): void {
    $order = billingOrder($this->tenant, $this->customer);
    $first = $order->items->values()[0];

    $id = $this->postJson("/api/v1/invoicing/invoices/from-order/{$order->id}", ['lines' => [['sales_order_item_id' => $first->id, 'quantity' => 4]]])
        ->assertCreated()->assertJsonPath('status', 'draft')->json('id');

    expect(invoiceOf($id)->items)->toHaveCount(1)
        ->and((string) invoiceOf($id)->total_net)->toBe('400.00')
        ->and(SalesOrder::withoutGlobalScopes()->find($order->id)->billing_status)->toBe('partially_billed')
        ->and((string) SalesOrderItem::withoutGlobalScopes()->find($first->id)->quantity_invoiced)->toBe('4.0000');

    $this->postJson("/api/v1/invoicing/invoices/from-order/{$order->id}", ['lines' => [['sales_order_item_id' => $first->id, 'quantity' => 7]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['lines.0.quantity']);

    $rest = $this->postJson("/api/v1/invoicing/invoices/from-order/{$order->id}")->assertCreated()->json('id');
    expect((string) invoiceOf($rest)->total_net)->toBe('800.00')
        ->and(SalesOrder::withoutGlobalScopes()->find($order->id)->billing_status)->toBe('billed');

    $this->postJson("/api/v1/invoicing/invoices/from-order/{$order->id}")->assertUnprocessable();

    $foreignOrder = billingOrder($this->otherTenant, $this->foreignCustomer);
    $this->postJson("/api/v1/invoicing/invoices/from-order/{$foreignOrder->id}")->assertNotFound();
    $this->postJson("/api/v1/invoicing/invoices/from-order/{$order->id}", ['lines' => [['sales_order_item_id' => $foreignOrder->items->first()->id, 'quantity' => 1]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['lines.0.sales_order_item_id']);
});

// ---------------------------------------------------------------------------------------------------------------
// Down payments
// ---------------------------------------------------------------------------------------------------------------

test('a down payment is issued per VAT rate, limited to the order, and deducted by the final invoice', function (): void {
    $order = billingOrder($this->tenant, $this->customer);

    $deposit = $this->postJson("/api/v1/invoicing/orders/{$order->id}/down-payment-invoices", ['percentage' => 30])
        ->assertCreated()->assertJsonPath('status', 'posted')->assertJsonPath('document_type', 'TD02')->json();

    $stored = invoiceOf($deposit['id']);
    expect((string) $stored->total_net)->toBe('360.00')
        ->and($stored->items)->toHaveCount(2)
        ->and((string) $stored->total_tax)->toBe('72.00')
        ->and($stored->sequential_number)->not->toBeNull();
    billingAssertBalanced($deposit['id']);

    $this->postJson("/api/v1/invoicing/orders/{$order->id}/down-payment-invoices", ['percentage' => 80])->assertUnprocessable()->assertJsonValidationErrors(['percentage']);
    $this->postJson("/api/v1/invoicing/orders/{$order->id}/down-payment-invoices", ['percentage' => 10, 'amount' => 50])->assertUnprocessable();
    $this->postJson("/api/v1/invoicing/orders/{$order->id}/down-payment-invoices", [])->assertUnprocessable()->assertJsonValidationErrors(['percentage', 'amount']);

    $final = $this->postJson("/api/v1/sales/orders/{$order->id}/invoice")->assertCreated()->json('data');
    $finalInvoice = invoiceOf($final['id']);
    $deductions = $finalInvoice->items->whereNotNull('deducted_invoice_item_id');

    expect($deductions)->toHaveCount(2)
        ->and((string) $finalInvoice->total_net)->toBe('840.00')
        ->and($finalInvoice->status)->toBe('posted');
    billingAssertBalanced($final['id']);

    $foreignOrder = billingOrder($this->otherTenant, $this->foreignCustomer);
    $this->postJson("/api/v1/invoicing/orders/{$foreignOrder->id}/down-payment-invoices", ['percentage' => 10])->assertNotFound();
});

// ---------------------------------------------------------------------------------------------------------------
// Deferred invoices of delivery notes
// ---------------------------------------------------------------------------------------------------------------

test('dispatched delivery notes of a customer are billed by one deferred invoice listing them', function (): void {
    $order = billingOrder($this->tenant, $this->customer);
    $firstNote = billingDeliveryNote($this->tenant, $order, [0 => '4']);
    $secondNote = billingDeliveryNote($this->tenant, $order, [0 => '6', 1 => '5']);

    $invoice = $this->postJson('/api/v1/invoicing/invoices/deferred', ['delivery_note_ids' => [$firstNote->id, $secondNote->id]])
        ->assertCreated()->assertJsonPath('status', 'posted')->assertJsonPath('document_type', 'TD24')->json();

    $stored = invoiceOf($invoice['id']);
    expect($stored->items)->toHaveCount(3)
        ->and((string) $stored->total_net)->toBe('1200.00')
        ->and(SalesDeliveryNote::withoutGlobalScopes()->find($firstNote->id)->status)->toBe(DeliveryNoteStatus::Invoiced->value)
        ->and(SalesOrder::withoutGlobalScopes()->find($order->id)->billing_status)->toBe('billed');
    billingAssertBalanced($invoice['id']);

    $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml(Invoice::withoutGlobalScopes()->with(['tenant', 'contact.addresses', 'items'])->findOrFail($invoice['id']));
    expect(substr_count($xml, '<DatiDDT>'))->toBe(2)->and($xml)->toContain('<NumeroDDT>'.$firstNote->ddt_number.'</NumeroDDT>');

    $this->postJson('/api/v1/invoicing/invoices/deferred', ['delivery_note_ids' => [$firstNote->id]])->assertUnprocessable();

    $otherOrder = billingOrder($this->tenant, $this->secondCustomer);
    $mixedA = billingDeliveryNote($this->tenant, billingOrder($this->tenant, $this->customer), [0 => '1']);
    $mixedB = billingDeliveryNote($this->tenant, $otherOrder, [0 => '1']);
    $this->postJson('/api/v1/invoicing/invoices/deferred', ['delivery_note_ids' => [$mixedA->id, $mixedB->id]])->assertUnprocessable()->assertJsonValidationErrors(['delivery_note_ids']);

    $foreignNote = billingDeliveryNote($this->otherTenant, billingOrder($this->otherTenant, $this->foreignCustomer), [0 => '1']);
    $this->postJson('/api/v1/invoicing/invoices/deferred', ['delivery_note_ids' => [$foreignNote->id]])->assertUnprocessable()->assertJsonValidationErrors(['delivery_note_ids.0']);
    $this->postJson('/api/v1/invoicing/invoices/deferred', [])->assertUnprocessable()->assertJsonValidationErrors(['delivery_note_ids']);
});

test('the deferred billing run issues one invoice per customer for the notes up to the date', function (): void {
    $notesA = [billingDeliveryNote($this->tenant, billingOrder($this->tenant, $this->customer), [0 => '2']), billingDeliveryNote($this->tenant, billingOrder($this->tenant, $this->customer), [1 => '1'])];
    $noteB = billingDeliveryNote($this->tenant, billingOrder($this->tenant, $this->secondCustomer), [0 => '1']);
    $later = billingDeliveryNote($this->tenant, billingOrder($this->tenant, $this->secondCustomer), [0 => '1'], now()->addDays(3)->toDateString());

    $result = $this->postJson('/api/v1/invoicing/invoices/deferred-run', ['up_to_date' => now()->toDateString()])->assertOk()->json();

    expect($result['invoices'])->toHaveCount(2)->and($result['errors'])->toBe([])
        ->and(SalesDeliveryNote::withoutGlobalScopes()->find($later->id)->status)->toBe(DeliveryNoteStatus::Issued->value)
        ->and(SalesDeliveryNote::withoutGlobalScopes()->find($noteB->id)->status)->toBe(DeliveryNoteStatus::Invoiced->value)
        ->and(SalesDeliveryNote::withoutGlobalScopes()->find($notesA[1]->id)->status)->toBe(DeliveryNoteStatus::Invoiced->value);

    $this->postJson('/api/v1/invoicing/invoices/deferred-run', [])->assertUnprocessable()->assertJsonValidationErrors(['up_to_date']);
});

// ---------------------------------------------------------------------------------------------------------------
// Billing plans
// ---------------------------------------------------------------------------------------------------------------

test('a billing plan bills its items on their own invoices and blocks billing the order by quantity', function (): void {
    $order = billingOrder($this->tenant, $this->customer);

    $this->postJson('/api/v1/invoicing/billing-plans', ['sales_order_id' => $order->id, 'items' => [['name' => 'Anticipo', 'percentage' => 70, 'target_date' => now()->toDateString()], ['name' => 'Saldo', 'percentage' => 50, 'target_date' => now()->addMonth()->toDateString()]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['items']);

    $plan = $this->postJson('/api/v1/invoicing/billing-plans', ['sales_order_id' => $order->id, 'items' => [
        ['name' => 'Avvio lavori', 'percentage' => 40, 'target_date' => now()->toDateString()],
        ['name' => 'Collaudo', 'amount' => '720.00', 'target_date' => now()->addDays(20)->toDateString()],
    ]])->assertCreated()->assertJsonPath('total_amount', '1200.00')->assertJsonPath('status', 'active')->json();

    $this->postJson('/api/v1/invoicing/billing-plans', ['sales_order_id' => $order->id, 'items' => [['name' => 'Again', 'percentage' => 10, 'target_date' => now()->toDateString()]]])->assertUnprocessable();
    $this->postJson("/api/v1/invoicing/invoices/from-order/{$order->id}")->assertUnprocessable();

    $firstItem = collect($plan['items'])->firstWhere('name', 'Avvio lavori');
    $invoiceId = $this->postJson("/api/v1/invoicing/billing-plans/{$plan['id']}/items/{$firstItem['id']}/invoice")->assertCreated()->json('id');

    expect((string) invoiceOf($invoiceId)->total_net)->toBe('480.00')->and(invoiceOf($invoiceId)->items)->toHaveCount(2);
    billingAssertBalanced($invoiceId);
    $this->postJson("/api/v1/invoicing/billing-plans/{$plan['id']}/items/{$firstItem['id']}/invoice")->assertUnprocessable();
    $this->getJson("/api/v1/invoicing/billing-plans/{$plan['id']}")->assertOk()->assertJsonPath('billed_amount', '480.00')->assertJsonPath('remaining_amount', '720.00');
    expect(SalesOrder::withoutGlobalScopes()->find($order->id)->billing_status)->toBe('partially_billed');

    $run = $this->postJson('/api/v1/invoicing/billing-plans/run', ['up_to_date' => now()->addMonth()->toDateString()])->assertOk()->json();
    expect($run['invoices'])->toHaveCount(1);
    $this->getJson("/api/v1/invoicing/billing-plans/{$plan['id']}")->assertOk()->assertJsonPath('status', 'completed');
    expect(SalesOrder::withoutGlobalScopes()->find($order->id)->billing_status)->toBe('billed');

    $this->getJson('/api/v1/invoicing/billing-plans?sales_order_id='.$order->id)->assertOk()->assertJsonCount(1, 'data');

    $foreignPlan = billingInTenant($this->otherTenant, fn () => BillingSchedule::query()->create([
        'tenant_id' => $this->otherTenant->id, 'customer_id' => $this->foreignCustomer->id, 'schedule_number' => 'BP-X', 'start_date' => now()->toDateString(), 'status' => 'active',
    ]));
    $this->getJson("/api/v1/invoicing/billing-plans/{$foreignPlan->id}")->assertNotFound();
    $this->postJson("/api/v1/invoicing/billing-plans/{$foreignPlan->id}/cancel")->assertNotFound();
    $this->postJson('/api/v1/invoicing/billing-plans', ['sales_order_id' => billingOrder($this->otherTenant, $this->foreignCustomer)->id, 'items' => [['name' => 'X', 'percentage' => 10, 'target_date' => now()->toDateString()]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['sales_order_id']);
});

test('a cancelled billing plan keeps its invoiced items and cancels the pending ones', function (): void {
    $order = billingOrder($this->tenant, $this->customer);
    $plan = $this->postJson('/api/v1/invoicing/billing-plans', ['sales_order_id' => $order->id, 'items' => [
        ['name' => 'Uno', 'percentage' => 50, 'target_date' => now()->toDateString()],
        ['name' => 'Due', 'percentage' => 50, 'target_date' => now()->addDays(5)->toDateString()],
    ]])->assertCreated()->json();

    $this->postJson("/api/v1/invoicing/billing-plans/{$plan['id']}/items/{$plan['items'][0]['id']}/invoice")->assertCreated();
    $cancelled = $this->postJson("/api/v1/invoicing/billing-plans/{$plan['id']}/cancel")->assertOk()->assertJsonPath('status', 'cancelled')->json();

    expect(collect($cancelled['items'])->pluck('status')->sort()->values()->all())->toBe(['cancelled', 'invoiced']);
    $this->postJson("/api/v1/invoicing/billing-plans/{$plan['id']}/cancel")->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------------------------
// Debit notes
// ---------------------------------------------------------------------------------------------------------------

test('a debit note adjusts an issued invoice and is refused on anything else', function (): void {
    $invoiceId = $this->postJson('/api/v1/sales/invoices', [
        'contact_id' => $this->customer->id, 'invoice_type' => 'standard', 'date' => now()->toDateString(), 'currency' => 'EUR', 'jurisdiction' => 'IT',
        'items' => [['description' => 'Consulenza', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 22]],
    ])->assertCreated()->json('data.id');

    $debit = $this->postJson('/api/v1/invoicing/debit-notes', ['original_invoice_id' => $invoiceId, 'items' => [['description' => 'Spese di trasferta', 'quantity' => 1, 'unit_price' => 150, 'tax_rate' => 22]]])
        ->assertCreated()->assertJsonPath('document_type', 'TD05')->assertJsonPath('related_invoice_id', $invoiceId)->assertJsonPath('status', 'posted')->json();

    expect((string) invoiceOf($debit['id'])->total_gross)->toBe('183.00');
    billingAssertBalanced($debit['id']);

    $this->postJson('/api/v1/invoicing/debit-notes', ['original_invoice_id' => $debit['id'], 'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1, 'tax_rate' => 22]]])->assertUnprocessable();
    $this->postJson('/api/v1/invoicing/debit-notes', ['original_invoice_id' => $invoiceId, 'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1]]])->assertUnprocessable()->assertJsonValidationErrors(['items.0.tax_rate']);

    $foreign = billingInTenant($this->otherTenant, fn () => SalesInvoice::query()->create(['tenant_id' => $this->otherTenant->id, 'contact_id' => $this->foreignCustomer->id, 'invoice_number' => 'X-1', 'date' => now()->toDateString(), 'status' => 'posted', 'currency' => 'EUR']));
    $this->postJson('/api/v1/invoicing/debit-notes', ['original_invoice_id' => $foreign->id, 'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1, 'tax_rate' => 22]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['original_invoice_id']);
});

// ---------------------------------------------------------------------------------------------------------------
// Payment schedules and exchange rates
// ---------------------------------------------------------------------------------------------------------------

test('a payment term splits the amount due into installments fixed at emission', function (): void {
    $term = billingInTenant($this->tenant, function (): PaymentTerm {
        $term = PaymentTerm::query()->create(['tenant_id' => $this->tenant->id, 'code' => 'RATE', 'name' => '30/60', 'days' => 60, 'end_of_month' => false]);
        PaymentTermInstallment::query()->create(['payment_term_id' => $term->id, 'sequence' => 1, 'percent' => '50', 'days' => 30]);
        PaymentTermInstallment::query()->create(['payment_term_id' => $term->id, 'sequence' => 2, 'percent' => '50', 'days' => 60]);

        return $term;
    });

    $invoiceId = $this->postJson('/api/v1/sales/invoices', [
        'contact_id' => $this->customer->id, 'invoice_type' => 'standard', 'date' => now()->toDateString(), 'currency' => 'EUR', 'jurisdiction' => 'IT', 'payment_term_id' => $term->id,
        'items' => [['description' => 'Licenza', 'quantity' => 3, 'unit_price' => '33.33', 'tax_rate' => 22]],
    ])->assertCreated()->json('data.id');

    $schedule = $this->getJson("/api/v1/invoicing/invoices/{$invoiceId}/installments")->assertOk()->json('installments');
    $gross = (string) invoiceOf($invoiceId)->total_gross;

    expect($schedule)->toHaveCount(2)
        ->and(bcadd($schedule[0]['amount'], $schedule[1]['amount'], 2))->toBe($gross)
        ->and($schedule[1]['due_date'])->toBe(now()->addDays(60)->toDateString())
        ->and(invoiceOf($invoiceId)->due_date->toDateString())->toBe(now()->addDays(60)->toDateString())
        ->and(InvoiceInstallment::withoutGlobalScopes()->where('invoice_id', $invoiceId)->count())->toBe(2);

    $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml(Invoice::withoutGlobalScopes()->with(['tenant', 'contact.addresses', 'items'])->findOrFail($invoiceId));
    expect($xml)->toContain('<CondizioniPagamento>TP01</CondizioniPagamento>')->and(substr_count($xml, '<DettaglioPagamento>'))->toBe(2);

    $foreignTerm = billingInTenant($this->otherTenant, fn () => PaymentTerm::query()->create(['tenant_id' => $this->otherTenant->id, 'code' => 'F', 'name' => 'F', 'days' => 30]));
    $this->postJson('/api/v1/sales/invoices', [
        'contact_id' => $this->customer->id, 'invoice_type' => 'standard', 'date' => now()->toDateString(), 'currency' => 'EUR', 'jurisdiction' => 'IT', 'payment_term_id' => $foreignTerm->id,
        'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1, 'tax_rate' => 22]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['payment_term_id']);

    $foreignInvoice = billingInTenant($this->otherTenant, fn () => SalesInvoice::query()->create(['tenant_id' => $this->otherTenant->id, 'contact_id' => $this->foreignCustomer->id, 'invoice_number' => 'X-2', 'date' => now()->toDateString(), 'currency' => 'EUR']));
    $this->getJson("/api/v1/invoicing/invoices/{$foreignInvoice->id}/installments")->assertNotFound();
});

test('the exchange rate of a foreign-currency invoice is frozen at emission from the exchange-rate service', function (): void {
    ExchangeRate::query()->create(['source' => 'EUR', 'target' => 'USD', 'rate' => '1.250000', 'effective_date' => now()->subDays(2)->toDateString()]);

    $invoiceId = $this->postJson('/api/v1/sales/invoices', [
        'contact_id' => $this->customer->id, 'invoice_type' => 'standard', 'date' => now()->toDateString(), 'currency' => 'USD', 'jurisdiction' => 'IT',
        'items' => [['description' => 'Export', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
        'vat_nature' => 'N3.1',
    ])->assertCreated()->json('data.id');

    $invoice = invoiceOf($invoiceId);
    expect((string) $invoice->exchange_rate)->toBe('0.800000')
        ->and($invoice->exchange_rate_source)->toBe('exchange_rate_service')
        ->and($invoice->exchange_rate_date->toDateString())->toBe(now()->subDays(2)->toDateString());

    $this->postJson('/api/v1/sales/invoices', [
        'contact_id' => $this->customer->id, 'invoice_type' => 'standard', 'date' => now()->toDateString(), 'currency' => 'JPY', 'jurisdiction' => 'IT',
        'items' => [['description' => 'Export', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
        'vat_nature' => 'N3.1',
    ])->assertUnprocessable()->assertJsonValidationErrors(['exchange_rate']);

    $stated = $this->postJson('/api/v1/sales/invoices', [
        'contact_id' => $this->customer->id, 'invoice_type' => 'standard', 'date' => now()->toDateString(), 'currency' => 'JPY', 'exchange_rate' => '0.0061', 'jurisdiction' => 'IT',
        'items' => [['description' => 'Export', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
        'vat_nature' => 'N3.1',
    ])->assertCreated()->json('data.id');
    expect(invoiceOf($stated)->exchange_rate_source)->toBe('document');
});

// ---------------------------------------------------------------------------------------------------------------
// Numbering
// ---------------------------------------------------------------------------------------------------------------

test('a refused emission leaves no gap in the invoice numbers', function (): void {
    $payload = fn (string $date): array => [
        'contact_id' => $this->customer->id, 'invoice_type' => 'standard', 'date' => $date, 'currency' => 'EUR', 'jurisdiction' => 'IT',
        'items' => [['description' => 'Servizio', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 22]],
    ];

    $first = invoiceOf($this->postJson('/api/v1/sales/invoices', $payload(now()->toDateString()))->assertCreated()->json('data.id'))->sequential_number;

    FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->whereDate('start_date', '<=', now()->toDateString())->whereDate('end_date', '>=', now()->toDateString())->update(['status' => 'closed']);
    $this->postJson('/api/v1/sales/invoices', $payload(now()->toDateString()))->assertUnprocessable();
    FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->update(['status' => 'open']);

    $numbers = collect(range(1, 3))->map(fn (): int => (int) invoiceOf($this->postJson('/api/v1/sales/invoices', $payload(now()->toDateString()))->assertCreated()->json('data.id'))->sequential_number);

    expect($numbers->all())->toBe([$first + 1, $first + 2, $first + 3]);
});

// ---------------------------------------------------------------------------------------------------------------
// Reports
// ---------------------------------------------------------------------------------------------------------------

test('the sales register lists issued documents with the VAT of their entries, the aging buckets what is open', function (): void {
    $issue = fn (string $date, string $price): string => $this->postJson('/api/v1/sales/invoices', [
        'contact_id' => $this->customer->id, 'invoice_type' => 'standard', 'date' => $date, 'due_date' => $date, 'currency' => 'EUR', 'jurisdiction' => 'IT',
        'items' => [['description' => 'Servizio', 'quantity' => 1, 'unit_price' => $price, 'tax_rate' => 22]],
    ])->assertCreated()->json('data.id');

    $old = $issue(now()->startOfYear()->toDateString(), '100');
    $recent = $issue(now()->toDateString(), '200');
    $this->postJson('/api/v1/sales/invoices', ['contact_id' => $this->customer->id, 'invoice_type' => 'proforma', 'date' => now()->toDateString(), 'currency' => 'EUR', 'jurisdiction' => 'IT', 'items' => [['description' => 'PF', 'quantity' => 1, 'unit_price' => 999, 'tax_rate' => 22]]])->assertCreated();

    $register = $this->getJson('/api/v1/invoicing/reports/sales-register?from='.now()->startOfYear()->toDateString().'&to='.now()->toDateString())->assertOk()->json();
    $documents = collect($register['documents']);

    expect($documents->pluck('invoice_id')->all())->toContain($old, $recent)
        ->and($documents)->toHaveCount(2)
        ->and($documents->firstWhere('invoice_id', $recent)['vat'][0])->toBe(['rate' => '22.00', 'taxable' => '200.00', 'tax' => '44.00'])
        ->and($register['totals'][0]['total_gross'])->toBe('366.00');

    $this->getJson('/api/v1/invoicing/reports/sales-register?from=2026-01-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
    $this->getJson('/api/v1/invoicing/reports/sales-register?from=2026-01-01&to=2028-01-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);

    $aging = $this->getJson('/api/v1/invoicing/reports/receivables-aging?as_of='.now()->toDateString())->assertOk()->json();
    $row = collect($aging['customers'])->firstWhere('customer_id', $this->customer->id);
    expect($row['total_open'])->toBe('366.00')->and($row['current'])->toBe('244.00');

    $this->getJson('/api/v1/invoicing/reports/receivables-aging?customer_id='.$this->foreignCustomer->id)->assertUnprocessable()->assertJsonValidationErrors(['customer_id']);
});

// ---------------------------------------------------------------------------------------------------------------
// Access
// ---------------------------------------------------------------------------------------------------------------

test('read-only roles read the billing endpoints and are refused every write', function (): void {
    $order = billingOrder($this->tenant, $this->customer);
    billingActor($this, 'accountant_readonly');

    $this->getJson('/api/v1/invoicing/billing-plans')->assertOk();
    $this->getJson('/api/v1/invoicing/reports/receivables-aging')->assertOk();

    foreach ([
        "/api/v1/invoicing/orders/{$order->id}/down-payment-invoices",
        '/api/v1/invoicing/invoices/deferred',
        '/api/v1/invoicing/invoices/deferred-run',
        '/api/v1/invoicing/billing-plans',
        '/api/v1/invoicing/billing-plans/run',
        '/api/v1/invoicing/debit-notes',
        "/api/v1/invoicing/invoices/from-order/{$order->id}",
    ] as $uri) {
        $this->postJson($uri, [])->assertForbidden();
    }

    expect(SalesInvoiceItem::withoutGlobalScopes()->count())->toBe(0);
});
