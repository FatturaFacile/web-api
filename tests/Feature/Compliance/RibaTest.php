<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\ContactAddress;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Invoicing\InvoiceService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\LedgerAccountResolver;
use Modules\Treasury\Contracts\BankReconciliationServiceInterface;
use Modules\Treasury\Contracts\CashForecastServiceInterface;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\CashForecastLine;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\ReconciliationMatch;
use Modules\Treasury\Models\RibaReceipt;

require_once __DIR__.'/ComplianceHelpers.php';

uses(RefreshDatabase::class);

function ribaBank(Tenant $tenant, string $iban = 'IT60X0542811101000000123456'): BankAccount
{
    return BankAccount::create([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'company_id' => $tenant->id, 'bank_name' => 'Banca', 'branch_name' => 'Milano',
        'account_number' => 'ACC-'.Str::random(6), 'account_type' => 'OPERATING', 'iban' => $iban, 'bic_swift' => 'BCITITMM', 'currency' => 'EUR',
        'opening_balance' => 0, 'current_balance' => 0, 'available_balance' => 0, 'overdraft_limit' => 0, 'is_active' => true,
    ]);
}

function ribaInvoice(Tenant $tenant, string $gross = '122.00', string $number = 'FT-1'): Invoice
{
    $contact = Contact::create([
        'tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => 'Cliente Più Srl', 'vat_number' => '12345678901', 'fiscal_code' => '12345678901',
        'iban' => 'IT60X0306909606100000000001',
    ]);
    ContactAddress::create(['contact_id' => $contact->id, 'type' => 'billing', 'street' => 'Via Roma', 'house_number' => '1', 'postal_code' => '20100', 'city' => 'Milano', 'state_canton' => 'MI', 'country' => 'IT']);

    $invoice = new Invoice(['contact_id' => $contact->id, 'invoice_type' => 'standard', 'invoice_number' => $number, 'date' => '2027-01-15', 'due_date' => '2027-02-15', 'currency' => 'EUR', 'total_net' => '100.00', 'total_tax' => '22.00', 'total_gross' => $gross, 'total_paid' => 0, 'payment_status' => 'unpaid']);
    $invoice->tenant_id = $tenant->id;
    $invoice->save();

    return $invoice;
}

function ribaApproved(array $receipts, BankAccount $bank): string
{
    $id = test()->postJson('/api/v1/treasury/riba/batches', ['bank_account_id' => $bank->id, 'sia_code' => 'A1B2C', 'batch_reference' => 'RIBA-2027-001', 'receipts' => $receipts])
        ->assertCreated()->json('data.payment_batch_id');

    test()->postJson("/api/v1/treasury/payment-batches/{$id}/submit-approval")->assertOk();

    $tenantId = test()->tenant->id;
    actingAsMember(test()->tenant, 'accountant');
    test()->postJson("/api/v1/treasury/payment-batches/{$id}/approve")->assertOk();

    return $id;
}

beforeEach(function (): void {
    $this->travelTo('2027-02-01 10:00:00');
    $this->tenant = complianceTenant();
    actingAsMember($this->tenant);
    fiscalCalendar($this->tenant);
    $this->bank = ribaBank($this->tenant);
    $this->invoice = ribaInvoice($this->tenant);
});

/**
 * @return array<string, array{debit: float, credit: float}>
 */
function ribaEntryLines(string $sourceType, string $sourceId): array
{
    return DB::table('journal_lines')
        ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
        ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
        ->where('journal_entries.source_document_type', $sourceType)
        ->where('journal_entries.source_document_id', $sourceId)
        ->get(['chart_of_accounts.account_code', 'journal_lines.debit_amount', 'journal_lines.credit_amount'])
        ->groupBy('account_code')
        ->map(fn ($lines): array => ['debit' => round((float) $lines->sum('debit_amount'), 2), 'credit' => round((float) $lines->sum('credit_amount'), 2)])
        ->all();
}

/**
 * @return array<string, mixed>
 */
function ribaExportedReceipt(BankAccount $bank, Invoice $invoice): array
{
    $id = ribaApproved([['invoice_id' => $invoice->id, 'due_date' => '2027-03-31']], $bank);

    return test()->postJson("/api/v1/treasury/riba/batches/{$id}/export")->json('data.receipts.0');
}

