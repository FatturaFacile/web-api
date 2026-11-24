<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\VatAccountResolver;
use Modules\ItalianVat\Exceptions\ItalianVatException;
use Modules\ItalianVat\Models\AnnualVatReconciliation;
use Modules\ItalianVat\Models\VatProRataRate;
use Modules\ItalianVat\Models\VatRegisterEntry;

/**
 * Annual VAT reconciliation (dichiarazione IVA annuale, prospetto di riconciliazione): puts together the periodic settlements
 * of the year, the adjustment of the pro-rata (definitive percentage against the provisional one used during the year), the
 * credit of the previous year and the payments, and checks the result against the Erario c/IVA account of the ledger.
 */
final class AnnualVatReconciliationService
{
    private const string TOLERANCE = '0.01';

    public function __construct(private readonly VatAccountResolver $accountResolver) {}

    /**
     * Calculates (or recalculates) the reconciliation of a year. A finalized year is not recalculated.
     */
    public function calculate(string $tenantId, int $year): AnnualVatReconciliation
    {
        $existing = AnnualVatReconciliation::query()->where('tenant_id', $tenantId)->where('year', $year)->first();

        if ($existing?->status === 'finalized') {
            throw ItalianVatException::locked('annual_already_finalized', ['year' => $year]);
        }

        $settlements = VatSettlement::query()
            ->where('tenant_id', $tenantId)
            ->where('country', 'IT')
            ->where('year', $year)
            ->orderBy('period_from')
            ->get();

        $final = $settlements->filter(fn (VatSettlement $settlement): bool => $settlement->isFinal());
        $diagnostics = [];

        $notFinal = $settlements->count() - $final->count();

        if ($notFinal > 0) {
            $diagnostics[] = ['code' => 'settlements_not_posted', 'params' => ['count' => $notFinal]];
        }

        $missing = $this->missingPeriods($final);

        if ($missing !== []) {
            $diagnostics[] = ['code' => 'periods_missing', 'params' => ['periods' => implode(',', $missing)]];
        }

        $periods = [];
        $output = '0';
        $input = '0';
        $interest = '0';
        $payable = '0';
        $paid = '0';

        foreach ($final as $settlement) {
            $isPaid = (bool) $settlement->is_paid && Decimal::isPositive((string) $settlement->payable_amount);
            $output = Decimal::add($output, (string) $settlement->output_vat_amount);
            $input = Decimal::add($input, (string) $settlement->input_vat_amount);
            $interest = Decimal::add($interest, (string) $settlement->interest_amount);
            $payable = Decimal::add($payable, (string) $settlement->payable_amount);

            if ($isPaid) {
                $paid = Decimal::add($paid, Decimal::sub((string) $settlement->payable_amount, (string) $settlement->interest_amount));
            }

            $periods[] = [
                'settlement_id' => (string) $settlement->id,
                'period_from' => $settlement->period_from->toDateString(),
                'period_to' => $settlement->period_to->toDateString(),
                'output_vat' => Decimal::round2((string) $settlement->output_vat_amount),
                'input_vat' => Decimal::round2((string) $settlement->input_vat_amount),
                'interest' => Decimal::round2((string) $settlement->interest_amount),
                'payable' => Decimal::round2((string) $settlement->payable_amount),
                'is_paid' => $isPaid,
            ];
        }

        $proRata = $this->proRataAdjustment($tenantId, $year, $diagnostics);
        $priorCredit = Decimal::round2((string) ($final->first()->prior_credit_used ?? '0'));
        $annualVat = Decimal::sub(Decimal::sub($output, $input), $proRata['adjustment']);
        $result = Decimal::sub(Decimal::sub($annualVat, $priorCredit), $paid);

        $interestPaid = $this->interestPaid($final);
        $expectedOpen = Decimal::sub($payable, Decimal::add($paid, $interestPaid));
        $ledger = $this->ledgerBalance($tenantId, $year);
        $difference = Decimal::round2(Decimal::sub($expectedOpen, $ledger));
        $reconciled = Decimal::compare(Decimal::abs($difference), self::TOLERANCE) < 0 && $diagnostics === [];

        if (Decimal::compare(Decimal::abs($difference), self::TOLERANCE) >= 0) {
            $diagnostics[] = ['code' => 'ledger_difference', 'params' => ['difference' => $difference]];
        }

        $attributes = [
            'status' => 'draft',
            'periods' => $periods,
            'total_output_vat' => Decimal::round2($output),
            'total_input_vat' => Decimal::round2($input),
            'pro_rata_percentage' => $proRata['percentage'],
            'pro_rata_adjustment' => Decimal::round2($proRata['adjustment']),
            'prior_year_credit' => $priorCredit,
            'interest_total' => Decimal::round2($interest),
            'paid_total' => Decimal::round2(Decimal::add($paid, $interestPaid)),
            'annual_debit' => Decimal::isPositive($result) ? Decimal::round2($result) : '0.00',
            'annual_credit' => Decimal::isNegative($result) ? Decimal::round2(Decimal::neg($result)) : '0.00',
            'ledger_balance' => Decimal::round2($ledger),
            'difference' => $difference,
            'is_reconciled' => $reconciled,
            'diagnostics' => $diagnostics,
        ];

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing;
        }

