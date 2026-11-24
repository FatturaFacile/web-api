<?php

declare(strict_types=1);

use App\Models\PaymentTerm;
use App\Models\PaymentTermInstallment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoicing\Models\InvoiceInstallment;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Services\InvoicingService;

require_once __DIR__.'/InvoicingBillingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    billingSetUp($this);
});

function invoiceWithThreeInstallments(): SalesInvoice
{
    $term = billingInTenant(test()->tenant, function (): PaymentTerm {
        $term = PaymentTerm::query()->create(['tenant_id' => test()->tenant->id, 'code' => 'R3', 'name' => '30/60/90', 'days' => 90, 'end_of_month' => false]);

        foreach ([1 => 30, 2 => 60, 3 => 90] as $sequence => $days) {
            PaymentTermInstallment::query()->create(['payment_term_id' => $term->id, 'sequence' => $sequence, 'percent' => '33.33', 'days' => $days]);
        }

        return $term;
    });

    $id = test()->postJson('/api/v1/sales/invoices', [
        'contact_id' => test()->customer->id, 'invoice_type' => 'standard', 'date' => now()->toDateString(), 'currency' => 'EUR', 'jurisdiction' => 'IT', 'payment_term_id' => $term->id,
        'items' => [['description' => 'Licenza', 'quantity' => 1, 'unit_price' => '1000.00', 'tax_rate' => 0]],
    ])->assertCreated()->json('data.id');

    return SalesInvoice::withoutGlobalScopes()->findOrFail($id);
}

function creditFor(SalesInvoice $invoice, string $amount): SalesInvoice
{
    return app(InvoicingService::class)->issueCreditNote($invoice, [], [['description' => 'Credit', 'quantity' => 1, 'unit_price' => $amount, 'tax_rate' => 0]]);
}

test('a partial credit note shrinks the newest installments first and keeps the schedule equal to what is still owed', function (): void {
    $invoice = invoiceWithThreeInstallments();
    expect(InvoiceInstallment::withoutGlobalScopes()->whereNull('deleted_at')->where('invoice_id', $invoice->id)->count())->toBe(3);

    creditFor($invoice, '400.00');

    $rows = InvoiceInstallment::withoutGlobalScopes()->whereNull('deleted_at')->where('invoice_id', $invoice->id)->orderBy('sequence')->get();
    $total = $rows->reduce(fn (string $sum, $row): string => bcadd($sum, (string) $row->amount, 2), '0.00');

    expect($rows)->toHaveCount(2)
        ->and($rows->first()->sequence)->toBe(1)
        ->and($total)->toBe('600.00')
        ->and($invoice->refresh()->due_date->toDateString())->toBe($rows->last()->due_date->toDateString());
});

test('a total credit note cancels every installment', function (): void {
    $invoice = invoiceWithThreeInstallments();

    creditFor($invoice, '1000.00');

    expect(InvoiceInstallment::withoutGlobalScopes()->whereNull('deleted_at')->where('invoice_id', $invoice->id)->count())->toBe(0);
});

test('an invoice without installments is left alone by its credit note', function (): void {
    $id = $this->postJson('/api/v1/sales/invoices', [
        'contact_id' => $this->customer->id, 'invoice_type' => 'standard', 'date' => now()->toDateString(), 'currency' => 'EUR', 'jurisdiction' => 'IT',
        'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => '100.00', 'tax_rate' => 0]],
    ])->assertCreated()->json('data.id');

    creditFor(SalesInvoice::withoutGlobalScopes()->findOrFail($id), '50.00');

    expect(InvoiceInstallment::withoutGlobalScopes()->whereNull('deleted_at')->where('invoice_id', $id)->count())->toBe(0);
});
