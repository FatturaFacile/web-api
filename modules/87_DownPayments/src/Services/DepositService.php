<?php

declare(strict_types=1);

namespace Modules\DownPayments\Services;

use App\Actions\Sales\RecordPayment;
use App\Models\Invoice;
use App\Services\Sales\DocumentNumberingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\DownPayments\Domain\Enums\CalculationMode;
use Modules\DownPayments\Domain\Enums\ChainStatus;
use Modules\DownPayments\Domain\Enums\DepositResolution;
use Modules\DownPayments\Domain\Enums\DepositStatus;
use Modules\DownPayments\Domain\Enums\DepositType;
use Modules\DownPayments\Domain\Enums\InstallmentStatus;
use Modules\DownPayments\Domain\Enums\InstrumentType;
use Modules\DownPayments\Domain\Enums\LinkStatus;
use Modules\DownPayments\Domain\Enums\LinkType;
use Modules\DownPayments\Events\DepositResolved;
use Modules\DownPayments\Exceptions\DownPaymentException;
use Modules\DownPayments\Models\DownPaymentChain;
use Modules\DownPayments\Models\DownPaymentInstallment;
use Modules\DownPayments\Models\DownPaymentLink;
use Modules\DownPayments\Models\EarnestAndSecurityDeposit;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Contracts\CustomerLedgerServiceInterface;

/**
 * Earnest money (caparra confirmatoria, Art. 1385 c.c. / Art. 158 CO) and security deposits (deposito cauzionale): receipt outside
 * the VAT scope, the liability posting and the three ways out (imputation to the price, forfeiture on breach, refund single or
 * double).
 *
 * Receipt: Dr bank or cash / Cr deposits received. No VAT account and no revenue account is ever touched until the deposit is
 * imputed to the price, which turns it into an advance invoice (TD02) settled with the deposit.
 */
final class DepositService
{
    /** Italian virtual stamp duty on a receipt above this amount (Art. 13 Tariffa DPR 642/72). */
    public const string STAMP_DUTY_THRESHOLD = '77.47';

    public const string STAMP_DUTY_AMOUNT = '2.00';

    public const string EXEMPTION_IT = 'N1';

    public const string EXEMPTION_CH = 'OUT_OF_SCOPE';

    public function __construct(
        private readonly ChainJournal $journal,
        private readonly ChainLedger $ledger,
        private readonly DocumentNumberingService $numbering,
        private readonly InstallmentPlanService $plan,
        private readonly AdvanceEmissionService $advances,
        private readonly RecordPayment $payments,
        private readonly CustomerLedgerServiceInterface $customerLedger,
    ) {}

