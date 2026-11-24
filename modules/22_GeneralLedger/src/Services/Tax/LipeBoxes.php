<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

/**
 * The boxes VP2 to VP14 of the Italian periodic VAT communication (LIPE, IVP18), all decimal strings.
 *
 * VP2 counts the active operations including those under split payment (their VAT is not collected, so it is not in
 * VP4); VP5 is the input VAT reduced by the pro-rata deductibility percentage.
 */
final readonly class LipeBoxes
{
    public const string MINIMUM_PAYMENT = VatReturnCalculator::ITALIAN_MINIMUM_PAYMENT;

    public function __construct(
        public string $vp2ActiveOperations,
        public string $vp3PassiveOperations,
        public string $vp4OutputVat,
        public string $vp5DeductibleVat,
        public string $vp6Due,
        public string $vp6Credit,
        public string $vp7PreviousDebit,
        public string $vp8PreviousPeriodCredit,
        public string $vp9PreviousYearCredit,
        public string $vp10EuAutoPayments,
        public string $vp11TaxCredits,
        public string $vp12Interest,
        public string $vp13Advance,
        public string $vp14Payable,
        public string $vp14Credit,
    ) {}

    /**
     * @param  string  $proRataPercent  deductible share of the input VAT, 100 when the tenant has no pro-rata
     * @param  string  $previousCredit  credit of the previous period: VP9 in the first period of a year, VP8 otherwise
     */
    public static function compute(
        string $activeOperations,
        string $passiveOperations,
        string $outputVat,
        string $inputVat,
        string $previousDebit,
        string $previousCredit,
        bool $firstPeriodOfYear,
        bool $quarterly,
        string $proRataPercent = '100',
        string $advance = '0',
        string $euAutoPayments = '0',
        string $taxCredits = '0',
    ): self {
        $deductible = Decimal::round2(Decimal::div(Decimal::mul($inputVat, $proRataPercent), '100'));
        $difference = Decimal::sub($outputVat, $deductible);
        $due = Decimal::isPositive($difference) ? $difference : '0';
        $credit = Decimal::isNegative($difference) ? Decimal::neg($difference) : '0';

        $vp8 = $firstPeriodOfYear ? '0' : $previousCredit;
        $vp9 = $firstPeriodOfYear ? $previousCredit : '0';

        $balance = Decimal::sub(Decimal::add($difference, $previousDebit), Decimal::add(Decimal::add($vp8, $vp9), Decimal::add($euAutoPayments, $taxCredits)));
        $balance = Decimal::sub($balance, $advance);

        $interest = $quarterly && Decimal::isPositive($balance) ? Decimal::round2(Decimal::mul($balance, '0.01')) : '0';
        $payable = Decimal::add($balance, $interest);

        return new self(
            Decimal::round2($activeOperations),
            Decimal::round2($passiveOperations),
            Decimal::round2($outputVat),
            $deductible,
            Decimal::round2($due),
            Decimal::round2($credit),
            Decimal::round2($previousDebit),
            Decimal::round2($vp8),
            Decimal::round2($vp9),
            Decimal::round2($euAutoPayments),
            Decimal::round2($taxCredits),
            Decimal::round2($interest),
            Decimal::round2($advance),
            Decimal::isPositive($payable) ? Decimal::round2($payable) : '0.00',
            Decimal::isNegative($payable) ? Decimal::round2(Decimal::neg($payable)) : '0.00',
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'VP2' => $this->vp2ActiveOperations,
            'VP3' => $this->vp3PassiveOperations,
            'VP4' => $this->vp4OutputVat,
            'VP5' => $this->vp5DeductibleVat,
            'VP6_due' => $this->vp6Due,
            'VP6_credit' => $this->vp6Credit,
            'VP7' => $this->vp7PreviousDebit,
            'VP8' => $this->vp8PreviousPeriodCredit,
            'VP9' => $this->vp9PreviousYearCredit,
            'VP10' => $this->vp10EuAutoPayments,
            'VP11' => $this->vp11TaxCredits,
            'VP12' => $this->vp12Interest,
            'VP13' => $this->vp13Advance,
            'VP14_payable' => $this->vp14Payable,
            'VP14_credit' => $this->vp14Credit,
        ];
    }
}
