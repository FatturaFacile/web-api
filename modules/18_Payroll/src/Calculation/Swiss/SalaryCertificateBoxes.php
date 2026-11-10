<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Swiss;

use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\PayslipLineData;
use Modules\Payroll\Calculation\WageTypeKind;
use Modules\Payroll\Models\PayrollEmployeeProfile;

/**
 * The boxes of the Swiss salary certificate (Form 11 / Lohnausweis), summed from the lines of the closed payslips of a year.
 */
final class SalaryCertificateBoxes
{
    /**
     * @param  list<PayslipLineData>  $lines
     * @return array<string, string|bool|null> the amounts and flags by column of payroll_salary_certificates
     */
    public static function fill(array $lines, ?Employee $employee = null, ?PayrollEmployeeProfile $profile = null): array
    {
        $box1 = self::box($lines, ['1']);
        $box2_1 = self::box($lines, ['2.1']);
        $box2_2 = self::box($lines, ['2.2']);
        $box2 = self::box($lines, ['2.1', '2.2', '2.3']);
        $box3 = self::box($lines, ['3']);
        $box4 = self::box($lines, ['4']);
        $box7 = self::box($lines, ['5', '6', '7']);
        $box8 = Dec::sum([$box1, $box2, $box3, $box4, $box7]);
        $box9 = self::deducted($lines, [SwissWageTypeCodes::Ahv, SwissWageTypeCodes::Alv, SwissWageTypeCodes::Nbu]);
        $box10_1 = self::deductedByCodeOrBox($lines, [SwissWageTypeCodes::Bvg], ['10.1']);
        $box10_2 = self::deductedByCodeOrBox($lines, [SwissWageTypeCodes::BvgBuyIn, '9045'], ['10.2']);
        $box10 = Dec::add($box10_1, $box10_2);

        $box13_1 = self::expenseBox($lines, '13.1', includeUnclassified: true);
        $box13_2 = self::expenseBox($lines, '13.2', includeUnclassified: false);
        $box13 = Dec::add($box13_1, $box13_2);

        $checkboxF = Dec::isPositive($box2_2) || self::hasExpenseBox($lines, '13.2.2');
        $checkboxG = Dec::isPositive($box2_1);
        $remarks = self::buildRemarks($employee, $profile);

        return [
            'checkbox_f_transport' => $checkboxF,
            'checkbox_g_canteen' => $checkboxG,
            'box1_salary' => $box1,
            'box2_benefits' => $box2,
            'box3_irregular' => $box3,
            'box4_capital' => $box4,
            'box7_other' => $box7,
            'box8_gross' => $box8,
            'box9_social_contributions' => $box9,
            'box10_1_ordinary_pension' => $box10_1,
            'box10_2_pension_buyin' => $box10_2,
            'box10_pension' => $box10,
            'box11_net' => Dec::sub(Dec::sub($box8, $box9), $box10),
            'box12_withholding_tax' => self::deducted($lines, [SwissWageTypeCodes::WithholdingTax]),
            'box13_1_effective_expenses' => $box13_1,
            'box13_2_lump_sum_expenses' => $box13_2,
            'box13_expenses' => $box13,
            'box15_remarks' => $remarks,
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

    /**
     * @param  list<PayslipLineData>  $lines
     * @param  list<string>  $codes
     * @param  list<string>  $boxes
     */
    private static function deductedByCodeOrBox(array $lines, array $codes, array $boxes): string
    {
        $matching = array_filter(
            $lines,
            static fn (PayslipLineData $line): bool => $line->kind === WageTypeKind::Deduction
                && (in_array($line->wageTypeCode, $codes, true) || in_array((string) $line->certificateBox, $boxes, true)),
        );

        return Dec::neg(Dec::sum(array_map(static fn (PayslipLineData $line): string => $line->amount, $matching)));
    }

    /**
     * @param  list<PayslipLineData>  $lines
     */
    private static function expenseBox(array $lines, string $prefix, bool $includeUnclassified): string
    {
        $matching = array_filter($lines, static function (PayslipLineData $line) use ($prefix, $includeUnclassified): bool {
            if ($line->kind !== WageTypeKind::Expense) {
                return false;
            }

            $box = (string) ($line->certificateBox ?? '');

            if (str_starts_with($box, $prefix)) {
                return true;
            }

            return $includeUnclassified && ($box === '' || $box === '13');
        });

        return Dec::sum(array_map(static fn (PayslipLineData $line): string => $line->amount, $matching));
    }

    /**
     * @param  list<PayslipLineData>  $lines
     */
    private static function hasExpenseBox(array $lines, string $box): bool
    {
        foreach ($lines as $line) {
            if ($line->kind === WageTypeKind::Expense && $line->certificateBox === $box && Dec::isPositive($line->amount)) {
                return true;
            }
        }

        return false;
    }

    private static function buildRemarks(?Employee $employee, ?PayrollEmployeeProfile $profile): ?string
    {
        $parts = [];

        if ($employee !== null && Dec::isPositive((string) $employee->fte_ratio) && Dec::cmp((string) $employee->fte_ratio, '1') < 0) {
            $percent = Dec::trimmed(Dec::mul((string) $employee->fte_ratio, '100'), 2);
            $parts[] = "Grado di occupazione: {$percent}%";
        }

        $permit = $profile !== null ? ($profile->swiss_permit_type ?? $profile->residence_permit) : null;

        if ($permit !== null && $permit !== '') {
            $parts[] = "Permesso: {$permit}";
        }

        if ($profile?->frontier_regime !== null && $profile->frontier_regime !== '') {
            $parts[] = "Regime frontaliere: {$profile->frontier_regime}";
        }

        return $parts === [] ? null : implode('; ', $parts);
    }
}
