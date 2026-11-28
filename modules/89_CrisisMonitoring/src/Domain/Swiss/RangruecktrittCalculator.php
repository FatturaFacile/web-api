<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Domain\Swiss;

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The subordination (Rangrücktritt) arithmetic of Art. 725b CO. A subordinated claim leaves the liabilities of the over-indebtedness
 * test; what counts is the principal plus the interest that stays subordinated (accrued up to the date and contractual interest until
 * the end of the horizon, or of the deferral if earlier). Interest is simple, actual days over 365.
 *
 * The required amount is the claim that must still be subordinated to cure the shortfall; the required principal gives it for a new
 * agreement at an assumed rate over the horizon: principal x (1 + rate x months / 12) = required claim.
 */
final readonly class RangruecktrittCalculator
{
    /**
     * The claim of one agreement at the horizon.
     *
     * @param  array{principal: string, interest_rate: string, interest_treatment: string, scope: string, effective_from: CarbonImmutable, deferral_end_date: CarbonImmutable|null}  $loan
     * @return array{accrued_interest: string, future_interest: string, claim: string}
     */
    public function claim(array $loan, CarbonImmutable $asOf, int $horizonMonths): array
    {
        $deferred = $loan['interest_treatment'] === 'deferred';
        $withInterest = $loan['scope'] === 'principal_and_interest' && $deferred;
        $horizonEnd = $asOf->addMonths($horizonMonths);
        $end = $loan['deferral_end_date'] !== null && $loan['deferral_end_date']->lessThan($horizonEnd) ? $loan['deferral_end_date'] : $horizonEnd;
        $accrued = '0';
        $future = '0';

        if ($withInterest) {
            $accrued = $this->interest($loan['principal'], $loan['interest_rate'], $loan['effective_from'], $asOf);
            $future = $this->interest($loan['principal'], $loan['interest_rate'], $asOf, $end);
        }

        return [
            'accrued_interest' => Decimal::round2($accrued),
            'future_interest' => Decimal::round2($future),
            'claim' => Decimal::round2(Decimal::add($loan['principal'], Decimal::add($accrued, $future))),
        ];
    }

    /**
     * @param  list<array{principal: string, interest_rate: string, interest_treatment: string, scope: string, status: string, effective_from: CarbonImmutable, deferral_end_date: CarbonImmutable|null}>  $loans
     * @return array{claims: string, loans: list<array<string, string>>}
     */
    public function covered(array $loans, CarbonImmutable $asOf, int $horizonMonths): array
    {
        $total = '0';
        $rows = [];

        foreach ($loans as $index => $loan) {
            $claim = $this->claim($loan, $asOf, $horizonMonths);
            $effective = $loan['status'] === 'active'
                && $loan['effective_from']->lessThanOrEqualTo($asOf)
                && ($loan['deferral_end_date'] === null || $loan['deferral_end_date']->greaterThanOrEqualTo($asOf));

            if ($effective) {
                $total = Decimal::add($total, $claim['claim']);
            }

            $rows[$index] = $claim;
        }

        return ['claims' => Decimal::round2($total), 'loans' => $rows];
    }

    /**
     * @param  string  $shortfall  the amount to cure (assets short of liabilities at the chosen basis)
     * @param  string  $covered  the claims already subordinated and effective
     * @param  string  $annualRatePercent  the rate of the agreement to sign
     * @return array{remaining_shortfall: string, required_claim_amount: string, required_principal_amount: string, assumed_rate: string}
     */
    public function required(string $shortfall, string $covered, int $horizonMonths, string $annualRatePercent): array
    {
        $remaining = Decimal::sub($shortfall, $covered);
        $remaining = Decimal::isPositive($remaining) ? $remaining : '0';
        $factor = Decimal::add('1', Decimal::mul(Decimal::div($annualRatePercent, '100'), Decimal::div((string) $horizonMonths, '12')));
        $principal = Decimal::isPositive($remaining) ? Decimal::div($remaining, $factor) : '0';

        // Round the principal up to the cent: rounding down would leave the claim a cent short of the cure.
        $cents = Decimal::mul($principal, '100');
        $whole = explode('.', $cents)[0];
        $principal = Decimal::div(Decimal::compare($cents, $whole) > 0 ? Decimal::add($whole, '1') : $whole, '100');

        return [
            'remaining_shortfall' => Decimal::round2($remaining),
            'required_claim_amount' => Decimal::round2($remaining),
            'required_principal_amount' => Decimal::round2($principal),
            'assumed_rate' => Decimal::round2($annualRatePercent),
        ];
    }

    private function interest(string $principal, string $ratePercent, CarbonImmutable $from, CarbonImmutable $to): string
    {
        if ($to->lessThanOrEqualTo($from) || Decimal::isZero($ratePercent)) {
            return '0';
        }

        $days = (string) $from->diffInDays($to, true);

        return Decimal::mul($principal, Decimal::mul(Decimal::div($ratePercent, '100'), Decimal::div($days, '365')));
    }
}