    /**
     * Issues the receipt of a deposit. With a payment date the money is in and the liability is posted at once; without it the
     * receipt waits for the payment (`confirmPayment`).
     *
     * @param  array{deposit_type: string, amount: string, issue_date?: ?string, payment_date?: ?string, payment_account?: ?string, notes?: ?string}  $data
     *
     * @throws DownPaymentException when the chain is closed or the amount is not valid
     */
    public function issueReceipt(DownPaymentChain $chain, ?DownPaymentInstallment $installment, array $data, string $userId): EarnestAndSecurityDeposit
    {
        return DB::transaction(function () use ($chain, $installment, $data, $userId): EarnestAndSecurityDeposit {
            $this->assertOpen($chain);
            $type = DepositType::from($data['deposit_type']);
            $amount = Decimal::round2($data['amount']);

            if (! Decimal::isPositive($amount)) {
                throw new DownPaymentException('deposit_amount_invalid');
            }

            $issueDate = $data['issue_date'] ?? $data['payment_date'] ?? CarbonImmutable::now()->toDateString();
            $italian = $chain->jurisdiction !== 'CH';
            $stamp = $italian && Decimal::compare($amount, self::STAMP_DUTY_THRESHOLD) > 0;
            $deposit = EarnestAndSecurityDeposit::query()->create([
                'tenant_id' => (string) $chain->tenant_id,
                'chain_id' => (string) $chain->id,
                'installment_id' => $installment?->id,
                'deposit_type' => $type->value,
                'receipt_number' => $this->numbering->next('deposit_receipt', CarbonImmutable::parse($issueDate)->year, (string) $chain->tenant_id)['number'],
                'issue_date' => $issueDate,
                'payment_date' => null,
                'amount' => $amount,
                'currency' => (string) $chain->currency,
                'vat_exemption_code' => $italian ? self::EXEMPTION_IT : self::EXEMPTION_CH,
                'marca_da_bollo_applied' => $stamp,
                'marca_da_bollo_amount' => $stamp ? self::STAMP_DUTY_AMOUNT : '0.00',
                'payment_account' => ($data['payment_account'] ?? 'bank') === 'cash' ? 'cash' : 'bank',
                'status' => DepositStatus::IssuedUnpaid->value,
                'notes' => $data['notes'] ?? null,
            ]);

            $linkType = $type === DepositType::EarnestMoney ? LinkType::EarnestMoneyDeposit : LinkType::SecurityDeposit;
            DownPaymentLink::query()->create([
                'tenant_id' => (string) $chain->tenant_id,
                'chain_id' => (string) $chain->id,
                'source_type' => (string) $chain->source_type,
                'source_id' => (string) $chain->source_id,
                'installment_id' => $installment?->id,
                'link_type' => $linkType->value,
                'document_id' => (string) $deposit->id,
                'document_number' => (string) $deposit->receipt_number,
                'document_date' => $issueDate,
                'status' => LinkStatus::Open->value,
                'net_amount' => $amount,
                'vat_amount' => '0.00',
                'gross_amount' => $amount,
            ]);

            if ($installment !== null) {
                $installment->forceFill(['status' => InstallmentStatus::Requested->value, 'linked_document_id' => (string) $deposit->id])->save();
            }

            if (! empty($data['payment_date'])) {
                $this->collect($chain, $deposit, (string) $data['payment_date'], $userId);
            }

            $this->ledger->refresh($chain);

            return $deposit->refresh();
        });
    }

    /**
     * The payment of a receipt came in: the deposit is held and its liability is posted.
     *
     * @throws DownPaymentException when the deposit was already collected
     */
    public function confirmPayment(DownPaymentChain $chain, EarnestAndSecurityDeposit $deposit, string $paymentDate, string $userId, ?string $paymentAccount = null): EarnestAndSecurityDeposit
    {
        return DB::transaction(function () use ($chain, $deposit, $paymentDate, $userId, $paymentAccount): EarnestAndSecurityDeposit {
            $locked = $this->lock($chain, $deposit);

            if ($locked->status !== DepositStatus::IssuedUnpaid->value) {
                throw new DownPaymentException('deposit_already_collected');
            }

            if ($paymentAccount !== null) {
                $locked->payment_account = $paymentAccount === 'cash' ? 'cash' : 'bank';
            }

            $this->collect($chain, $locked, $paymentDate, $userId);
            $this->ledger->refresh($chain);

            return $locked->refresh();
        });
    }

    /**
     * Resolves a held deposit in one of its three legal ways.
     *
     * @param  array{resolution: string, date?: ?string, refund_mode?: ?string, payment_account?: ?string, notes?: ?string}  $data
     *
     * @throws DownPaymentException when the deposit is not held or the way out does not apply to it
     */
    public function resolve(DownPaymentChain $chain, EarnestAndSecurityDeposit $deposit, array $data, string $userId): EarnestAndSecurityDeposit
    {
        return DB::transaction(function () use ($chain, $deposit, $data, $userId): EarnestAndSecurityDeposit {
            $locked = $this->lock($chain, $deposit);

            if ($locked->status !== DepositStatus::HeldActive->value) {
                throw new DownPaymentException('deposit_not_held');
            }

            $resolution = DepositResolution::from($data['resolution']);
            $date = $data['date'] ?? CarbonImmutable::now()->toDateString();

            match ($resolution) {
                DepositResolution::ImputeToPrice => $this->imputeToPrice($chain, $locked, $date, $userId),
                DepositResolution::ForfeitBreach => $this->forfeit($chain, $locked, $date, $userId),
                DepositResolution::Refund => $this->refund($chain, $locked, $date, ($data['refund_mode'] ?? 'single') === 'double', $data['payment_account'] ?? null, $userId),
            };

            $locked->forceFill(['resolution' => $resolution->value, 'resolved_at' => $date, 'notes' => $data['notes'] ?? $locked->notes])->save();
            $this->closeLink($chain, $locked, $resolution);
            $this->ledger->refresh($chain);
            DepositResolved::dispatch((string) $chain->id, (string) $locked->id, $resolution->value, (string) $chain->tenant_id);

            return $locked->refresh();
        });
    }

