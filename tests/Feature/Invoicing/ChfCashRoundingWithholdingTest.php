<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Invoicing\InvoiceService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Rossi', 'legal_name' => 'Rossi', 'vat_number' => '00811720580', 'fiscal_code' => '00811720580', 'country' => 'IT', 'default_currency' => 'EUR',
        'settings' => ['street' => 'Via Verdi 1', 'postal_code' => '20121', 'city' => 'Milano', 'province' => 'MI'],
    ]);
    $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Kunde AG', 'country' => 'IT', 'vat_number' => '01234567897']);

    app(TenantContext::class)->setTenant($this->tenant);
    fiscalCalendar($this->tenant);

    // A franc invoice of a euro company takes the rate of the exchange-rate service at emission.
    ExchangeRate::query()->create(['source' => 'EUR', 'target' => 'CHF', 'rate' => '1.000000', 'effective_date' => '2027-01-01']);
});

/**
 * @param  array<string, mixed>  $data
 */
function chfRoundedSale(array $data = []): Invoice
{
    return app(InvoiceService::class)->createInvoice([
        'contact_id' => test()->customer->id,
        'invoice_type' => 'standard',
        'date' => '2027-03-01',
        'currency' => 'CHF',
        'jurisdiction' => 'IT',
        'items' => [['description' => 'Consulenza', 'quantity' => 1, 'unit_price' => 100.30, 'tax_rate' => 22]],
        ...$data,
    ]);
}

/**
 * @return array<string, array{debit: float, credit: float}>
 */
function chfRoundingEntry(string $journalEntryId): array
{
    return DB::table('journal_lines')
        ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
        ->where('journal_lines.journal_entry_id', $journalEntryId)
        ->get(['chart_of_accounts.account_code', 'journal_lines.debit_amount', 'journal_lines.credit_amount'])
        ->groupBy('account_code')
        ->map(fn ($lines): array => ['debit' => round((float) $lines->sum('debit_amount'), 2), 'credit' => round((float) $lines->sum('credit_amount'), 2)])
        ->all();
}

test('with a withholding the amount due is rounded to 5 centimes, not the gross', function (): void {
    $invoice = chfRoundedSale(['withholding_tax_enabled' => true, 'withholding_tax_percent' => 20]);

    expect((string) $invoice->withholding_tax_amount)->toBe('20.06')
        ->and((string) $invoice->rounding_amount)->toBe('-0.01')
        ->and((string) $invoice->total_gross)->toBe('122.36')
        ->and($invoice->amountDue())->toBe('102.30')
        ->and((string) $invoice->receivable_amount)->toBe('102.30');

    $lines = chfRoundingEntry((string) $invoice->gl_journal_entry_id);

    expect($lines['1100']['debit'])->toBe(102.3)
        ->and($lines['1260']['debit'])->toBe(20.06)
        ->and($lines['6960']['debit'])->toBe(0.01);
});

test('the draft flow rounds the amount due too', function (): void {
    $invoicing = app(InvoicingServiceInterface::class);
    $draft = $invoicing->createInvoice([
        'tenant_id' => $this->tenant->id, 'contact_id' => $this->customer->id, 'date' => '2027-03-01', 'currency' => 'CHF', 'jurisdiction' => 'IT',
        'withholding_tax_enabled' => true, 'withholding_tax_percent' => 20,
    ], [['description' => 'Consulenza', 'quantity' => 1, 'unit_price' => 100.30, 'tax_rate' => 22]]);

    $posted = $invoicing->postInvoice($draft, '');

    expect($posted->amountDue())->toBe('102.30')
        ->and((string) $posted->total_gross)->toBe('122.36');
});

test('without deductions the gross is rounded as before', function (): void {
    $invoice = chfRoundedSale();

    expect((string) $invoice->total_gross)->toBe('122.35')
        ->and((string) $invoice->rounding_amount)->toBe('-0.02')
        ->and($invoice->amountDue())->toBe('122.35');
});
