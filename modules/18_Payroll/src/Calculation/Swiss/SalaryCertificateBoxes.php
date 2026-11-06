<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Swiss;

use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\PayslipLineData;
use Modules\Payroll\Calculation\WageTypeKind;

/**
 * The boxes of the salary certificate, summed from the lines of the closed payslips of a year.
 */
final class SalaryCertificateBoxes
{
    /**
     * @param  list<PayslipLineData>  $lines
     * @return array<string, string> the amounts by column of payroll_salary_certificates
     */
    public static function fill(array $lines): array
    {
        $box1 = self::box($lines, ['1']);
        $box2 = self::box($lines, ['2.1', '2.2', '2.3']);
        $box3 = self::box($lines, ['3']);
        $box4 = self::box($lines, ['4']);
        $box7 = self::box($lines, ['5', '6', '7']);
        $box8 = Dec::sum([$box1, $box2, $box3, $box4, $box7]);
        $box9 = self::deducted($lines, [SwissWageTypeCodes::Ahv, SwissWageTypeCodes::Alv, SwissWageTypeCodes::Nbu]);
        $box10 = self::deducted($lines, [SwissWageTypeCodes::Bvg]);
        $expenses = array_filter($lines, static fn (PayslipLineData $line): bool => $line->kind === WageTypeKind::Expense);

        return [
            'box1_salary' => $box1,
            'box2_benefits' => $box2,
            'box3_irregular' => $box3,
            'box4_capital' => $box4,
            'box7_other' => $box7,
            'box8_gross' => $box8,
            'box9_social_contributions' => $box9,
            'box10_pension' => $box10,
            'box11_net' => Dec::sub(Dec::sub($box8, $box9), $box10),
            'box12_withholding_tax' => self::deducted($lines, [SwissWageTypeCodes::WithholdingTax]),
            'box13_expenses' => Dec::sum(array_map(static fn (PayslipLineData $line): string => $line->amount, $expenses)),
        ];
    }

    /**
     * @param  list<PayslipLineData>  $lines
     * @param  list<string>  $boxes
     */
    private static function box(array $lines, array $boxes): string
    {
        $matching = array_filter($lines, static fn (PayslipLineData $line): bool => WageTypeKind::isGross($line->kind) && in_array($line->certificateBox, $boxes, true));

        return Dec::sum(array_map(static fn (PayslipLineData $line): string => $line->amount, $matching));
    }

    /**
     * @param  list<PayslipLineData>  $lines
     * @param  list<string>  $codes
     */
    private static function deducted(array $lines, array $codes): string
    {
        $matching = array_filter($lines, static fn (PayslipLineData $line): bool => $line->kind === WageTypeKind::Deduction && in_array($line->wageTypeCode, $codes, true));

        return Dec::neg(Dec::sum(array_map(static fn (PayslipLineData $line): string => $line->amount, $matching)));
    }
}