    /**
     * The accounting of each way out, for the preview of the resolution wizard: the lines it will post.
     *
     * @return array{resolution: string, vat_applies: bool, lines: list<array{account: string, debit: string, credit: string}>, penalty_amount: string}
     */
    public function preview(DownPaymentChain $chain, EarnestAndSecurityDeposit $deposit, DepositResolution $resolution, bool $double = false): array
    {
        $amount = (string) $deposit->amount;
        $lines = match ($resolution) {
            DepositResolution::ImputeToPrice => [],
            DepositResolution::ForfeitBreach => [
                ['account' => 'deposits_received', 'debit' => $amount, 'credit' => '0.00'],
                ['account' => 'forfeited_deposits_income', 'debit' => '0.00', 'credit' => $amount],
            ],
            DepositResolution::Refund => $double
                ? [
                    ['account' => 'deposits_received', 'debit' => $amount, 'credit' => '0.00'],
                    ['account' => 'deposit_penalties', 'debit' => $amount, 'credit' => '0.00'],
                    ['account' => $deposit->payment_account, 'debit' => '0.00', 'credit' => Decimal::round2(Decimal::mul($amount, '2'))],
                ]
                : [
                    ['account' => 'deposits_received', 'debit' => $amount, 'credit' => '0.00'],
                    ['account' => $deposit->payment_account, 'debit' => '0.00', 'credit' => $amount],
                ],
        };

        if ($resolution === DepositResolution::ImputeToPrice) {
            $residual = array_map(static fn (array $row): string => $row['net'], $this->ledger->residualByRate($chain));
            $row = $this->plan->advanceRow(CalculationMode::FixedGross, null, $amount, (string) $chain->total_contract_net, $residual);
            $lines = [
                ['account' => 'receivables', 'debit' => $row['amount_gross'], 'credit' => '0.00'],
                ['account' => 'customer_advances', 'debit' => '0.00', 'credit' => $row['amount_net']],
                ['account' => 'output_vat', 'debit' => '0.00', 'credit' => $row['amount_vat']],
                ['account' => 'deposits_received', 'debit' => $amount, 'credit' => '0.00'],
                ['account' => 'receivables', 'debit' => '0.00', 'credit' => $amount],
            ];
        }

        return [
            'resolution' => $resolution->value,
            'vat_applies' => $resolution === DepositResolution::ImputeToPrice,
            'lines' => $lines,
            'penalty_amount' => $double && $resolution === DepositResolution::Refund ? $amount : '0.00',
        ];
    }

    private function collect(DownPaymentChain $chain, EarnestAndSecurityDeposit $deposit, string $paymentDate, string $userId): void
    {
        $tenantId = (string) $chain->tenant_id;
        $currency = (string) $chain->currency;
        $amount = (string) $deposit->amount;
        $lines = [
            $this->journal->line($this->journal->cashAccountId($tenantId, $currency, (string) $deposit->payment_account), $amount, '0', $currency, 'Deposit collected: '.$deposit->receipt_number),
            $this->journal->line((string) $this->journal->accounts->customerDeposits($tenantId)->id, '0', $amount, $currency, 'Deposit received: '.$deposit->receipt_number),
        ];

        // The virtual stamp duty of the receipt is due to the Treasury and not charged to the customer
        if ($deposit->marca_da_bollo_applied) {
            $stamp = (string) $deposit->marca_da_bollo_amount;
            $lines[] = $this->journal->line((string) $this->journal->accounts->stampDutyExpense($tenantId)->id, $stamp, '0', $currency, 'Stamp duty of the receipt: '.$deposit->receipt_number);
            $lines[] = $this->journal->line((string) $this->journal->accounts->stampDutyPayable($tenantId)->id, '0', $stamp, $currency, 'Stamp duty payable: '.$deposit->receipt_number);
        }

        $entry = $this->journal->post($chain, 'down_payment_deposit', (string) $deposit->id, (string) $deposit->receipt_number, $paymentDate, 'Deposit received '.$deposit->receipt_number, $lines, $userId);

        $deposit->forceFill(['payment_date' => $paymentDate, 'status' => DepositStatus::HeldActive->value, 'journal_entry_id' => (string) $entry->id])->save();
        $this->touchLinks($chain, $deposit, LinkStatus::Issued);

        if ($deposit->installment_id !== null) {
            DownPaymentInstallment::query()->whereKey($deposit->installment_id)->update(['status' => InstallmentStatus::Paid->value]);
        }
    }

