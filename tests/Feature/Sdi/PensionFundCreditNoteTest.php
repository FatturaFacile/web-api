<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Invoicing\InvoiceService;
use App\Services\ItalianSdi\FatturaPaBuilderService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\SDI\Services\FatturaPaSchemaValidator;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Rossi', 'legal_name' => 'Rossi', 'vat_number' => '00811720580', 'fiscal_code' => '00811720580', 'country' => 'IT', 'default_currency' => 'EUR',
        'settings' => ['street' => 'Via Verdi 1', 'postal_code' => '20121', 'city' => 'Milano', 'province' => 'MI'],
    ]);
    $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bianchi SpA', 'country' => 'IT', 'vat_number' => '01234567897', 'sdi_code' => 'ABC1234']);
    $this->customer->addresses()->create(['tenant_id' => $this->tenant->id, 'type' => 'billing', 'street' => 'Via Dante 2', 'postal_code' => '00100', 'city' => 'Roma', 'country' => 'IT']);

    app(TenantContext::class)->setTenant($this->tenant);
    fiscalCalendar($this->tenant);
});

/**
 * @param  array<string, mixed>  $data
 */
function fundCreditNoteSale(array $data = [], float $unitPrice = 1000.0): Invoice
{
    return app(InvoiceService::class)->createInvoice([
        'contact_id' => test()->customer->id,
        'invoice_type' => 'standard',
        'date' => '2027-03-01',
        'currency' => 'EUR',
        'jurisdiction' => 'IT',
        'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => $unitPrice, 'tax_rate' => 22]],
        ...$data,
    ]);
}

/**
 * @return array<string, array{debit: float, credit: float}>
 */
function fundCreditNoteEntry(string $journalEntryId): array
{
    return DB::table('journal_lines')
        ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
        ->where('journal_lines.journal_entry_id', $journalEntryId)
        ->get(['chart_of_accounts.account_code', 'journal_lines.debit_amount', 'journal_lines.credit_amount'])
        ->groupBy('account_code')
        ->map(fn ($lines): array => ['debit' => round((float) $lines->sum('debit_amount'), 2), 'credit' => round((float) $lines->sum('credit_amount'), 2)])
        ->all();
}

function fundCreditNoteBalance(string $code): float
{
    return round((float) DB::table('journal_lines')
        ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
        ->where('chart_of_accounts.tenant_id', test()->tenant->id)
        ->where('chart_of_accounts.account_code', $code)
        ->selectRaw('COALESCE(SUM(journal_lines.debit_amount - journal_lines.credit_amount), 0) AS balance')
        ->value('balance'), 2);
}

test('a credit note takes back its share of the INPS rivalsa, in the totals, the XML and the ledger', function (): void {
    $invoice = fundCreditNoteSale(['fund_enabled' => true, 'fund_type' => 'TC22', 'fund_percent' => 4]);

    expect((string) $invoice->fund_amount)->toBe('40.00')->and((string) $invoice->total_gross)->toBe('1268.80');

    $credit = fundCreditNoteSale(['invoice_type' => 'credit_note', 'related_invoice_id' => $invoice->id], unitPrice: 400);

    expect($credit->fund_enabled)->toBeTrue()
        ->and((string) $credit->fund_amount)->toBe('16.00')
        ->and((string) $credit->fund_vat_amount)->toBe('3.52')
        ->and((string) $credit->total_tax)->toBe('91.52')
        ->and((string) $credit->total_gross)->toBe('507.52');

    $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml($credit->load(['tenant', 'contact', 'items', 'declarationOfIntent']), '0000B');
    expect(app(FatturaPaSchemaValidator::class)->errors($xml))->toBe([])
        ->and($xml)->toContain('<TipoDocumento>TD04</TipoDocumento>')
        ->and($xml)->toContain('<TipoCassa>TC22</TipoCassa>')
        ->and($xml)->toContain('<ImportoContributoCassa>16.00</ImportoContributoCassa>')
        ->and($xml)->toContain('<ImponibileCassa>400.00</ImponibileCassa>')
        ->and($xml)->toContain('<ImponibileImporto>416.00</ImponibileImporto>')
        ->and($xml)->toContain('<ImportoTotaleDocumento>507.52</ImportoTotaleDocumento>');

    $lines = fundCreditNoteEntry((string) $credit->refresh()->gl_journal_entry_id);
    expect($lines['4095']['debit'])->toBe(16.0)
        ->and($lines['4090']['debit'])->toBe(400.0)
        ->and($lines['2200']['debit'])->toBe(91.52)
        ->and($lines['1100']['credit'])->toBe(507.52);

    expect(fundCreditNoteBalance('1100'))->toBe(761.28)
        ->and(fundCreditNoteBalance('4095'))->toBe(-24.0);
});

test('a full credit note on a fund with withholding clears receivable, fund payable and withholding', function (): void {
    $invoice = fundCreditNoteSale([
        'fund_enabled' => true, 'fund_type' => 'TC01', 'fund_percent' => 4, 'fund_subject_to_withholding' => true,
        'withholding_tax_enabled' => true, 'withholding_tax_percent' => 20,
    ]);

    expect((string) $invoice->withholding_tax_amount)->toBe('208.00');

    $credit = fundCreditNoteSale(['invoice_type' => 'credit_note', 'related_invoice_id' => $invoice->id]);

    expect((string) $credit->fund_amount)->toBe('40.00')
        ->and((string) $credit->withholding_tax_amount)->toBe('208.00')
        ->and((string) $credit->total_gross)->toBe('1268.80');

    $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml($credit->load(['tenant', 'contact', 'items', 'declarationOfIntent']), '0000C');
    expect(app(FatturaPaSchemaValidator::class)->errors($xml))->toBe([])->and($xml)->toContain('<Ritenuta>SI</Ritenuta>');

    expect(fundCreditNoteBalance('1100'))->toBe(0.0)
        ->and(fundCreditNoteBalance('2245'))->toBe(0.0)
        ->and(fundCreditNoteBalance('1260'))->toBe(0.0)
        ->and(fundCreditNoteBalance('2200'))->toBe(0.0);
});

test('a credit note cannot state a fund its invoice does not have', function (): void {
    $invoice = fundCreditNoteSale();

    expect(fn () => fundCreditNoteSale(['invoice_type' => 'credit_note', 'related_invoice_id' => $invoice->id, 'fund_enabled' => true, 'fund_type' => 'TC22', 'fund_percent' => 4], unitPrice: 400))
        ->toThrow(ValidationException::class);
});
