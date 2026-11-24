<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Italian;

use Modules\Payroll\Calculation\Dec;

/**
 * Italian payroll parameters, with the 2026 defaults of the desktop application: IRPEF brackets (Legge di Bilancio
 * 2026), employee work-income deduction (art. 13 TUIR), tax-wedge measures (L. 207/2024) and INPS thresholds (circolare
 * INPS n. 6/2026). A row of payroll_annual_parameters overrides them for a year.
 */
final readonly class ItalianPayrollParameters
{
    public function __construct(
        public int $year = 2026,
        public string $inpsEmployeeRate = '0.0919',
        public string $inpsAdditionalRate = '0.01',
        public string $inpsFirstBracket = '56224',
        public string $indicativeEmployerInpsRate = '0.29',
        public string $tfrDivisor = '13.5',
        public string $tfrSolidarityRate = '0.005',
        public string $irpefFirstLimit = '28000',
        public string $irpefSecondLimit = '50000',
        public string $irpefFirstRate = '0.23',
        public string $irpefSecondRate = '0.33',
        public string $irpefThirdRate = '0.43',
        public string $workDeductionFirstLimit = '15000',
        public string $workDeductionMaximum = '1955',
        public string $workDeductionBase = '1910',
        public string $workDeductionSpread = '1190',
        public string $workDeductionSpreadRange = '13000',
        public string $workDeductionTaperRange = '22000',
        public string $workDeductionIncrease = '65',
        public string $workDeductionIncreaseFrom = '25000',
        public string $workDeductionIncreaseTo = '35000',
        public string $wedgeDeductionFrom = '20000',
        public string $wedgeDeductionFullTo = '32000',
        public string $wedgeDeductionEnd = '40000',
        public string $wedgeDeductionAmount = '1000',
        public string $wedgeDeductionTaperRange = '8000',
        public string $wedgeBonusFirstLimit = '8500',
        public string $wedgeBonusSecondLimit = '15000',
        public string $wedgeBonusThirdLimit = '20000',
        public string $wedgeBonusFirstRate = '0.071',
        public string $wedgeBonusSecondRate = '0.053',
        public string $wedgeBonusThirdRate = '0.048',
    ) {}

    /**
     * @param  array<string, mixed>  $values  snake_case keys, as stored in payroll_annual_parameters
     */
    public static function fromArray(int $year, array $values): self
    {
        $arguments = ['year' => $year];

        foreach ($values as $key => $value) {
            $property = lcfirst(str_replace('_', '', ucwords($key, '_')));

            if (property_exists(self::class, $property) && $property !== 'year') {
                $arguments[$property] = (string) $value;
            }
        }

        return new self(...$arguments);
    }

    /**
     * The values to store for a year.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $values = [];

        foreach (get_object_vars($this) as $property => $value) {
            if ($property !== 'year') {
                $values[strtolower((string) preg_replace('/[A-Z]/', '_$0', $property))] = $value;
            }
        }

        return $values;
    }

    /**
     * The gross annual IRPEF on the taxable income (23% up to 28,000; 33% up to 50,000; 43% above).
     */
    public function grossIrpef(string $taxableIncome): string
    {
        if (! Dec::isPositive($taxableIncome)) {
            return '0';
        }

        $tax = Dec::mul(Dec::min($taxableIncome, $this->irpefFirstLimit), $this->irpefFirstRate);

        if (Dec::cmp($taxableIncome, $this->irpefFirstLimit) > 0) {
            $tax = Dec::add($tax, Dec::mul(Dec::sub(Dec::min($taxableIncome, $this->irpefSecondLimit), $this->irpefFirstLimit), $this->irpefSecondRate));
        }

        if (Dec::cmp($taxableIncome, $this->irpefSecondLimit) > 0) {
            $tax = Dec::add($tax, Dec::mul(Dec::sub($taxableIncome, $this->irpefSecondLimit), $this->irpefThirdRate));
        }

        return $tax;
    }

    /**
     * The annual employee work-income deduction (art. 13 TUIR) for a full year; the 690 / 1,380 minimums apply only to
     * periods shorter than a year and are not modelled.
     */
    public function workDeduction(string $income): string
    {
        $deduction = match (true) {
            Dec::cmp($income, $this->workDeductionFirstLimit) <= 0 => $this->workDeductionMaximum,
            Dec::cmp($income, $this->irpefFirstLimit) <= 0 => Dec::add(
                $this->workDeductionBase,
                Dec::div(Dec::mul($this->workDeductionSpread, Dec::sub($this->irpefFirstLimit, $income)), $this->workDeductionSpreadRange),
            ),
            Dec::cmp($income, $this->irpefSecondLimit) <= 0 => Dec::div(
                Dec::mul($this->workDeductionBase, Dec::sub($this->irpefSecondLimit, $income)),
                $this->workDeductionTaperRange,
            ),
            default => '0',
        };

        $hasIncrease = Dec::cmp($income, $this->workDeductionIncreaseFrom) > 0 && Dec::cmp($income, $this->workDeductionIncreaseTo) <= 0;

        return Dec::add($deduction, $hasIncrease ? $this->workDeductionIncrease : '0');
    }

    /**
     * The tax-wedge additional deduction: 1,000 between 20,000 and 32,000, decreasing to zero at 40,000.
     */
    public function taxWedgeDeduction(string $income): string
    {
        return match (true) {
            Dec::cmp($income, $this->wedgeDeductionFrom) <= 0 => '0',
            Dec::cmp($income, $this->wedgeDeductionFullTo) <= 0 => $this->wedgeDeductionAmount,
            Dec::cmp($income, $this->wedgeDeductionEnd) <= 0 => Dec::div(Dec::mul($this->wedgeDeductionAmount, Dec::sub($this->wedgeDeductionEnd, $income)), $this->wedgeDeductionTaperRange),
            default => '0',
        };
    }

    /**
     * The tax-wedge non-taxable bonus for employment income up to 20,000.
     */
    public function taxWedgeBonus(string $employmentIncome): string
    {
        return match (true) {
            ! Dec::isPositive($employmentIncome) => '0',
            Dec::cmp($employmentIncome, $this->wedgeBonusFirstLimit) <= 0 => Dec::mul($employmentIncome, $this->wedgeBonusFirstRate),
            Dec::cmp($employmentIncome, $this->wedgeBonusSecondLimit) <= 0 => Dec::mul($employmentIncome, $this->wedgeBonusSecondRate),
            Dec::cmp($employmentIncome, $this->wedgeBonusThirdLimit) <= 0 => Dec::mul($employmentIncome, $this->wedgeBonusThirdRate),
            default => '0',
        };
    }
}