    /**
     * The deposit becomes a VAT-bearing advance invoice at the rates of the order, settled with the deposit: the liability moves
     * to the customer advances and the VAT of the advance is due from the day of the imputation.
     */
    private function imputeToPrice(DownPaymentChain $chain, EarnestAndSecurityDeposit $deposit, string $date, string $userId): void
    {
        $amount = (string) $deposit->amount;
        $open = array_map(static fn (array $row): string => $row['net'], $this->ledger->residualByRate($chain));
        $row = $this->plan->advanceRow(CalculationMode::FixedGross, null, $amount, (string) $chain->total_contract_net, $open);

        if (Decimal::compare($row['amount_gross'], $amount) !== 0) {
            throw new DownPaymentException('deposit_vat_split_not_exact', 422, ['attainable' => $row['amount_gross'], 'amount' => $amount]);
        }

        $installment = $deposit->installment_id !== null ? DownPaymentInstallment::query()->whereKey($deposit->installment_id)->first() : null;
        $advance = $this->advances->issueAdvance($chain, null, $row['vat_breakdown'], ['date' => $date, 'due_date' => $date, 'label' => (string) __('down_payments.imputed_label', ['receipt' => $deposit->receipt_number])], $userId);
        $invoice = Invoice::withoutGlobalScopes()->where('tenant_id', $chain->tenant_id)->whereKey($advance->document_id)->firstOrFail();
        $tenantId = (string) $chain->tenant_id;
        $currency = (string) $chain->currency;

        // The advance invoice is settled by the deposit, not by a bank movement
        $invoice->payments()->create([
            'tenant_id' => $invoice->tenant_id,
            'amount' => $amount,
            'paid_at' => $date,
            'payment_method' => null,
            'reference' => 'DEP-'.$deposit->id,
            'notes' => 'Deposit '.$deposit->receipt_number.' imputed to the price',
        ]);
        $this->payments->recalculate($invoice);
        $this->customerLedger->recordPaymentEntry(customerId: (string) $chain->customer_id, amount: (float) $amount, paymentReference: 'DEP-'.$deposit->id, tenantId: $tenantId, invoiceId: (string) $invoice->id);
        $entry = $this->journal->post($chain, 'down_payment_deposit_imputation', (string) $deposit->id, (string) $deposit->receipt_number, $date, 'Deposit '.$deposit->receipt_number.' imputed to the price', [
            $this->journal->line((string) $this->journal->accounts->customerDeposits($tenantId)->id, $amount, '0', $currency, 'Deposit imputed: '.$deposit->receipt_number),
            $this->journal->line((string) $this->journal->accounts->receivables($tenantId)->id, '0', $amount, $currency, 'AR settlement by deposit: '.$advance->document_number, ['partner_type' => 'customer', 'partner_id' => (string) $chain->customer_id, 'settles_entry_id' => $invoice->gl_journal_entry_id]),
        ], $userId);

        $deposit->forceFill(['status' => DepositStatus::ConvertedToAdvance->value, 'converted_invoice_id' => (string) $advance->document_id, 'resolution_journal_entry_id' => (string) $entry->id])->save();

        if ($installment !== null) {
            $installment->forceFill(['status' => InstallmentStatus::Converted->value, 'linked_invoice_id' => (string) $advance->document_id])->save();
        }
    }

