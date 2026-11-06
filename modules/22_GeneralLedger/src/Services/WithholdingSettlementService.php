<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use App\Models\PassiveInvoice;
use App\Models\SupplierPayment;
use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Exceptions\WithholdingSettlementException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\WithholdingSettlement;
use Modules\GeneralLedger\Models\WithholdingSettlementLine;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\SDI\Enums\TipoRitenuta;

/**
 * Monthly settlement of the withholding on supplier invoices. Withholding is due when the supplier is paid, so a month
 * holds the payments of that month and each carries its share of the invoice: partial payments add up to the exact
 * amount of the document. A paid settlement never changes.
 */
final class WithholdingSettlementService
{
    public const string SOURCE_PAYMENT = 'withholding_settlement_payment';

    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService,
        private readonly FiscalPeriodResolver $periods,
        private readonly LedgerAccountResolver $accounts,
    ) {}

    public function calculate(string $tenantId, int $year, int $month, ?string $actorUserId = null): WithholdingSettlement
    {
        return DB::transaction(function () use ($tenantId, $year, $month, $actorUserId): WithholdingSettlement {
            $settlement = WithholdingSettlement::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->where('year', $year)
                ->where('month', $month)
                ->lockForUpdate()
                ->first();

            if ($settlement?->isPaid()) {
                throw WithholdingSettlementException::conflict("The withholding settlement {$settlement->periodLabel()} is already paid and cannot be calculated again.");
            }

            $lines = $this->linesOf($tenantId, $year, $month);
            $total = $lines->reduce(static fn (string $sum, array $line): string => Decimal::add($sum, $line['withheld_amount']), '0');
            $totalF24 = $lines->whereNotNull('f24_code')->reduce(static fn (string $sum, array $line): string => Decimal::add($sum, $line['withheld_amount']), '0');

            $attributes = [
                'tenant_id' => $tenantId,
                'year' => $year,
                'month' => $month,
                'currency' => TenantAccountingCurrency::of($tenantId),
                'total_withheld' => Decimal::round2($total),
                'total_f24' => Decimal::round2($totalF24),
                'due_date' => $this->dueDate($year, $month)->toDateString(),
                'status' => WithholdingSettlement::STATUS_CALCULATED,
                'calculated_by' => $this->uuidOrNull($actorUserId),
            ];

            if ($settlement === null) {
                $settlement = WithholdingSettlement::create($attributes);
            } else {
                $settlement->update($attributes);
                $settlement->lines()->delete();
            }

            foreach ($lines as $line) {
                WithholdingSettlementLine::create([...$line, 'tenant_id' => $tenantId, 'withholding_settlement_id' => $settlement->id]);
            }

            return $settlement->load('lines');
        });
    }

    /**
     * @throws WithholdingSettlementException when it is already paid, or the bank account is not of the tenant
     */
    public function markPaid(string $tenantId, string $settlementId, string $paymentDate, ?string $bankAccountId, ?string $actorUserId = null): WithholdingSettlement
    {
        return DB::transaction(function () use ($tenantId, $settlementId, $paymentDate, $bankAccountId, $actorUserId): WithholdingSettlement {
            $settlement = WithholdingSettlement::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->whereKey($settlementId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($settlement->isPaid()) {
                throw WithholdingSettlementException::conflict('This withholding settlement is already paid.');
            }

            if (! Decimal::isPositive((string) $settlement->total_f24)) {
                throw WithholdingSettlementException::invalid('This withholding settlement has no amount to pay with the F24.');
            }

            $journal = $bankAccountId !== null ? $this->registerPayment($settlement, $paymentDate, $bankAccountId, $actorUserId) : null;

            $settlement->update([
                'status' => WithholdingSettlement::STATUS_PAID,
                'payment_date' => $paymentDate,
                'payment_journal_id' => $journal?->id,
                'paid_by' => $this->uuidOrNull($actorUserId),
            ]);

            return $settlement->load('lines');
        });
    }

    /**
     * The lines of the F24 for the settlement, one per tax code. Contributions (Enasarco, INPS and the others) stay out:
     * they are not paid with this section of the form.
     *
     * @return array{section: string, reference_month: string, reference_year: int, due_date: string, total: string, lines: list<array{tax_code: string, reference_month: string, reference_year: int, amount: string}>}
     */
    public function f24(WithholdingSettlement $settlement): array
    {
        $amounts = [];

        foreach ($settlement->lines as $line) {
            if ($line->f24_code !== null) {
                $amounts[$line->f24_code] = Decimal::add($amounts[$line->f24_code] ?? '0', (string) $line->withheld_amount);
            }
        }

        $month = sprintf('%02d', $settlement->month);
        $lines = [];

        foreach ($amounts as $code => $amount) {
            $lines[] = ['tax_code' => (string) $code, 'reference_month' => $month, 'reference_year' => (int) $settlement->year, 'amount' => Decimal::round2($amount)];
        }

        return [
            'section' => 'erario',
            'reference_month' => $month,
            'reference_year' => (int) $settlement->year,
            'due_date' => $settlement->due_date->toDateString(),
            'total' => (string) $settlement->total_f24,
            'lines' => $lines,
        ];
    }

    /**
     * What the year paid to each supplier, per causale, ready to be keyed into the CU. A year is exported from what was
     * calculated: a month not calculated yet is missing, and `amount_paid_to_tax_authority` says what the F24s covered.
     *
     * @return array{content: string, file_name: string}
     */
    public function certificationExport(string $tenantId, int $year): array
    {
        $settlements = WithholdingSettlement::withoutGlobalScope(TenantScope::class)
            ->with(['lines' => fn ($query) => $query->withoutGlobalScope(TenantScope::class)->with(['supplier' => fn ($supplier) => $supplier->withoutGlobalScopes()])])
            ->where('tenant_id', $tenantId)
            ->where('year', $year)
            ->get();

        $rows = [];

        foreach ($settlements as $settlement) {
            foreach ($settlement->lines as $line) {
                $key = $line->supplier_id.'|'.$line->reason;
                $supplier = $line->supplier;
                $row = $rows[$key] ?? [
                    'name' => $supplier?->company_name ?: trim($supplier?->first_name.' '.$supplier?->last_name),
                    'vat_number' => (string) $supplier?->vat_number,
                    'fiscal_code' => (string) $supplier?->fiscal_code,
                    'reason' => (string) $line->reason,
                    'gross' => '0', 'taxable' => '0', 'withheld' => '0', 'contributions' => '0', 'paid' => '0',
                ];

                $isTax = TipoRitenuta::tryFrom((string) $line->withholding_type)?->isTax() === true;

                if ($isTax) {
                    $row['gross'] = Decimal::add($row['gross'], (string) $line->gross_paid);
                    $row['taxable'] = Decimal::add($row['taxable'], (string) $line->taxable_amount);
                    $row['withheld'] = Decimal::add($row['withheld'], (string) $line->withheld_amount);
                    $row['paid'] = $settlement->isPaid() ? Decimal::add($row['paid'], (string) $line->withheld_amount) : $row['paid'];
                } else {
                    $row['contributions'] = Decimal::add($row['contributions'], (string) $line->withheld_amount);
                }

                $rows[$key] = $row;
            }
        }

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['supplier', 'vat_number', 'fiscal_code', 'reason', 'gross_paid', 'taxable_amount', 'withheld_amount', 'contributions_withheld', 'amount_paid_to_tax_authority'], ';', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, [
                $this->csvText($row['name']), $this->csvText($row['vat_number']), $this->csvText($row['fiscal_code']), $row['reason'],
                Decimal::round2($row['gross']), Decimal::round2($row['taxable']), Decimal::round2($row['withheld']),
                Decimal::round2($row['contributions']), Decimal::round2($row['paid']),
            ], ';', '"', '');
        }

        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);

        return ['content' => $content, 'file_name' => "cu-ritenute-{$year}.csv"];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function linesOf(string $tenantId, int $year, int $month): Collection
    {
        $from = CarbonImmutable::create($year, $month, 1);

        $payments = SupplierPayment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('type', 'payment')
            ->whereDate('paid_at', '>=', $from->toDateString())
            ->whereDate('paid_at', '<=', $from->endOfMonth()->toDateString())
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();

        $invoices = PassiveInvoice::withoutGlobalScopes()
            ->whereIn('id', $payments->pluck('passive_invoice_id')->unique())
            ->where('tenant_id', $tenantId)
            ->where('document_type', '!=', 'TD04')
            ->where('withholding_tax_amount', '>', 0)
            ->get()
            ->keyBy('id');

        $lines = collect();

        foreach ($payments as $payment) {
            $invoice = $invoices->get($payment->passive_invoice_id);

            if ($invoice === null) {
                continue;
            }

            foreach ($this->sharesOf($invoice, $payment) as $share) {
                $lines->push($share);
            }
        }

        return $lines;
    }

    /**
     * The part of each withholding of the invoice this payment carries. Each share is the difference between two
     * cumulative amounts, so the rounding of a partial payment cannot lose or add a cent over the whole invoice.
     *
     * @return list<array<string, mixed>>
     */
    private function sharesOf(PassiveInvoice $invoice, SupplierPayment $payment): array
    {
        $due = $invoice->amountDue();
        $before = (string) SupplierPayment::withoutGlobalScopes()
            ->where('passive_invoice_id', $invoice->id)
            ->where('type', 'payment')
            ->where(fn ($query) => $query->where('paid_at', '<', $payment->paid_at)->orWhere(fn ($sameDay) => $sameDay->where('paid_at', $payment->paid_at)->where('id', '<', $payment->id)))
            ->sum('amount');
        $after = Decimal::add($before, (string) $payment->amount);
        $shares = [];

        foreach ($this->withholdingsByType($invoice) as $type => $withholding) {
            $withheld = Decimal::sub($this->shareOf($withholding['amount'], $after, $due), $this->shareOf($withholding['amount'], $before, $due));
            $grossPaid = Decimal::sub($this->shareOf((string) ($invoice->total_net ?? $invoice->total_amount), $after, $due), $this->shareOf((string) ($invoice->total_net ?? $invoice->total_amount), $before, $due));
            $rate = $withholding['rate'];

            $shares[] = [
                'passive_invoice_id' => (string) $invoice->id,
                'supplier_payment_id' => (string) $payment->id,
                'supplier_id' => (string) $invoice->supplier_id,
                'invoice_number' => (string) $invoice->invoice_number,
                'paid_at' => CarbonImmutable::parse($payment->paid_at)->toDateString(),
                'withholding_type' => $type,
                'reason' => $withholding['reason'],
                'f24_code' => $this->f24Code($type, $withholding['reason']),
                'gross_paid' => Decimal::round2($grossPaid),
                'taxable_amount' => $rate !== null && Decimal::isPositive($rate) ? Decimal::round2(Decimal::div(Decimal::mul($withheld, '100'), $rate)) : Decimal::round2($grossPaid),
                'rate_percent' => $rate,
                'withheld_amount' => Decimal::round2($withheld),
            ];
        }

        return $shares;
    }

    /**
     * @return array<string, array{amount: string, rate: ?string, reason: ?string}>
     */
    private function withholdingsByType(PassiveInvoice $invoice): array
    {
        $byType = [];

        foreach ($invoice->withholdings ?? [] as $withholding) {
            $type = (string) $withholding['type'];
            $byType[$type] = [
                'amount' => Decimal::add($byType[$type]['amount'] ?? '0', (string) $withholding['amount']),
                'rate' => $withholding['rate'] ?? null,
                'reason' => $withholding['reason'] ?? null,
            ];
        }

        return $byType;
    }

    private function shareOf(string $amount, string $paid, string $due): string
    {
        return Decimal::round2(Decimal::div(Decimal::mul($amount, $paid), $due));
    }

    private function f24Code(string $type, ?string $reason): ?string
    {
        if (TipoRitenuta::tryFrom($type)?->isTax() !== true) {
            return null;
        }

        return config('fiscal_it.withholding.f24_codes_by_reason')[(string) $reason] ?? (string) config('fiscal_it.withholding.f24_code');
    }

    /**
     * The due day of the month after the payment, moved to Monday when it falls on a weekend. National holidays and the
     * August suspension are not considered.
     */
    private function dueDate(int $year, int $month): CarbonImmutable
    {
        $due = CarbonImmutable::create($year, $month, 1)->addMonthNoOverflow()->setDay((int) config('fiscal_it.withholding.due_day'));

        return $due->isWeekend() ? $due->next(CarbonImmutable::MONDAY) : $due;
    }

    private function registerPayment(WithholdingSettlement $settlement, string $paymentDate, string $bankAccountId, ?string $actorUserId): JournalEntry
    {
        $tenantId = (string) $settlement->tenant_id;
        $bankAccount = ChartOfAccount::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->whereKey($bankAccountId)->first();

        if ($bankAccount === null) {
            throw WithholdingSettlementException::invalid('The bank account does not belong to this tenant.');
        }

        $currency = (string) $settlement->currency;
        $amount = (string) $settlement->total_f24;
        $line = static fn (string $accountId, string $debit, string $credit): array => [
            'account_id' => $accountId,
            'debit_amount' => $debit,
            'credit_amount' => $credit,
            'currency' => $currency,
            'exchange_rate' => 1,
            'description' => "Withholding paid with F24 for {$settlement->periodLabel()}",
        ];

        try {
            return $this->postingService->createAndPost([
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'fiscal_period_id' => $this->periods->forDate($tenantId, $paymentDate)->id,
                'entry_number' => 'WHT-PAY-'.$settlement->periodLabel(),
                'posting_date' => $paymentDate,
                'document_date' => $paymentDate,
                'journal_type' => 'standard',
                'source_document_type' => self::SOURCE_PAYMENT,
                'source_document_id' => (string) $settlement->id,
                'description' => "Withholding payment for {$settlement->periodLabel()}",
                'lines' => [
                    $line((string) $this->accounts->withholdingPayable($tenantId)->id, $amount, '0'),
                    $line((string) $bankAccount->id, '0', $amount),
                ],
            ], $actorUserId);
        } catch (InvalidArgumentException $exception) {
            throw WithholdingSettlementException::invalid($exception->getMessage());
        }
    }

    /**
     * Keeps a value from running as a formula when the file is opened in a spreadsheet.
     */
    private function csvText(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    private function uuidOrNull(?string $userId): ?string
    {
        return $userId !== null && Str::isUuid($userId) ? $userId : null;
    }
}