test('a collection adds to the balance of the bank of the batch, and the insoluto takes amount and fee back off it', function (): void {
    ribaBank($this->tenant, 'IT02L1234512345123456789012');
    $receipt = ribaExportedReceipt($this->bank, $this->invoice);

    $this->postJson("/api/v1/treasury/riba/receipts/{$receipt['id']}/paid", ['paid_at' => '2027-03-31'])->assertOk();
    expect((float) $this->bank->fresh()->current_balance)->toBe(122.0);

    $this->postJson("/api/v1/treasury/riba/receipts/{$receipt['id']}/unpaid", ['reason' => 'Insoluto', 'unpaid_at' => '2027-04-05', 'fee' => '7.50'])->assertOk();

    expect((float) $this->bank->fresh()->current_balance)->toBe(-7.5)
        ->and(ribaEntryLines('riba_unpaid_fee', $receipt['id']))->toBe([
            '6800' => ['debit' => 7.5, 'credit' => 0.0],
            '1020' => ['debit' => 0.0, 'credit' => 7.5],
        ]);
});

test('a collection on an invoice in the ledger posts to the bank once and moves the balance once', function (): void {
    $this->bank->update(['gl_account_id' => app(LedgerAccountResolver::class)->bank($this->tenant->id)->id]);
    app(TenantContext::class)->setTenant($this->tenant);
    $posted = app(InvoiceService::class)->createInvoice([
        'contact_id' => $this->invoice->contact_id, 'invoice_type' => 'standard', 'date' => '2027-01-20', 'currency' => 'EUR', 'jurisdiction' => 'IT',
        'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22]],
    ]);
    $receipt = ribaExportedReceipt($this->bank, $posted);

    $this->postJson("/api/v1/treasury/riba/receipts/{$receipt['id']}/paid", ['paid_at' => '2027-03-31'])->assertOk();

    $paymentId = RibaReceipt::find($receipt['id'])->payment_id;
    expect((float) $this->bank->fresh()->current_balance)->toBe(122.0)
        ->and(ribaEntryLines('customer_payment', $paymentId))->toBe([
            '1020' => ['debit' => 122.0, 'credit' => 0.0],
            '1100' => ['debit' => 0.0, 'credit' => 122.0],
        ]);

    $this->postJson("/api/v1/treasury/riba/receipts/{$receipt['id']}/unpaid", ['reason' => 'Insoluto', 'unpaid_at' => '2027-04-05'])->assertOk();

    expect((float) $this->bank->fresh()->current_balance)->toBe(0.0)
        ->and(ribaEntryLines('customer_payment', $paymentId)['1020'])->toBe(['debit' => 122.0, 'credit' => 122.0]);
});