    private function forfeit(DownPaymentChain $chain, EarnestAndSecurityDeposit $deposit, string $date, string $userId): void
    {
        $tenantId = (string) $chain->tenant_id;
        $currency = (string) $chain->currency;
        $amount = (string) $deposit->amount;
        $entry = $this->journal->post($chain, 'down_payment_deposit_forfeiture', (string) $deposit->id, (string) $deposit->receipt_number, $date, 'Deposit '.$deposit->receipt_number.' forfeited for breach', [
            $this->journal->line((string) $this->journal->accounts->customerDeposits($tenantId)->id, $amount, '0', $currency, 'Deposit released: '.$deposit->receipt_number),
            $this->journal->line((string) $this->journal->accounts->depositForfeitureIncome($tenantId)->id, '0', $amount, $currency, 'Compensation outside the VAT scope: '.$deposit->receipt_number),
        ], $userId);

        $deposit->forceFill(['status' => DepositStatus::ForfeitedOnBreach->value, 'resolution_journal_entry_id' => (string) $entry->id])->save();

        if ($deposit->installment_id !== null) {
            DownPaymentInstallment::query()->whereKey($deposit->installment_id)->update(['status' => InstallmentStatus::Forfeited->value]);
        }
    }

    private function refund(DownPaymentChain $chain, EarnestAndSecurityDeposit $deposit, string $date, bool $double, ?string $paymentAccount, string $userId): void
    {
        if ($double && $deposit->deposit_type !== DepositType::EarnestMoney->value) {
            throw new DownPaymentException('double_refund_only_for_earnest_money');
        }

        $tenantId = (string) $chain->tenant_id;
        $currency = (string) $chain->currency;
        $amount = (string) $deposit->amount;
        $paid = $double ? Decimal::round2(Decimal::mul($amount, '2')) : $amount;
        $cash = $this->journal->cashAccountId($tenantId, $currency, $paymentAccount ?? (string) $deposit->payment_account);
        $lines = [
            $this->journal->line((string) $this->journal->accounts->customerDeposits($tenantId)->id, $amount, '0', $currency, 'Deposit refunded: '.$deposit->receipt_number),
        ];

        if ($double) {
            // Art. 1385 par. 2 c.c.: the party that received the earnest money and withdraws returns the double
            $lines[] = $this->journal->line((string) $this->journal->accounts->depositPenaltyExpense($tenantId)->id, $amount, '0', $currency, 'Penalty of the double refund: '.$deposit->receipt_number);
        }

        $lines[] = $this->journal->line($cash, '0', $paid, $currency, 'Refund paid: '.$deposit->receipt_number);
        $entry = $this->journal->post($chain, 'down_payment_deposit_refund', (string) $deposit->id, (string) $deposit->receipt_number, $date, 'Deposit '.$deposit->receipt_number.' refunded', $lines, $userId);

        $deposit->forceFill([
            'status' => ($double ? DepositStatus::RefundedDoubleArt1385 : DepositStatus::RefundedSingle)->value,
            'penalty_amount' => $double ? $amount : '0.00',
            'resolution_journal_entry_id' => (string) $entry->id,
        ])->save();

        if ($deposit->installment_id !== null) {
            DownPaymentInstallment::query()->whereKey($deposit->installment_id)->update(['status' => InstallmentStatus::Refunded->value]);
        }
    }

    private function touchLinks(DownPaymentChain $chain, EarnestAndSecurityDeposit $deposit, LinkStatus $status): void
    {
        DownPaymentLink::query()->where('tenant_id', $chain->tenant_id)->where('document_id', $deposit->id)->update(['status' => $status->value]);
    }

    private function closeLink(DownPaymentChain $chain, EarnestAndSecurityDeposit $deposit, DepositResolution $resolution): void
    {
        $this->touchLinks($chain, $deposit, $resolution === DepositResolution::ImputeToPrice ? LinkStatus::Converted : LinkStatus::Settled);
    }

    private function lock(DownPaymentChain $chain, EarnestAndSecurityDeposit $deposit): EarnestAndSecurityDeposit
    {
        return EarnestAndSecurityDeposit::query()->where('tenant_id', $chain->tenant_id)->where('chain_id', $chain->id)->whereKey($deposit->id)->lockForUpdate()->firstOrFail();
    }

    private function assertOpen(DownPaymentChain $chain): void
    {
        if (in_array($chain->status, [ChainStatus::Settled->value, ChainStatus::Cancelled->value], true)) {
            throw new DownPaymentException('chain_closed');
        }
    }

    /** Whether the installment instrument is a deposit. */
    public static function isDepositInstrument(InstrumentType $instrument): bool
    {
        return in_array($instrument, [InstrumentType::EarnestMoneyDeposit, InstrumentType::SecurityDeposit], true);
    }
}
