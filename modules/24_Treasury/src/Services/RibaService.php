<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use App\Actions\Sales\RecordPayment;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\LedgerAccountResolver;
use Modules\GeneralLedger\Services\PaymentLedgerService;
use Modules\Treasury\Contracts\PaymentFactoryServiceInterface;
use Modules\Treasury\Contracts\TreasuryPositionServiceInterface;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\PaymentLine;
use Modules\Treasury\Models\RibaPresentation;
use Modules\Treasury\Models\RibaReceipt;

/**
 * Ri.Ba. collections on top of the payment batch flow: the batch is created, submitted and approved like any other,
 * then the export writes the CBI flat file instead of a pain.001, and each receipt ends paid or unpaid.
 */
final readonly class RibaService
{
    /** ModalitaPagamento of the FatturaPA schema: payments store this code. */
    private const string FatturaPaRibaCode = 'MP12';

    /** Source of the ledger entry of the commission on an unpaid receipt. */
    private const string UnpaidFeeSource = 'riba_unpaid_fee';

    public function __construct(
        private PaymentFactoryServiceInterface $paymentFactory,
        private RibaFlatFileBuilder $builder,
        private RecordPayment $payments,
        private PaymentLedgerService $ledger,
        private TreasuryPositionServiceInterface $positions,
        private LedgerAccountResolver $accounts,
    ) {}

    /**
     * @param  array{bank_account_id: string, sia_code: string, batch_reference?: ?string, receipts: list<array{invoice_id: string, due_date: string, amount?: numeric-string|float|int|null, debtor_abi?: ?string, debtor_cab?: ?string}>}  $data
     *
     * @throws InvalidArgumentException when a receipt cannot be collected by Ri.Ba.
     */
    public function createBatch(array $data, string $tenantId, ?string $userId): RibaPresentation
    {
        return DB::transaction(function () use ($data, $tenantId, $userId): RibaPresentation {
            $this->bankCoordinates(BankAccount::findOrFail($data['bank_account_id']));

            $prepared = array_map(fn (array $receipt): array => $this->prepared($receipt), $data['receipts']);
            $lines = array_map(static fn (array $receipt): array => [
                'recipient_type' => 'customer',
                'recipient_id' => $receipt['contact_id'],
                'recipient_name' => $receipt['debtor_name'],
                'recipient_iban' => $receipt['iban'],
                'amount' => $receipt['amount'],
                'remittance_reference' => $receipt['reference'],
                'invoice_id' => $receipt['invoice_id'],
            ], $prepared);

            $batch = $this->paymentFactory->createBatch([
                'tenant_id' => $tenantId,
                'bank_account_id' => $data['bank_account_id'],
                'batch_reference' => $data['batch_reference'] ?? null,
                'payment_method' => 'riba',
                'format' => 'cbi_riba',
                'currency' => 'EUR',
            ], $lines, $userId);

            $presentation = RibaPresentation::create(['payment_batch_id' => $batch->id, 'sia_code' => strtoupper($data['sia_code'])]);
            $paymentLines = PaymentLine::where('payment_batch_id', $batch->id)->orderBy('line_number')->get();

            foreach ($prepared as $index => $receipt) {
                unset($receipt['iban'], $receipt['reference'], $receipt['contact_id']);

                $presentation->receipts()->create([
                    ...$receipt,
                    'tenant_id' => $tenantId,
                    'payment_batch_id' => $batch->id,
                    'payment_line_id' => $paymentLines[$index]->id,
                    'receipt_number' => $index + 1,
                ]);
            }

            return $presentation->load('receipts');
        });
    }

    /**
     * @throws InvalidArgumentException when the batch is not approved or was already exported
     */
    public function export(string $batchId, ?string $userId): RibaPresentation
    {
        return DB::transaction(function () use ($batchId, $userId): RibaPresentation {
            $batch = PaymentBatch::with('bankAccount')->lockForUpdate()->findOrFail($batchId);
            $presentation = RibaPresentation::where('payment_batch_id', $batch->id)->firstOrFail();

            if (strtolower((string) $batch->status) !== 'approved') {
                throw new InvalidArgumentException("Payment batch [{$batch->batch_reference}] must be approved before export.");
            }

            $tenant = Tenant::findOrFail($batch->tenant_id);
            $taxCode = (string) preg_replace('/^IT/i', '', (string) ($tenant->vat_number ?: $tenant->fiscal_code));

            if ($taxCode === '') {
                throw new InvalidArgumentException('The company needs a VAT number or a fiscal code to issue Ri.Ba. receipts.');
            }

            $file = $this->builder->build($presentation, [
                'creditor_name' => (string) ($tenant->legal_name ?: $tenant->name),
                'creditor_tax_code' => $taxCode,
                ...$this->bankCoordinates($batch->bankAccount),
            ], (string) $batch->batch_reference, now());

            $presentation->update([
                'flat_file' => $file,
                'file_name' => "{$batch->batch_reference}.txt",
                'file_sha256' => hash('sha256', $file),
                'exported_at' => now(),
            ]);

            $batch->update(['status' => 'exported', 'exported_at' => now(), 'exported_by' => $userId, 'xml_sha256' => hash('sha256', $file)]);
            $presentation->receipts()->update(['status' => 'presented']);

            return $presentation->load('receipts');
        });
    }

    /**
     * Registers the collection through the ordinary payment flow.
     */
    public function markPaid(RibaReceipt $receipt, string $paidAt): RibaReceipt
    {
        return DB::transaction(function () use ($receipt, $paidAt): RibaReceipt {
            $receipt = RibaReceipt::lockForUpdate()->findOrFail($receipt->id);

            if ($receipt->status !== 'presented') {
                throw new InvalidArgumentException('Only a presented receipt can be marked as paid.');
            }

            $bankAccountId = (string) PaymentBatch::whereKey($receipt->payment_batch_id)->value('bank_account_id');
            $payment = $this->payments->execute($receipt->invoice, [
                'amount' => (string) $receipt->amount,
                'paid_at' => $paidAt,
                'payment_method' => self::FatturaPaRibaCode,
                'reference' => 'RIBA-'.$receipt->receipt_number,
                'bank_account_id' => $bankAccountId,
            ]);
            $this->positions->updateBalance($bankAccountId, (float) $receipt->amount, 'riba_collection');

            $receipt->update(['status' => 'paid', 'payment_id' => $payment->id]);
            $this->settle($receipt, 'executed');

            return $receipt;
        });
    }

    /**
     * Records an insoluto. A collection already registered is undone, so the invoice is open again and can go into a
     * new batch, and the bank balance gives the amount back. The bank fee is posted to the ledger, Dr the account set
     * in `banking.ledger_defaults.riba_unpaid_fee_account_code` (the bank charges account when unset) and Cr the bank
     * of the batch, whose balance it reduces.
     *
     * @param  array{reason: string, unpaid_at: string, fee?: numeric-string|float|int|null}  $outcome
     *
     * @throws InvalidArgumentException when the configured fee account does not exist
     */
    public function markUnpaid(RibaReceipt $receipt, array $outcome): RibaReceipt
    {
        return DB::transaction(function () use ($receipt, $outcome): RibaReceipt {
            $receipt = RibaReceipt::lockForUpdate()->findOrFail($receipt->id);

            if (! in_array($receipt->status, ['presented', 'paid'], true)) {
                throw new InvalidArgumentException('Only a presented or paid receipt can become unpaid.');
            }

            $batch = PaymentBatch::with('bankAccount')->findOrFail($receipt->payment_batch_id);

            if ($receipt->payment_id !== null) {
                $payment = Payment::findOrFail($receipt->payment_id);
                $isBalanceManaged = $payment->bank_account_id !== null;
                $this->ledger->reverse(PaymentLedgerService::SOURCE_CUSTOMER, (string) $payment->id, (string) $payment->tenant_id, CarbonImmutable::parse($payment->paid_at), $isBalanceManaged);

                if ($isBalanceManaged) {
                    $this->positions->updateBalance((string) $payment->bank_account_id, -1 * (float) $payment->amount, 'riba_unpaid');
                }

                $payment->delete();
                $this->payments->recalculate($receipt->invoice);
            }

            $this->postUnpaidFee($receipt, $batch, (string) $outcome['unpaid_at'], bcadd((string) ($outcome['fee'] ?? '0'), '0', 2));

            $receipt->update([
                'status' => 'unpaid',
                'payment_id' => null,
                'unpaid_at' => $outcome['unpaid_at'],
                'unpaid_reason' => $outcome['reason'],
                'unpaid_fee' => $outcome['fee'] ?? null,
            ]);
            $this->settle($receipt, 'rejected');

            return $receipt;
        });
    }

    /**
     * The commission the bank charges on an insoluto: posted once per receipt, and taken off the balance of the bank.
     */
    private function postUnpaidFee(RibaReceipt $receipt, PaymentBatch $batch, string $unpaidAt, string $fee): void
    {
        if (bccomp($fee, '0', 2) <= 0) {
            return;
        }

        $tenantId = (string) $receipt->tenant_id;
        $alreadyPosted = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('source_document_type', self::UnpaidFeeSource)
            ->where('source_document_id', $receipt->id)
            ->where('status', 'posted')
            ->exists();

        if ($alreadyPosted) {
            return;
        }

        $this->ledger->postBankCharge(
            $tenantId,
            self::UnpaidFeeSource,
            (string) $receipt->id,
            $unpaidAt,
            $fee,
            (string) ($batch->currency ?: 'EUR'),
            $this->unpaidFeeAccount($tenantId),
            "Ri.Ba. {$batch->batch_reference}/{$receipt->receipt_number}",
            ['iban' => $batch->bankAccount?->iban, 'bank_balance_managed' => true],
        );
        $this->positions->updateBalance((string) $batch->bank_account_id, -1 * (float) $fee, 'riba_unpaid_fee');
    }

    /**
     * @throws InvalidArgumentException when the configured account is not in the chart of accounts of the tenant
     */
    private function unpaidFeeAccount(string $tenantId): ChartOfAccount
    {
        $code = Tenant::find($tenantId)?->settings['preferences']['banking']['ledger_defaults']['riba_unpaid_fee_account_code'] ?? null;

        if ($code === null || $code === '') {
            return $this->accounts->bankCharges($tenantId);
        }

        return ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('account_code', $code)->first()
            ?? throw new InvalidArgumentException("The Ri.Ba. unpaid fee account [{$code}] is not in the chart of accounts.");
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @return array<string, mixed>
     */
    private function prepared(array $receipt): array
    {
        $invoice = Invoice::with('contact.addresses')->lockForUpdate()->findOrFail($receipt['invoice_id']);
        $contact = $invoice->contact;

        if ($invoice->invoice_type === 'proforma' || $contact === null) {
            throw new InvalidArgumentException("Invoice {$invoice->invoice_number} cannot be collected by Ri.Ba.");
        }

        $outstanding = bcadd($invoice->outstandingAmount(), '0', 2);
        $committed = $this->committedAmount((string) $invoice->id);
        $amount = bcadd((string) ($receipt['amount'] ?? bcsub($outstanding, $committed, 2)), '0', 2);

        if (bccomp($amount, '0', 2) <= 0 || bccomp(bcadd($committed, $amount, 2), $outstanding, 2) === 1) {
            throw new InvalidArgumentException("The Ri.Ba. amount of invoice {$invoice->invoice_number} is above its open balance.");
        }

        $address = $contact->addresses->first();
        $taxCode = (string) preg_replace('/^IT/i', '', (string) ($contact->fiscal_code ?: $contact->vat_number));
        [$abi, $cab] = $this->debtorBank($contact, $receipt);

        if ($taxCode === '' || $address === null || empty($address->postal_code) || empty($address->city) || empty($address->street)) {
            throw new InvalidArgumentException("The customer of invoice {$invoice->invoice_number} needs a tax code and a full address for Ri.Ba.");
        }

        return [
            'invoice_id' => $invoice->id,
            'contact_id' => $contact->id,
            'iban' => (string) $contact->iban,
            'reference' => "Ri.Ba. {$invoice->invoice_number}",
            'due_date' => $receipt['due_date'],
            'amount' => $amount,
            'debtor_name' => $this->contactName($contact),
            'debtor_tax_code' => $taxCode,
            'debtor_address' => trim($address->street.' '.$address->house_number),
            'debtor_postal_code' => (string) $address->postal_code,
            'debtor_city' => trim($address->city.' '.$address->state_canton),
            'debtor_abi' => $abi,
            'debtor_cab' => $cab,
        ];
    }

    /**
     * What other receipts already expect to collect on the invoice. Cancelled and rejected batches free their receipts.
     */
    private function committedAmount(string $invoiceId): string
    {
        $amount = RibaReceipt::query()
            ->join('payment_batches as batch', 'batch.id', '=', 'riba_receipts.payment_batch_id')
            ->where('riba_receipts.invoice_id', $invoiceId)
            ->whereIn('riba_receipts.status', ['pending', 'presented'])
            ->whereNotIn('batch.status', ['cancelled', 'canceled', 'rejected'])
            ->sum('riba_receipts.amount');

        return bcadd((string) $amount, '0', 2);
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @return array{0: string, 1: string}
     */
    private function debtorBank(Contact $contact, array $receipt): array
    {
        $iban = strtoupper(str_replace(' ', '', (string) $contact->iban));
        $abi = $receipt['debtor_abi'] ?? (str_starts_with($iban, 'IT') ? substr($iban, 5, 5) : '');
        $cab = $receipt['debtor_cab'] ?? (str_starts_with($iban, 'IT') ? substr($iban, 10, 5) : '');

        if (preg_match('/^\d{5}$/', (string) $abi) !== 1 || preg_match('/^\d{5}$/', (string) $cab) !== 1) {
            throw new InvalidArgumentException("State the ABI and CAB of the bank of {$this->contactName($contact)}.");
        }

        return [(string) $abi, (string) $cab];
    }

    /**
     * @return array{abi: string, cab: string, account: string}
     */
    private function bankCoordinates(BankAccount $account): array
    {
        $iban = strtoupper(str_replace(' ', '', (string) $account->iban));

        if (preg_match('/^IT\d{2}[A-Z](\d{5})(\d{5})([0-9A-Z]{12})$/', $iban, $parts) !== 1) {
            throw new InvalidArgumentException('Ri.Ba. needs a bank account with an Italian IBAN.');
        }

        return ['abi' => $parts[1], 'cab' => $parts[2], 'account' => $parts[3]];
    }

    private function contactName(Contact $contact): string
    {
        return (string) ($contact->company_name ?: trim($contact->first_name.' '.$contact->last_name));
    }

    private function settle(RibaReceipt $receipt, string $lineStatus): void
    {
        PaymentLine::where('id', $receipt->payment_line_id)->update(['status' => $lineStatus]);

        $open = RibaReceipt::where('payment_batch_id', $receipt->payment_batch_id)->where('status', 'presented')->exists();
        $hasPaid = RibaReceipt::where('payment_batch_id', $receipt->payment_batch_id)->where('status', 'paid')->exists();
        $hasUnpaid = RibaReceipt::where('payment_batch_id', $receipt->payment_batch_id)->where('status', 'unpaid')->exists();

        PaymentBatch::where('id', $receipt->payment_batch_id)->update(['status' => match (true) {
            $open || ($hasPaid && $hasUnpaid) => 'partially_executed',
            $hasPaid => 'executed',
            default => 'rejected',
        }]);
    }
}