test('the unpaid fee goes to the account the tenant configured, and an unknown account is refused', function (): void {
    ChartOfAccount::create(['tenant_id' => $this->tenant->id, 'account_code' => '6895', 'name' => 'Commissioni insoluti', 'account_class' => 'expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'currency' => 'EUR', 'is_active' => true]);
    $this->tenant->update(['settings' => ['preferences' => ['banking' => ['ledger_defaults' => ['riba_unpaid_fee_account_code' => '6895']]]]]);
    $receipt = ribaExportedReceipt($this->bank, $this->invoice);

    $this->postJson("/api/v1/treasury/riba/receipts/{$receipt['id']}/unpaid", ['reason' => 'Insoluto', 'unpaid_at' => '2027-04-05', 'fee' => '4.00'])->assertOk();
    expect(ribaEntryLines('riba_unpaid_fee', $receipt['id'])['6895'])->toBe(['debit' => 4.0, 'credit' => 0.0]);

    $this->tenant->update(['settings' => ['preferences' => ['banking' => ['ledger_defaults' => ['riba_unpaid_fee_account_code' => '9999']]]]]);
    actingAsMember($this->tenant);
    $other = ribaExportedReceipt($this->bank, ribaInvoice($this->tenant, '50.00', 'FT-9'));

    $this->postJson("/api/v1/treasury/riba/receipts/{$other['id']}/unpaid", ['reason' => 'Insoluto', 'unpaid_at' => '2027-04-05', 'fee' => '4.00'])->assertUnprocessable();
    expect(RibaReceipt::find($other['id'])->status)->toBe('presented');
});

test('a statement line of a riba collection is reconciled as a customer payment, never as a supplier payment', function (): void {
    $receipt = ribaExportedReceipt($this->bank, $this->invoice);
    $service = app(BankReconciliationServiceInterface::class);
    $line = ['booking_date' => '2027-03-31', 'value_date' => '2027-03-31', 'customer_reference' => 'Ri.Ba. FT-1'];

    $outgoing = $service->importStatement(['tenant_id' => $this->tenant->id, 'bank_account_id' => $this->bank->id, 'statement_date' => '2027-03-31'], [[...$line, 'amount' => -122.00]]);
    $service->runAutoReconciliation((string) $outgoing->id);
    expect(ReconciliationMatch::where('matched_entity_id', RibaReceipt::find($receipt['id'])->payment_line_id)->exists())->toBeFalse();

    $incoming = $service->importStatement(['tenant_id' => $this->tenant->id, 'bank_account_id' => $this->bank->id, 'statement_date' => '2027-03-31'], [[...$line, 'amount' => 122.00]]);
    $service->runAutoReconciliation((string) $incoming->id);

    expect(ReconciliationMatch::where('matched_entity_id', RibaReceipt::find($receipt['id'])->payment_line_id)->value('matched_entity_type'))->toBe('customer_payment');
});

test('the file has fixed 120 character records in the published order and positions', function (): void {
    $second = ribaInvoice($this->tenant, '50.50', 'FT-2');
    $id = ribaApproved([
        ['invoice_id' => $this->invoice->id, 'due_date' => '2027-03-31'],
        ['invoice_id' => $second->id, 'due_date' => '2027-04-30'],
    ], $this->bank);

    $this->postJson("/api/v1/treasury/riba/batches/{$id}/export")->assertOk();

    $file = $this->get("/api/v1/treasury/riba/batches/{$id}/file")->assertOk()->getContent();
    $lines = explode("\r\n", rtrim($file, "\r\n"));

    expect($lines)->toHaveCount(16)
        ->and(array_unique(array_map('strlen', $lines)))->toBe([120])
        ->and(array_map(static fn (string $line): string => substr($line, 1, 2), array_slice($lines, 0, 9)))->toBe(['IB', '14', '20', '30', '40', '50', '51', '70', '14'])
        ->and(substr($lines[0], 3, 5))->toBe('A1B2C')
        ->and(substr($lines[0], 8, 5))->toBe('05428')
        ->and(substr($lines[0], 13, 6))->toBe('010227')
        ->and(substr($lines[1], 3, 7))->toBe('0000001')
        ->and(substr($lines[1], 22, 6))->toBe('310327')
        ->and(substr($lines[1], 28, 5))->toBe('30000')
        ->and(substr($lines[1], 33, 13))->toBe('0000000012200')
        ->and($lines[1][46])->toBe('-')
        ->and(substr($lines[1], 47, 5))->toBe('05428')
        ->and(substr($lines[1], 52, 5))->toBe('11101')
        ->and(substr($lines[1], 57, 12))->toBe('000000123456')
        ->and(substr($lines[1], 69, 5))->toBe('03069')
        ->and(substr($lines[1], 74, 5))->toBe('09606')
        ->and(substr($lines[1], 91, 5))->toBe('A1B2C')
        ->and($lines[1][96])->toBe('4')
        ->and($lines[1][119])->toBe('E')
        ->and(trim(substr($lines[3], 10, 30)))->toBe('Cliente Piu Srl')
        ->and(trim(substr($lines[3], 70, 16)))->toBe('12345678901')
        ->and(substr($lines[4], 40, 5))->toBe('20100')
        ->and(substr($lines[15], 1, 2))->toBe('EF')
        ->and(substr($lines[15], 45, 7))->toBe('0000002')
        ->and(substr($lines[15], 52, 15))->toBe('000000000017250')
        ->and(substr($lines[15], 67, 15))->toBe('000000000000000')
        ->and(substr($lines[15], 82, 7))->toBe('0000016')
        ->and($lines[15][113])->toBe('E');

    expect(PaymentBatch::find($id)->status)->toBe('exported');
});

test('a batch reaches the export only through the approval flow', function (): void {
    $id = $this->postJson('/api/v1/treasury/riba/batches', ['bank_account_id' => $this->bank->id, 'sia_code' => 'A1B2C', 'receipts' => [['invoice_id' => $this->invoice->id, 'due_date' => '2027-03-31']]])
        ->assertCreated()->json('data.payment_batch_id');

    $this->postJson("/api/v1/treasury/riba/batches/{$id}/export")->assertUnprocessable();
    $this->get("/api/v1/treasury/riba/batches/{$id}/file")->assertNotFound();
    $this->postJson("/api/v1/treasury/payment-batches/{$id}/submit-approval")->assertOk();
    $this->postJson("/api/v1/treasury/payment-batches/{$id}/approve")->assertUnprocessable();
});

test('a riba batch never takes the outflow path', function (): void {
    $id = ribaApproved([['invoice_id' => $this->invoice->id, 'due_date' => '2027-03-31']], $this->bank);

    $this->postJson("/api/v1/treasury/payment-batches/{$id}/export")->assertUnprocessable();

    $this->postJson("/api/v1/treasury/riba/batches/{$id}/export")->assertOk();
    $this->postJson("/api/v1/treasury/payment-batches/{$id}/confirm", ['bank_reference' => 'X'])->assertUnprocessable();
    expect((float) $this->bank->fresh()->current_balance)->toBe(0.0);
});

test('an open riba batch is not forecast as an outflow', function (): void {
    ribaApproved([['invoice_id' => $this->invoice->id, 'due_date' => '2027-03-31']], $this->bank);

    $forecast = app(CashForecastServiceInterface::class)->generateForecast((string) $this->tenant->id, 'BASELINE-TEST', 90, '2027-02-01');

    expect(CashForecastLine::where('cash_forecast_id', $forecast->id)->where('source_reference', 'BATCH-RIBA-2027-001')->exists())->toBeFalse();
});

test('a collected receipt registers the invoice payment and closes the batch', function (): void {
    $id = ribaApproved([['invoice_id' => $this->invoice->id, 'due_date' => '2027-03-31']], $this->bank);
    $receipt = $this->postJson("/api/v1/treasury/riba/batches/{$id}/export")->json('data.receipts.0');

    $this->postJson("/api/v1/treasury/riba/receipts/{$receipt['id']}/paid", ['paid_at' => '2027-03-31'])->assertOk()->assertJsonPath('data.status', 'paid');

    expect($this->invoice->fresh()->payment_status)->toBe('paid')->and(PaymentBatch::find($id)->status)->toBe('executed');
    $this->postJson("/api/v1/treasury/riba/receipts/{$receipt['id']}/paid", ['paid_at' => '2027-03-31'])->assertUnprocessable();
});

test('an insoluto after the collection reopens the invoice for a new batch', function (): void {
    $id = ribaApproved([['invoice_id' => $this->invoice->id, 'due_date' => '2027-03-31']], $this->bank);
    $receipt = $this->postJson("/api/v1/treasury/riba/batches/{$id}/export")->json('data.receipts.0');
    $this->postJson("/api/v1/treasury/riba/receipts/{$receipt['id']}/paid", ['paid_at' => '2027-03-31'])->assertOk();

    $this->postJson("/api/v1/treasury/riba/receipts/{$receipt['id']}/unpaid", ['reason' => 'Conto estinto', 'unpaid_at' => '2027-04-05', 'fee' => '7.50'])
        ->assertOk()->assertJsonPath('data.status', 'unpaid')->assertJsonPath('data.unpaid_fee', '7.50');

    expect($this->invoice->fresh()->payment_status)->toBe('unpaid')
        ->and((float) $this->invoice->fresh()->total_paid)->toBe(0.0)
        ->and(PaymentBatch::find($id)->status)->toBe('rejected');

    actingAsMember($this->tenant);
    $this->postJson('/api/v1/treasury/riba/batches', ['bank_account_id' => $this->bank->id, 'sia_code' => 'A1B2C', 'receipts' => [['invoice_id' => $this->invoice->id, 'due_date' => '2027-05-31']]])->assertCreated();
});

test('an invoice cannot be presented above its open balance', function (): void {
    $payload = ['bank_account_id' => $this->bank->id, 'sia_code' => 'A1B2C', 'receipts' => [['invoice_id' => $this->invoice->id, 'due_date' => '2027-03-31']]];

    $this->postJson('/api/v1/treasury/riba/batches', $payload)->assertCreated();
    $this->postJson('/api/v1/treasury/riba/batches', $payload)->assertUnprocessable();
});

test('missing bank coordinates and foreign accounts are refused', function (): void {
    $this->invoice->contact->update(['iban' => null]);
    $payload = ['bank_account_id' => $this->bank->id, 'sia_code' => 'A1B2C', 'receipts' => [['invoice_id' => $this->invoice->id, 'due_date' => '2027-03-31']]];

    $this->postJson('/api/v1/treasury/riba/batches', $payload)->assertUnprocessable();

    $payload['receipts'][0] += ['debtor_abi' => '03069', 'debtor_cab' => '09606'];
    $this->postJson('/api/v1/treasury/riba/batches', [...$payload, 'bank_account_id' => ribaBank($this->tenant, 'CH9300762011623852957')->id])->assertUnprocessable();
    $this->postJson('/api/v1/treasury/riba/batches', $payload)->assertCreated();
});

test('batches and invoices of another tenant stay out of reach', function (): void {
    $id = ribaApproved([['invoice_id' => $this->invoice->id, 'due_date' => '2027-03-31']], $this->bank);
    $receipt = $this->postJson("/api/v1/treasury/riba/batches/{$id}/export")->json('data.receipts.0');

    $other = complianceTenant('Beta');
    actingAsMember($other);

    $this->getJson("/api/v1/treasury/riba/batches/{$id}")->assertNotFound();
    $this->get("/api/v1/treasury/riba/batches/{$id}/file")->assertNotFound();
    $this->postJson("/api/v1/treasury/riba/receipts/{$receipt['id']}/paid", ['paid_at' => '2027-03-31'])->assertNotFound();
    $this->postJson('/api/v1/treasury/riba/batches', ['bank_account_id' => ribaBank($other)->id, 'sia_code' => 'A1B2C', 'receipts' => [['invoice_id' => $this->invoice->id, 'due_date' => '2027-03-31']]])
        ->assertUnprocessable()->assertJsonValidationErrors('receipts.0.invoice_id');
});

test('the module roles apply to riba routes', function (): void {
    actingAsMember($this->tenant, 'member');
    $this->postJson('/api/v1/treasury/riba/batches', [])->assertForbidden();

    actingAsMember($this->tenant, 'accountant_readonly');
    $this->postJson('/api/v1/treasury/riba/batches', [])->assertForbidden();
});

test('an invoice with withholding is presented for the amount due, which the collection then settles', function (): void {
    $this->invoice->forceFill(['withholding_tax_enabled' => true, 'withholding_tax_amount' => '20.00'])->save();

    $id = ribaApproved([['invoice_id' => $this->invoice->id, 'due_date' => '2027-03-31']], $this->bank);
    $receipt = $this->postJson("/api/v1/treasury/riba/batches/{$id}/export")->json('data.receipts.0');

    expect((string) $receipt['amount'])->toBe('102.00');

    $this->postJson("/api/v1/treasury/riba/receipts/{$receipt['id']}/paid", ['paid_at' => '2027-03-31'])->assertOk()->assertJsonPath('data.status', 'paid');
    expect($this->invoice->fresh()->payment_status)->toBe('paid');

    actingAsMember($this->tenant);
    $this->postJson('/api/v1/treasury/riba/batches', ['bank_account_id' => $this->bank->id, 'sia_code' => 'A1B2C', 'receipts' => [['invoice_id' => $this->invoice->id, 'due_date' => '2027-05-31', 'amount' => '1.00']]])->assertUnprocessable();
});
