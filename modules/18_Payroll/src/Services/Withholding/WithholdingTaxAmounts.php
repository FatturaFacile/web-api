<?php

declare(strict_types=1);

namespace Modules\Payroll\Services\Withholding;

use Illuminate\Support\Collection;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\Swiss\SwissWageTypeCodes;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\PayslipLine;

/**
 * What a set of payslips adds up to for the tax authorities. The deductions are negative on the lines.
 */
final class WithholdingTaxAmounts
{
    /** @var list<string> AVS/AI/IPG, AD, AINP and LPP: the mandatory contributions the employer reports (Ticino, September 2024). */
    private const array MandatoryContributionCodes = [SwissWageTypeCodes::Ahv, SwissWageTypeCodes::Alv, SwissWageTypeCodes::Nbu, SwissWageTypeCodes::Bvg];

    /**
     * @param  Collection<int, Payslip>  $payslips  With their lines.
     * @return array{gross: string, basis: string, tax: string, social_contributions: string}
     */
    public static function of(Collection $payslips): array
    {
        $lines = $payslips->flatMap(static fn (Payslip $payslip): Collection => $payslip->lines);

        return [
            'gross' => Dec::round(Dec::sum($payslips->map(static fn (Payslip $payslip): string => (string) $payslip->gross_salary))),
            'basis' => Dec::round(Dec::sum($payslips->map(static fn (Payslip $payslip): string => (string) $payslip->withholding_tax_basis))),
            'tax' => self::absoluteTotal($lines->where('wage_type_code', SwissWageTypeCodes::WithholdingTax)),
            'social_contributions' => self::absoluteTotal($lines->whereIn('wage_type_code', self::MandatoryContributionCodes)),
        ];
    }

    /**
     * @param  Collection<int, PayslipLine>  $lines
     */
    private static function absoluteTotal(Collection $lines): string
    {
        $total = Dec::sum($lines->map(static fn (PayslipLine $line): string => (string) $line->amount));

        return Dec::round(Dec::isNegative($total) ? Dec::neg($total) : $total);
    }
}
