<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Contracts\VatSettlementServiceInterface;
use Modules\GeneralLedger\Exceptions\VatSettlementException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Models\VatTaxSetting;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\GeneralLedger\Services\Tax\VatAccountResolver;
use Modules\GeneralLedger\Services\Tax\VatLedgerReader;
use Modules\GeneralLedger\Services\Tax\VatLedgerSnapshot;
use Modules\GeneralLedger\Services\Tax\VatPeriod;
use Modules\GeneralLedger\Services\Tax\VatRateTotal;
use Modules\GeneralLedger\Services\Tax\VatReturnCalculator;
use Modules\GeneralLedger\Services\Tax\VatReturnResult;

/**
 * Lifecycle of the periodic VAT settlement: calculated from the registered entries, posted once to the ledger, paid.
 * A posted settlement never changes: a later correction belongs to the period it is registered in.
 */
final class VatSettlementService implements VatSettlementServiceInterface
{
    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService,
        private readonly VatLedgerReader $ledgerReader,
        private readonly VatAccountResolver $accountResolver,
        private readonly VatRegisterService $registerService,
    ) {}

    public function calculateForFiscalPeriod(string $tenantId, string $fiscalPeriodId, ?string $actorUserId = null): VatSettlement
    {
        $fiscalPeriod = FiscalPeriod::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereKey($fiscalPeriodId)
            ->firstOrFail();

        return $this->calculate(
            $tenantId,
            VatPeriod::fromDates(CarbonImmutable::parse($fiscalPeriod->start_date), CarbonImmutable::parse($fiscalPeriod->end_date)),
            $actorUserId
        );
    }

    public function calculate(string $tenantId, VatPeriod $period, ?string $actorUserId = null): VatSettlement
    {
        return DB::transaction(function () use ($tenantId, $period, $actorUserId): VatSettlement {
            $this->lockTenantSettlements($tenantId);

            $settlement = VatSettlement::withTrashed()
                ->withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->where('period_type', $period->type)
                ->where('year', $period->year)
                ->where('period', $period->number)
                ->lockForUpdate()
                ->first();

            if ($settlement?->isFinal()) {
                throw VatSettlementException::conflict("The VAT settlement {$period->label()} is already {$settlement->status} and cannot be recalculated.");
            }

            $this->assertNoFinalSettlementCovers($tenantId, $period);

            $fiscalPeriod = $this->fiscalPeriodOf($tenantId, $period->to);
            ['result' => $result, 'snapshot' => $snapshot, 'previous' => $previous] = $this->compute($tenantId, $period);
            $payable = $result->payable;

            $attributes = [
                'tenant_id' => $tenantId,
                'company_id' => $fiscalPeriod->company_id,
                'fiscal_period_id' => $fiscalPeriod->id,
                'period_type' => $period->type,
                'year' => $period->year,
                'period' => $period->number,
                'settlement_period' => $period->label(),
                'period_from' => $result->periodFrom,
                'period_to' => $result->periodTo,
                'country' => $result->country,
                'currency' => TenantAccountingCurrency::of($tenantId),
                'regime' => $result->regime,
                'turnover' => $result->turnover,
                'taxable_turnover' => $result->taxableTurnover,
                'gross_turnover' => $result->grossTurnover,
                'exempt_turnover' => $result->exemptTurnover,
                'acquisition_tax_base' => $result->acquisitionTaxBase,
                'acquisition_tax_amount' => $result->acquisitionTaxAmount,
                'gross_input_vat_forfeited' => $result->grossInputVatForfeited,
                'flat_rate_adjustment_amount' => $result->flatRateAdjustmentAmount,
                'passive_operations' => $snapshot->passiveOperations(),
                'output_vat_amount' => $result->outputVat,
                'input_vat_amount' => $result->inputVat,
                'total_debit_vat' => $result->outputVat,
                'total_credit_vat' => $result->inputVat,
                'prior_credit_used' => $result->previousCredit,
                'previous_debit' => $result->previousDebit,
                'previous_settlement_id' => $previous?->id,
                'interest_amount' => $result->interest,
                'payable_amount' => $payable,
                'net_vat_payable' => Decimal::isPositive($payable) ? $payable : '0.00',
                'net_vat_credit' => Decimal::isNegative($payable) ? Decimal::round2(Decimal::neg($payable)) : '0.00',
                'below_minimum_payment' => $result->belowMinimumPayment,
                'rate_breakdown' => array_map(
                    static fn (VatRateTotal $rate): array => ['rate' => $rate->rate, 'taxable' => $rate->taxable, 'tax' => $rate->tax],
                    $result->rates
                ),
                'estv_boxes' => $result->estvBoxes,
                'warnings' => $snapshot->warnings,
                'details' => $result->details,
                'status' => VatSettlement::STATUS_CALCULATED,
                'calculated_by' => $this->uuidOrNull($actorUserId),
            ];

            if ($settlement === null) {
                return VatSettlement::create($attributes);
            }

            $settlement->fill($attributes);
            $settlement->deleted_at = null;
            $settlement->save();

            return $settlement;
        });
    }

    public function post(string $tenantId, string $vatSettlementId, ?string $actorUserId = null): ?JournalEntry
    {
        return DB::transaction(function () use ($tenantId, $vatSettlementId, $actorUserId): ?JournalEntry {
            $this->lockTenantSettlements($tenantId);

            $settlement = $this->settlementOf($tenantId, $vatSettlementId);

            if ($settlement->isFinal()) {
                throw VatSettlementException::conflict('VAT settlement is already posted.');
            }

            $period = VatPeriod::of((string) $settlement->period_type, (int) $settlement->year, (int) $settlement->period);
            $this->assertNoFinalSettlementCovers($tenantId, $period);
            $this->assertNoLaterFinalSettlement($tenantId, $period);

            ['result' => $result, 'snapshot' => $snapshot] = $this->compute($tenantId, $period);
            $this->assertStillCurrent($settlement, $result);

            $journal = $this->registerInLedger($settlement, $result, $snapshot, $actorUserId);
            $this->registerService->assignProtocols($tenantId, $settlement->company_id, (string) $settlement->id, $snapshot);

            $settlement->update([
                'status' => VatSettlement::STATUS_POSTED,
                'settlement_journal_id' => $journal?->id,
                'posted_at' => now(),
                'posted_by' => $this->uuidOrNull($actorUserId),
            ]);

            return $journal;
        });
    }

    public function markPaid(string $tenantId, string $vatSettlementId, string $paymentDate, ?string $bankAccountId = null, ?string $actorUserId = null): VatSettlement
    {
        return DB::transaction(function () use ($tenantId, $vatSettlementId, $paymentDate, $bankAccountId, $actorUserId): VatSettlement {
            $this->lockTenantSettlements($tenantId);

            $settlement = $this->settlementOf($tenantId, $vatSettlementId);

            if ($settlement->status !== VatSettlement::STATUS_POSTED) {
                throw VatSettlementException::conflict('Only a posted VAT settlement can be paid.');
            }

            if (! Decimal::isPositive((string) $settlement->payable_amount) || $settlement->below_minimum_payment) {
                throw VatSettlementException::invalid('This VAT settlement has no amount to pay.');
            }

            $journal = $bankAccountId !== null
                ? $this->registerPayment($settlement, $paymentDate, $bankAccountId, $actorUserId)
                : null;

            $settlement->update([
                'status' => VatSettlement::STATUS_PAID,
                'is_paid' => true,
                'payment_date' => $paymentDate,
                'paid_at' => now(),
                'paid_by' => $this->uuidOrNull($actorUserId),
                'payment_journal_id' => $journal?->id,
            ]);

            return $settlement;
        });
    }

    /**
     * @return array{result: VatReturnResult, snapshot: VatLedgerSnapshot, previous: ?VatSettlement}
     */
    private function compute(string $tenantId, VatPeriod $period): array
    {
        $country = $this->countryOf($tenantId, $period);
        $accounts = $this->accountResolver->accountsFor($tenantId, $country);
        $snapshot = $this->ledgerReader->read($tenantId, $country, $period->from, $period->to, $accounts);

        $previous = VatSettlement::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [VatSettlement::STATUS_POSTED, VatSettlement::STATUS_PAID])
            ->whereDate('period_to', '<', $period->from->toDateString())
            ->orderByDesc('period_to')
            ->first();

        $previousPayable = (string) ($previous?->payable_amount ?? '0');
        $previousCredit = Decimal::isNegative($previousPayable) ? Decimal::neg($previousPayable) : '0';
        $previousDebit = $previous?->below_minimum_payment ? $previousPayable : '0';

        $settings = $this->accountResolver->settingsOf($tenantId);
        $regime = $country === 'CH' ? (string) ($settings->regime ?? 'effective') : 'effective';

        return [
            'result' => VatReturnCalculator::compute(
                $country,
                $period->from,
                $period->to,
                $snapshot->sales,
                $snapshot->purchases,
                $previousCredit,
                $previousDebit,
                $regime,
                $settings->sss_rate_1 !== null ? (string) $settings->sss_rate_1 : null,
                $settings->sss_rate_2 !== null ? (string) $settings->sss_rate_2 : null,
                is_array($settings->flat_tax_rates) ? $settings->flat_tax_rates : [],
                (bool) ($settings->carry_over_below_minimum ?? true),
            ),
            'snapshot' => $snapshot,
            'previous' => $previous,
        ];
    }

    private function countryOf(string $tenantId, VatPeriod $period): string
    {
        $country = strtoupper((string) Tenant::query()->whereKey($tenantId)->value('country'));

        if (! in_array($country, ['CH', 'IT'], true)) {
            throw VatSettlementException::invalid("VAT returns are available for Swiss and Italian tenants only, not [{$country}].");
        }

        if ($country === 'IT' && $period->type === VatPeriod::SEMIANNUAL) {
            throw VatSettlementException::invalid('The Italian liquidation is monthly or quarterly.');
        }

        return $country;
    }

    /**
     * Clears the VAT accounts against the tax authority account. The credit or debt carried over is not moved: it is
     * already on the tax authority account from the previous settlement, so the line shown here is the balance of the
     * period plus the interest, and `payable_amount` is what that account holds afterwards.
     */
    private function registerInLedger(VatSettlement $settlement, VatReturnResult $result, VatLedgerSnapshot $snapshot, ?string $actorUserId): ?JournalEntry
    {
        $tenantId = (string) $settlement->tenant_id;
        $currency = TenantAccountingCurrency::of($tenantId);
        $lines = [];
        $isFlatRateSwiss = $result->country === 'CH' && in_array($result->regime, ['net_tax_rate', 'flat_tax_rate'], true);

        foreach ($snapshot->outputByAccount as $accountId => $amount) {
            $lines[] = $this->clearingLine($accountId, Decimal::neg($amount), $currency, 'Clear sales VAT for settlement');
        }

        foreach ($snapshot->inputByAccount as $accountId => $amount) {
            $lines[] = $this->clearingLine($accountId, $amount, $currency, 'Clear purchase VAT for settlement');
        }

        if ($isFlatRateSwiss) {
            if (! Decimal::isZero($result->flatRateAdjustmentAmount)) {
                $lines[] = $this->clearingLine(
                    $this->accountResolver->flatRateAdjustmentAccountId($tenantId),
                    $result->flatRateAdjustmentAmount,
                    $currency,
                    'SSS/PST flat-rate VAT revenue adjustment'
                );
            }

            if (Decimal::isPositive($result->grossInputVatForfeited)) {
                $lines[] = $this->clearingLine(
                    $this->accountResolver->forfeitedInputVatAccountId($tenantId),
                    Decimal::neg($result->grossInputVatForfeited),
                    $currency,
                    'Non-deductible input VAT under SSS/PST'
                );
            }
        }

        if (Decimal::isPositive($result->acquisitionTaxAmount)) {
            $lines[] = $this->clearingLine(
                $this->accountResolver->forfeitedInputVatAccountId($tenantId),
                Decimal::neg($result->acquisitionTaxAmount),
                $currency,
                'Bezugsteuer / Acquisition Tax'
            );
        }

        if (Decimal::isPositive($result->interest)) {
            $lines[] = $this->clearingLine($this->accountResolver->interestExpenseAccountId($tenantId), Decimal::neg($result->interest), $currency, 'Quarterly VAT interest');
        }

        $authorityAmount = Decimal::add(Decimal::sub($result->outputVat, $result->inputVat), $result->interest);
        $lines[] = $this->clearingLine($this->accountResolver->settlementAccountId($tenantId), $authorityAmount, $currency, 'Net VAT towards the tax authority');
        $lines = array_values(array_filter($lines, static fn (array $line): bool => ! Decimal::isZero($line['debit_amount']) || ! Decimal::isZero($line['credit_amount'])));

        if ($lines === []) {
            return null;
        }

        try {
            return $this->postingService->createAndPost([
                'tenant_id' => $tenantId,
                'company_id' => $settlement->company_id,
                'fiscal_period_id' => $settlement->fiscal_period_id,
                'entry_number' => 'VAT-'.$settlement->settlement_period,
                'posting_date' => $result->periodTo,
                'document_date' => $result->periodTo,
                'journal_type' => 'adjusting',
                'source_document_type' => VatLedgerReader::SOURCE_SETTLEMENT,
                'source_document_id' => (string) $settlement->id,
                'description' => "Periodic VAT Settlement for {$settlement->settlement_period}",
                'lines' => $lines,
            ], $actorUserId);
        } catch (InvalidArgumentException $exception) {
            throw VatSettlementException::invalid($exception->getMessage());
        }
    }

    private function registerPayment(VatSettlement $settlement, string $paymentDate, string $bankAccountId, ?string $actorUserId): JournalEntry
    {
        $tenantId = (string) $settlement->tenant_id;
        $bankAccount = ChartOfAccount::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->whereKey($bankAccountId)->first();

        if ($bankAccount === null) {
            throw VatSettlementException::invalid('The bank account does not belong to this tenant.');
        }

        $fiscalPeriod = $this->fiscalPeriodOf($tenantId, CarbonImmutable::parse($paymentDate));
        $currency = TenantAccountingCurrency::of($tenantId);
        $amount = (string) $settlement->payable_amount;

        try {
            return $this->postingService->createAndPost([
                'tenant_id' => $tenantId,
                'company_id' => $settlement->company_id,
                'fiscal_period_id' => $fiscalPeriod->id,
                'entry_number' => 'VAT-PAY-'.$settlement->settlement_period,
                'posting_date' => $paymentDate,
                'document_date' => $paymentDate,
                'journal_type' => 'standard',
                'source_document_type' => VatLedgerReader::SOURCE_SETTLEMENT_PAYMENT,
                'source_document_id' => (string) $settlement->id,
                'description' => "VAT payment for {$settlement->settlement_period}",
                'lines' => [
                    $this->clearingLine($this->accountResolver->settlementAccountId($tenantId), Decimal::neg($amount), $currency, 'VAT paid to the tax authority'),
                    $this->clearingLine((string) $bankAccount->id, $amount, $currency, 'VAT paid to the tax authority'),
                ],
            ], $actorUserId);
        } catch (InvalidArgumentException $exception) {
            throw VatSettlementException::invalid($exception->getMessage());
        }
    }

    /**
     * Builds a journal line: a positive amount goes to the credit side, a negative one to the debit side.
     *
     * @return array{account_id: string, debit_amount: string, credit_amount: string, currency: string, exchange_rate: int, description: string}
     */
    private function clearingLine(string $accountId, string $amount, string $currency, string $description): array
    {
        $rounded = Decimal::round2($amount);

        return [
            'account_id' => $accountId,
            'debit_amount' => Decimal::isNegative($rounded) ? Decimal::round2(Decimal::neg($rounded)) : '0.00',
            'credit_amount' => Decimal::isPositive($rounded) ? $rounded : '0.00',
            'currency' => $currency,
            'exchange_rate' => 1,
            'description' => $description,
        ];
    }

    /**
     * The figures on a settlement must still be what the ledger says at the moment they are registered.
     */
    private function assertStillCurrent(VatSettlement $settlement, VatReturnResult $result): void
    {
        $stored = [
            (string) $settlement->output_vat_amount, (string) $settlement->input_vat_amount, (string) $settlement->prior_credit_used,
            (string) $settlement->previous_debit, (string) $settlement->interest_amount, (string) $settlement->payable_amount,
        ];
        $current = [$result->outputVat, $result->inputVat, $result->previousCredit, $result->previousDebit, $result->interest, $result->payable];

        foreach ($stored as $index => $value) {
            if (Decimal::compare($value, $current[$index]) !== 0) {
                throw VatSettlementException::conflict('The ledger changed after this VAT settlement was calculated: calculate it again before posting.');
            }
        }
    }

    private function assertNoFinalSettlementCovers(string $tenantId, VatPeriod $period): void
    {
        $covered = VatSettlement::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [VatSettlement::STATUS_POSTED, VatSettlement::STATUS_PAID])
            ->whereDate('period_from', '<=', $period->to->toDateString())
            ->whereDate('period_to', '>=', $period->from->toDateString())
            ->exists();

        if ($covered) {
            throw VatSettlementException::conflict('A posted VAT settlement already covers this period.');
        }
    }

    private function assertNoLaterFinalSettlement(string $tenantId, VatPeriod $period): void
    {
        $later = VatSettlement::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [VatSettlement::STATUS_POSTED, VatSettlement::STATUS_PAID])
            ->whereDate('period_from', '>', $period->to->toDateString())
            ->exists();

        if ($later) {
            throw VatSettlementException::conflict('A later VAT settlement is already posted: the credit it carried over would no longer hold.');
        }
    }

    private function fiscalPeriodOf(string $tenantId, CarbonImmutable $day): FiscalPeriod
    {
        $fiscalPeriod = FiscalPeriod::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereDate('start_date', '<=', $day->toDateString())
            ->whereDate('end_date', '>=', $day->toDateString())
            ->orderBy('period_number')
            ->first();

        if ($fiscalPeriod === null) {
            throw VatSettlementException::invalid("No fiscal period includes {$day->toDateString()}.");
        }

        return $fiscalPeriod;
    }

    private function settlementOf(string $tenantId, string $vatSettlementId): VatSettlement
    {
        return VatSettlement::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereKey($vatSettlementId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Serializes the settlement operations of a tenant: each one reads the state of the others. The lock is taken on the
     * settings row, which no other table references, so it does not hold back unrelated writes of the tenant.
     */
    private function lockTenantSettlements(string $tenantId): void
    {
        $table = (new VatTaxSetting)->getTable();

        DB::table($table)->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table($table)->where('tenant_id', $tenantId)->lockForUpdate()->first();
    }

    private function uuidOrNull(?string $userId): ?string
    {
        return $userId !== null && Str::isUuid($userId) ? $userId : null;
    }
}