        return AnnualVatReconciliation::create($attributes + ['tenant_id' => $tenantId, 'year' => $year]);
    }

    /**
     * Closes the year: only a reconciled position is finalized, so the amounts of the annual return are the ones booked.
     */
    public function finalize(string $tenantId, ?string $userId, int $year): AnnualVatReconciliation
    {
        $reconciliation = $this->calculate($tenantId, $year);

        if (! $reconciliation->is_reconciled) {
            throw ItalianVatException::invalid('annual_not_reconciled', ['year' => $year, 'difference' => (string) $reconciliation->difference]);
        }

        $reconciliation->update(['status' => 'finalized', 'finalized_at' => now(), 'finalized_by' => $userId]);

        return $reconciliation;
    }

    public function find(string $tenantId, int $year): AnnualVatReconciliation
    {
        return AnnualVatReconciliation::query()->where('tenant_id', $tenantId)->where('year', $year)->first()
            ?? throw ItalianVatException::notFound('annual_not_found', ['year' => $year]);
    }

    /**
     * The adjustment of the VAT deducted during the year, for the purchases registered with pro-rata: the difference between
     * what the definitive percentage allows and what was deducted with the provisional one. Positive = more VAT to deduct.
     *
     * @param  list<array{code: string, params: array<string, scalar>}>  $diagnostics
     * @return array{percentage: int, adjustment: string}
     */
    private function proRataAdjustment(string $tenantId, int $year, array &$diagnostics): array
    {
        $rate = VatProRataRate::query()->where('tenant_id', $tenantId)->where('year', $year)->first();
        $entries = VatRegisterEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('year', $year)
            ->where('register_type', VatSectionService::PURCHASES)
            ->where('non_deductible_reason', 'pro_rata')
            ->get(['vat_amount', 'deductible_vat']);

        if ($rate === null || ! $rate->is_definitive || $rate->definitive_percentage === null) {
            if ($entries->isNotEmpty()) {
                $diagnostics[] = ['code' => 'pro_rata_not_definitive', 'params' => ['year' => $year]];
            }

            return ['percentage' => $rate->provisional_percentage ?? 100, 'adjustment' => '0'];
        }

        $adjustment = '0';

        foreach ($entries as $entry) {
            $allowed = Decimal::round2(Decimal::div(Decimal::mul((string) $entry->vat_amount, (string) $rate->definitive_percentage), '100'));
            $adjustment = Decimal::add($adjustment, Decimal::sub($allowed, (string) $entry->deductible_vat));
        }

        return ['percentage' => $rate->definitive_percentage, 'adjustment' => $adjustment];
    }

    /**
     * @param  Collection<int, VatSettlement>  $settlements
     */
    private function interestPaid(Collection $settlements): string
    {
        $interest = '0';

        foreach ($settlements as $settlement) {
            if ($settlement->is_paid && Decimal::isPositive((string) $settlement->payable_amount)) {
                $interest = Decimal::add($interest, (string) $settlement->interest_amount);
            }
        }

        return $interest;
    }

    /**
     * What the Erario c/IVA account holds at the end of the year from the entries of the year: credit (to pay) minus debit
     * (payments and credit positions).
     */
    private function ledgerBalance(string $tenantId, int $year): string
    {
        $configured = $this->accountResolver->settingsOf($tenantId)->settlement_account_id;
        $accountId = $configured !== null ? (string) $configured : ChartOfAccount::query()->where('tenant_id', $tenantId)->where('code', '2210')->value('id');

        if ($accountId === null) {
            return '0';
        }

        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->where('je.tenant_id', $tenantId)
            ->where('jl.account_id', $accountId)
            ->whereNull('je.deleted_at')
            ->whereNull('jl.deleted_at')
            ->whereIn('je.status', ['posted', 'reversed'])
            ->whereDate('je.posting_date', '>=', sprintf('%d-01-01', $year))
            ->whereDate('je.posting_date', '<=', sprintf('%d-12-31', $year))
            ->selectRaw('COALESCE(SUM(jl.credit_amount), 0) as credit, COALESCE(SUM(jl.debit_amount), 0) as debit')
            ->first();

        return Decimal::sub((string) ($row->credit ?? '0'), (string) ($row->debit ?? '0'));
    }

    /**
     * The periods of the year without a final settlement: months when the settlements are monthly, else quarters.
     *
     * @param  Collection<int, VatSettlement>  $settlements
     * @return list<string>
     */
    private function missingPeriods(Collection $settlements): array
    {
        if ($settlements->isEmpty()) {
            return ['1-12'];
        }

        $monthly = $settlements->contains(fn (VatSettlement $settlement): bool => $settlement->period_from->format('Y-m') === $settlement->period_to->format('Y-m'));
        $covered = [];

        foreach ($settlements as $settlement) {
            $covered[(int) $settlement->period_from->format('n')] = true;

            if (! $monthly) {
                $covered[(int) $settlement->period_from->format('n') + 1] = true;
                $covered[(int) $settlement->period_from->format('n') + 2] = true;
            }
        }

        $missing = [];

        for ($month = 1; $month <= 12; $month++) {
            if (! isset($covered[$month])) {
                $missing[] = (string) $month;
            }
        }

        return $missing;
    }
}
