<?php

declare(strict_types=1);

use App\Models\PassiveInvoice;
use App\Models\SupplierPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\GeneralLedger\Actions\PostPassiveInvoiceAction;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;

require_once __DIR__.'/AccountsPayableSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    apSetUp($this);
    $this->admin = treasuryActor($this, 'admin');
    treasuryActAs($this, $this->accountant);
});

/**
 * A supplier invoice (or credit note) that reached the ledger: debt of 122.00 gross with 22.00 of VAT, or of the amount given.
 */
function apPostedInvoice(object $test, string $number, string $gross, string $net, string $tax, array $overrides = []): PassiveInvoice
{
    $invoice = PassiveInvoice::create(array_merge([
        'tenant_id' => $test->tenant->id, 'supplier_id' => $test->supplier->id, 'invoice_number' => $number, 'date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        'total_amount' => $gross, 'total_net' => $net, 'total_tax' => $tax, 'currency' => 'EUR', 'document_type' => 'TD01', 'payment_status' => 'unpaid',
    ], $overrides));
    app(PostPassiveInvoiceAction::class)->execute($invoice);

    return $invoice->refresh();
}

/**
 * The lines of the entry of a supplier payment as [account code, debit, credit].
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function apPaymentPostings(string $paymentId): array
{
    $entry = JournalEntry::withoutGlobalScopes()->where('source_document_type', 'supplier_payment')->where('source_document_id', $paymentId)->firstOrFail();

    return JournalLine::withoutGlobalScopes()->where('journal_entry_id', $entry->id)->orderBy('line_number')->get()
        ->map(fn (JournalLine $line): array => [(string) ChartOfAccount::withoutGlobalScopes()->find($line->account_id)->account_code, bcadd((string) $line->debit_amount, '0', 2), bcadd((string) $line->credit_amount, '0', 2)])
        ->all();
}

function apPaymentOf(PassiveInvoice $invoice, string $type): SupplierPayment
{
    return SupplierPayment::withoutGlobalScopes()->where('passive_invoice_id', $invoice->id)->where('type', $type)->firstOrFail();
}

function apExecuteRun(object $test): void
{
    treasuryActAs($test, $test->accountant);
    $run = $test->postJson('/api/v1/accounts-payable/payment-runs', apRunPayload(['cutoff_date' => now()->addDays(30)->toDateString()]))->assertCreated()->json('data');
    $test->postJson("/api/v1/accounts-payable/payment-runs/{$run['id']}/submit")->assertOk();
    treasuryActAs($test, $test->admin);
    $test->postJson("/api/v1/accounts-payable/payment-runs/{$run['id']}/approve")->assertOk();
    $test->postJson("/api/v1/accounts-payable/payment-runs/{$run['id']}/execute")->assertOk();
}

it('posts the active cash discount when a run pays within the Skonto terms', function (): void {
    $this->postJson('/api/v1/accounts-payable/supplier-terms', ['supplier_id' => $this->supplier->id, 'clause' => '30 gg data fattura, 2% skonto entro 10 gg'])->assertCreated();
    $invoice = apPostedInvoice($this, 'SK-LEDGER', '122.00', '100.00', '22.00');

    expect(apAccountBalance($this->tenant->id, '2000'))->toBe('-122.00');

    apExecuteRun($this);

    $discount = apPaymentOf($invoice, 'cash_discount');
    $payment = apPaymentOf($invoice, 'payment');

    // 2% of 122.00 is 2.44: the supplier is paid 119.56, the payable is cleared, the discount is income.
    expect((string) $discount->amount)->toBe('2.44')
        ->and((string) $payment->amount)->toBe('119.56')
        ->and(apPaymentPostings((string) $discount->id))->toBe([['2000', '2.44', '0.00'], ['4090', '0.00', '2.44']])
        ->and(apPaymentPostings((string) $payment->id))->toBe([['2000', '119.56', '0.00'], ['1020', '0.00', '119.56']])
        ->and(apAccountBalance($this->tenant->id, '2000'))->toBe('0.00')
        ->and(apAccountBalance($this->tenant->id, '4090'))->toBe('-2.44')
        ->and(apAccountBalance($this->tenant->id, '1020'))->toBe('-119.56')
        ->and($invoice->refresh()->payment_status)->toBe('paid');
});

it('posts the discount to the account chosen in the ledger settings', function (): void {
    $income = treasuryAccount($this->tenant, '4120', 'Sconti attivi');
    $income->forceFill(['account_class' => 'revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit'])->save();
    $this->tenant->update(['settings' => array_replace_recursive($this->tenant->settings ?? [], ['preferences' => ['accounting' => ['accounts' => ['cash_discount_account_code' => '4120']]]])]);

    $this->postJson('/api/v1/accounts-payable/supplier-terms', ['supplier_id' => $this->supplier->id, 'skonto_percentage' => 1, 'skonto_days' => 30])->assertCreated();
    $invoice = apPostedInvoice($this, 'SK-ACC', '122.00', '100.00', '22.00');

    apExecuteRun($this);

    expect(apPaymentPostings((string) apPaymentOf($invoice, 'cash_discount')->id))->toBe([['2000', '1.22', '0.00'], ['4120', '0.00', '1.22']])
        ->and(apAccountBalance($this->tenant->id, '4090'))->toBe('0.00');
});

it('posts no discount when the Skonto deadline has passed', function (): void {
    $this->postJson('/api/v1/accounts-payable/supplier-terms', ['supplier_id' => $this->supplier->id, 'skonto_percentage' => 2, 'skonto_days' => 5])->assertCreated();
    $invoice = apPostedInvoice($this, 'SK-LATE', '122.00', '100.00', '22.00', ['date' => now()->toDateString()]);
    $invoice->forceFill(['date' => now()->subDays(20)->toDateString()])->saveQuietly();

    apExecuteRun($this);

    expect(SupplierPayment::withoutGlobalScopes()->where('passive_invoice_id', $invoice->id)->where('type', 'cash_discount')->count())->toBe(0)
        ->and((string) apPaymentOf($invoice, 'payment')->amount)->toBe('122.00')
        ->and(apAccountBalance($this->tenant->id, '4090'))->toBe('0.00')
        ->and(apAccountBalance($this->tenant->id, '1020'))->toBe('-122.00');
});

it('clears the invoice and the credit note against each other without moving money', function (): void {
    $invoice = apPostedInvoice($this, 'INV-OFFSET', '122.00', '100.00', '22.00');
    $credit = apPostedInvoice($this, 'NC-OFFSET', '61.00', '50.00', '11.00', ['document_type' => 'TD04']);

    expect(apAccountBalance($this->tenant->id, '2000'))->toBe('-61.00');

    apExecuteRun($this);

    $offset = apPaymentOf($invoice, 'credit_offset');
    $cash = apPaymentOf($invoice, 'payment');

    // 61.00 goes to the supplier, 61.00 is cleared between the two documents: Dr payables (invoice) / Cr payables (credit note).
    expect((string) $offset->amount)->toBe('61.00')
        ->and((string) $cash->amount)->toBe('61.00')
        ->and($offset->offset_passive_invoice_id)->toBe($credit->id)
        ->and(apPaymentPostings((string) $offset->id))->toBe([['2000', '61.00', '0.00'], ['2000', '0.00', '61.00']])
        ->and(apPaymentPostings((string) $cash->id))->toBe([['2000', '61.00', '0.00'], ['1020', '0.00', '61.00']])
        ->and(SupplierPayment::withoutGlobalScopes()->where('passive_invoice_id', $credit->id)->where('type', 'credit_used')->count())->toBe(1)
        ->and(apAccountBalance($this->tenant->id, '2000'))->toBe('0.00')
        ->and(apAccountBalance($this->tenant->id, '1020'))->toBe('-61.00')
        ->and([$invoice->refresh()->payment_status, $credit->refresh()->payment_status])->toBe(['paid', 'paid']);
});

it('pays from the bank account chosen for the run', function (): void {
    $bank = treasuryBank($this->tenant, 'IT21Q0542811101000000654321', $this->accounts['1003']);
    $invoice = apPostedInvoice($this, 'INV-BANK', '122.00', '100.00', '22.00');
    $run = $this->postJson('/api/v1/accounts-payable/payment-runs', apRunPayload(['cutoff_date' => now()->addDays(30)->toDateString(), 'bank_account_id' => $bank->id]))->assertCreated()->json('data');
    $this->postJson("/api/v1/accounts-payable/payment-runs/{$run['id']}/submit")->assertOk();
    treasuryActAs($this, $this->admin);
    $this->postJson("/api/v1/accounts-payable/payment-runs/{$run['id']}/approve")->assertOk();
    $this->postJson("/api/v1/accounts-payable/payment-runs/{$run['id']}/execute")->assertOk();

    expect(apPaymentPostings((string) apPaymentOf($invoice, 'payment')->id))->toBe([['2000', '122.00', '0.00'], ['1003', '0.00', '122.00']]);
});
