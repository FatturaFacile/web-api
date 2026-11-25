<?php

declare(strict_types=1);

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Contact;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SupplierPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Treasury\Models\ReconciliationProposal;

require_once __DIR__.'/TreasurySupport.php';

uses(RefreshDatabase::class);

const SMART_QRR = '210000000003139471430009017';
const SMART_IBAN = 'IT60X0542811101000000123456';

beforeEach(function (): void {
    treasurySetUp($this);
    $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Beta Consulting SRL', 'email' => 'beta@example.com', 'type' => 'customer', 'country' => 'IT', 'vat_number' => 'IT01234567897']);
    $this->supplier = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Fornitore SPA', 'email' => 'f@example.com', 'type' => 'supplier', 'country' => 'IT']);
    $this->statement = smartStatement($this->tenant->id);
});

function smartStatement(string $tenantId, string $currency = 'EUR'): BankStatement
{
    return BankStatement::create([
        'tenant_id' => $tenantId, 'source_format' => 'camt053', 'account_iban' => SMART_IBAN, 'statement_date' => now()->toDateString(),
        'currency' => $currency, 'total_entries' => 0, 'status' => 'draft', 'content_hash' => Str::random(40),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function smartLine(BankStatement $statement, array $overrides = []): BankStatementLine
{
    return BankStatementLine::create(array_merge([
        'tenant_id' => $statement->tenant_id, 'bank_statement_id' => $statement->id, 'line_number' => 1,
        'booking_date' => now()->toDateString(), 'value_date' => now()->toDateString(), 'amount' => '100.00', 'fee_amount' => '0',
        'direction' => 'credit', 'reconciled' => false, 'dedupe_key' => Str::random(40),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function smartInvoice(object $test, string $number, string $gross, array $overrides = []): Invoice
{
    return Invoice::create(array_merge([
        'tenant_id' => $test->tenant->id, 'contact_id' => $test->customer->id, 'invoice_number' => $number,
        'date' => now()->subDays(20)->toDateString(), 'due_date' => now()->addDays(10)->toDateString(), 'currency' => 'EUR',
        'total_net' => $gross, 'total_tax' => '0', 'total_gross' => $gross, 'payment_status' => 'unpaid', 'invoice_type' => 'standard',
    ], $overrides));
}

/**
 * @return array<int, array<string, mixed>>
 */
function smartProposals(object $test, BankStatementLine $line): array
{
    return $test->postJson("/api/v1/treasury/smart-reconciliation/lines/{$line->id}/refresh")->assertOk()->json('data.proposals');
}

test('rule 1: a structured QR reference proposes the invoice with 100% confidence and settles it in one click', function (): void {
    $invoice = smartInvoice($this, 'FT-2026/100', '1220.00', ['qr_reference' => SMART_QRR]);
    $line = smartLine($this->statement, ['amount' => '1220.00', 'qr_reference' => SMART_QRR, 'description' => 'Bonifico']);

    $proposals = smartProposals($this, $line);

    expect($proposals)->toHaveCount(1)
        ->and($proposals[0]['rule'])->toBe('structured_reference')
        ->and($proposals[0]['confidence'])->toBe(100)
        ->and($proposals[0]['kind'])->toBe('exact')
        ->and($proposals[0]['matched_amount'])->toBe('1220.00');

    $this->postJson("/api/v1/treasury/smart-reconciliation/proposals/{$proposals[0]['id']}/apply")->assertOk()->assertJsonPath('data.reconciled', true);

    expect($line->refresh()->reconciled)->toBeTrue()
        ->and($line->reconciliation_type)->toBe(BankStatementLine::QR_REFERENCE)
        ->and($invoice->refresh()->payment_status)->toBe('paid')
        ->and(ReconciliationProposal::query()->find($proposals[0]['id'])->status)->toBe('applied');
});

test('rule 2: an exact amount with the VAT number in the description is proposed with 95% confidence', function (): void {
    smartInvoice($this, 'FT-2026/101', '610.00');
    $line = smartLine($this->statement, ['amount' => '610.00', 'description' => 'Bonifico saldo P.IVA IT01234567897']);

    $top = smartProposals($this, $line)[0];

    expect($top['rule'])->toBe('amount_counterparty')->and($top['confidence'])->toBe(95)->and($top['reasons'])->toContain('exact_amount', 'tax_id');
});

test('rule 2: the counterparty is also recognised by a normalised name, with a lower confidence', function (): void {
    smartInvoice($this, 'FT-2026/102', '305.00');
    $line = smartLine($this->statement, ['amount' => '305.00', 'description' => 'Bonifico', 'counterpart_name' => 'BETA CONSULTING S.r.l.']);

    $top = smartProposals($this, $line)[0];

    expect($top['rule'])->toBe('amount_counterparty')->and($top['confidence'])->toBe(85)->and($top['reasons'])->toContain('name');
});

test('rule 2: two open invoices of the same amount make the proposals ambiguous', function (): void {
    smartInvoice($this, 'AAA-1', '200.00');
    smartInvoice($this, 'BBB-2', '200.00');
    $line = smartLine($this->statement, ['amount' => '200.00', 'description' => 'P.IVA IT01234567897']);

    $proposals = smartProposals($this, $line);

    expect($proposals)->toHaveCount(2)
        ->and(collect($proposals)->pluck('confidence')->unique()->all())->toBe([85])
        ->and($proposals[0]['reasons'])->toContain('ambiguous');
});

test('rule 3: the invoice number in the description with a small shortfall is a bank fee, booked as a charge', function (): void {
    $invoice = smartInvoice($this, 'FT 2026/045', '500.00');
    $line = smartLine($this->statement, ['amount' => '497.50', 'description' => 'Saldo FT2026-045']);

    $top = smartProposals($this, $line)[0];

    expect($top['rule'])->toBe('fuzzy_invoice')
        ->and($top['kind'])->toBe('fee_tolerance')
        ->and($top['fee_amount'])->toBe('2.50')
        ->and($top['confidence'])->toBe(80)
        ->and($top['reasons'])->toContain('invoice_number', 'bank_fee_tolerance');

    $this->postJson("/api/v1/treasury/smart-reconciliation/proposals/{$top['id']}/apply")->assertOk();

    $payments = Payment::withoutGlobalScopes()->where('bank_statement_line_id', $line->id)->get();

    expect($invoice->refresh()->payment_status)->toBe('paid')
        ->and($payments->pluck('type')->sort()->values()->all())->toBe(['bank_fee', 'payment'])
        ->and((float) $payments->firstWhere('type', 'bank_fee')->amount)->toBe(2.5)
        ->and((float) $line->refresh()->fee_amount)->toBe(2.5);
});

test('rule 3: a typo in the invoice number is forgiven with a lower confidence', function (): void {
    smartInvoice($this, 'INV20260077', '80.00');
    $line = smartLine($this->statement, ['amount' => '80.00', 'description' => 'Pagamento INV20260078']);

    $top = smartProposals($this, $line)[0];

    expect($top['rule'])->toBe('fuzzy_invoice')->and($top['confidence'])->toBe(70)->and($top['reasons'])->toContain('invoice_number_approximate');
});

test('a payment below the balance is proposed as a partial settlement that keeps the rest open', function (): void {
    $invoice = smartInvoice($this, 'INV-900-A', '1000.00');
    $line = smartLine($this->statement, ['amount' => '400.00', 'description' => 'acconto INV-900-A']);

    $top = smartProposals($this, $line)[0];

    expect($top['kind'])->toBe('partial')->and($top['residual_amount'])->toBe('600.00')->and($top['matched_amount'])->toBe('400.00');

    $this->postJson("/api/v1/treasury/smart-reconciliation/proposals/{$top['id']}/apply")->assertOk();

    expect((float) $invoice->refresh()->outstandingAmount())->toBe(600.0);
});

test('an overpayment is never proposed', function (): void {
    smartInvoice($this, 'INV-OVER', '100.00');
    $line = smartLine($this->statement, ['amount' => '150.00', 'description' => 'INV-OVER']);

    expect(smartProposals($this, $line))->toBe([]);
});

test('an outgoing payment is matched with the supplier invoice named in its description', function (): void {
    $invoice = treasurySupplierInvoice($this->tenant, $this->supplier, 'FA-77/2026', '300.00');
    $line = smartLine($this->statement, ['direction' => 'debit', 'amount' => '300.00', 'description' => 'Pagamento fattura FA-77/2026']);

    $top = smartProposals($this, $line)[0];

    expect($top['document_type'])->toBe('passive_invoice')->and($top['confidence'])->toBe(85);

    $this->postJson("/api/v1/treasury/smart-reconciliation/proposals/{$top['id']}/apply")->assertOk();

    expect($invoice->refresh()->payment_status)->toBe('paid')
        ->and(SupplierPayment::withoutGlobalScopes()->where('bank_statement_line_id', $line->id)->exists())->toBeTrue();
});

test('a foreign currency payment is proposed with the exchange difference when a rate exists', function (): void {
    ExchangeRate::create(['source' => 'EUR', 'target' => 'CHF', 'rate' => '0.950000', 'effective_date' => now()->subDay()->toDateString()]);
    smartInvoice($this, 'FT-FX-1', '1000.00');
    $chfStatement = smartStatement($this->tenant->id, 'CHF');
    $line = smartLine($chfStatement, ['amount' => '955.00', 'description' => 'Payment FT-FX-1']);

    $top = smartProposals($this, $line)[0];

    expect($top['kind'])->toBe('fx')
        ->and($top['fx_difference'])->toBe('5.26')
        ->and($top['matched_amount'])->toBe('1000.00')
        ->and($top['reasons'])->toContain('fx_difference');
});

test('a foreign currency payment without a rate, or far from the balance, gets no proposal', function (): void {
    smartInvoice($this, 'FT-FX-2', '1000.00');
    $chfStatement = smartStatement($this->tenant->id, 'CHF');
    $line = smartLine($chfStatement, ['amount' => '955.00', 'description' => 'Payment FT-FX-2']);

    expect(smartProposals($this, $line))->toBe([]);

    ExchangeRate::create(['source' => 'EUR', 'target' => 'CHF', 'rate' => '0.950000', 'effective_date' => now()->subDay()->toDateString()]);
    $far = smartLine($chfStatement, ['line_number' => 2, 'amount' => '500.00', 'description' => 'Payment FT-FX-2']);

    expect(smartProposals($this, $far))->toBe([]);
});

test('a reconciliation is taken back: payments are reversed, the invoice is open again and the proposal returns', function (): void {
    $invoice = smartInvoice($this, 'FT-2026/200', '300.00', ['qr_reference' => SMART_QRR]);
    $line = smartLine($this->statement, ['amount' => '300.00', 'qr_reference' => SMART_QRR]);
    $proposal = smartProposals($this, $line)[0];
    $this->postJson("/api/v1/treasury/smart-reconciliation/proposals/{$proposal['id']}/apply")->assertOk();

    $this->postJson("/api/v1/treasury/smart-reconciliation/lines/{$line->id}/unmatch")->assertOk()->assertJsonPath('data.reconciled', false);

    expect($invoice->refresh()->payment_status)->not->toBe('paid')
        ->and((float) $invoice->outstandingAmount())->toBe(300.0)
        ->and(Payment::withoutGlobalScopes()->where('bank_statement_line_id', $line->id)->count())->toBe(0)
        ->and(ReconciliationProposal::query()->find($proposal['id'])->status)->toBe('pending');
});

test('a rejected proposal is not proposed again', function (): void {
    smartInvoice($this, 'FT-2026/300', '90.00', ['qr_reference' => SMART_QRR]);
    $line = smartLine($this->statement, ['amount' => '90.00', 'qr_reference' => SMART_QRR]);
    $proposal = smartProposals($this, $line)[0];

    $this->postJson("/api/v1/treasury/smart-reconciliation/proposals/{$proposal['id']}/reject")->assertOk()->assertJsonPath('data.status', 'rejected');

    expect(smartProposals($this, $line))->toBe([]);
    $this->postJson("/api/v1/treasury/smart-reconciliation/proposals/{$proposal['id']}/apply")->assertUnprocessable();
});

test('running the engine on a statement applies only the unique, confident proposals', function (): void {
    smartInvoice($this, 'FT-2026/400', '120.00', ['qr_reference' => SMART_QRR]);
    smartInvoice($this, 'INV-PARTIAL-9', '900.00');
    $sure = smartLine($this->statement, ['line_number' => 1, 'amount' => '120.00', 'qr_reference' => SMART_QRR]);
    $partial = smartLine($this->statement, ['line_number' => 2, 'amount' => '100.00', 'description' => 'INV-PARTIAL-9']);
    $none = smartLine($this->statement, ['line_number' => 3, 'amount' => '33.00', 'description' => 'unknown']);

    $result = $this->postJson('/api/v1/treasury/smart-reconciliation/run', ['statement_id' => $this->statement->id, 'auto_apply' => true])->assertOk()->json('data');

    expect($result)->toBe(['proposed' => 2, 'applied' => 1, 'lines_without_proposal' => 1])
        ->and($sure->refresh()->reconciled)->toBeTrue()
        ->and($partial->refresh()->reconciled)->toBeFalse()
        ->and($none->refresh()->reconciled)->toBeFalse();
});

test('the workbench lists lines with their proposals, filters by confidence and counts them', function (): void {
    smartInvoice($this, 'FT-2026/500', '50.00', ['qr_reference' => SMART_QRR]);
    smartInvoice($this, 'INV-LOW-1', '700.00');
    $high = smartLine($this->statement, ['line_number' => 1, 'amount' => '50.00', 'qr_reference' => SMART_QRR]);
    smartLine($this->statement, ['line_number' => 2, 'amount' => '70.00', 'description' => 'INV-LOW-1']);
    $this->postJson('/api/v1/treasury/smart-reconciliation/run', ['statement_id' => $this->statement->id, 'auto_apply' => false])->assertOk();

    $lines = $this->getJson("/api/v1/treasury/smart-reconciliation/lines?statement_id={$this->statement->id}&min_confidence=95")->assertOk();

    expect($lines->json('data'))->toHaveCount(1)
        ->and($lines->json('data.0.id'))->toBe($high->id)
        ->and($lines->json('data.0.proposals.0.confidence'))->toBe(100)
        ->and($lines->json('meta.total'))->toBe(1);

    $this->getJson('/api/v1/treasury/smart-reconciliation/summary')->assertOk()
        ->assertJsonPath('data.open_lines', 2)->assertJsonPath('data.lines_with_proposals', 2)->assertJsonPath('data.high_confidence', 1);
});

test('lines, proposals and statements of another company are not reachable', function (): void {
    smartInvoice($this, 'FT-2026/600', '10.00', ['qr_reference' => SMART_QRR]);
    $line = smartLine($this->statement, ['amount' => '10.00', 'qr_reference' => SMART_QRR]);
    $proposal = smartProposals($this, $line)[0];
    treasuryActor($this, 'accountant', $this->otherTenant);

    $this->postJson("/api/v1/treasury/smart-reconciliation/lines/{$line->id}/refresh")->assertNotFound();
    $this->postJson("/api/v1/treasury/smart-reconciliation/proposals/{$proposal['id']}/apply")->assertNotFound();
    $this->postJson('/api/v1/treasury/smart-reconciliation/run', ['statement_id' => $this->statement->id])->assertNotFound();
});
