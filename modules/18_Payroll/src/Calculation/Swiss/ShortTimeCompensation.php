<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Swiss;

use Modules\Payroll\Calculation\Dec;

/**
 * The compensation of one employee in one settlement period, with no database behind it. The waiting time is borne by the
 * employer and comes off the lost hours first; what remains is compensated at the legal percentage of the earnings lost,
 * with the monthly salary capped at the maximum insured salary. All figures are arguments: none is known here.
 */
final class ShortTimeCompensation
{
    /**
     * @return array{lost_hours: string, waiting_hours: string, compensable_hours: string, insured_monthly_salary: string, lost_earnings: string, compensation: string, wage_reduction: string}
     */
    public static function calculate(
        string $targetHours,
        string $workedHours,
        string $absenceHours,
        string $overtimeHours,
        string $monthlySalary,
        string $weeklyHours,
        string $waitingDays,
        string $compensationPercent,
        string $maximumInsuredMonthlySalary,
    ): array {
        $lost = Dec::max('0', Dec::sub(Dec::sub(Dec::sub($targetHours, $absenceHours), $workedHours), $overtimeHours));
        $waiting = Dec::min($lost, Dec::div(Dec::mul($waitingDays, $weeklyHours), '5'));
        $compensable = Dec::sub($lost, $waiting);
        $insured = Dec::min($monthlySalary, $maximumInsuredMonthlySalary);

        if (! Dec::isPositive($targetHours)) {
            return ['lost_hours' => '0', 'waiting_hours' => '0', 'compensable_hours' => '0', 'insured_monthly_salary' => Dec::round($insured), 'lost_earnings' => '0.00', 'compensation' => '0.00', 'wage_reduction' => '0.00'];
        }

        $lostEarnings = Dec::div(Dec::mul($compensable, $insured), $targetHours);

        return [
            'lost_hours' => Dec::round($lost),
            'waiting_hours' => Dec::round($waiting),
            'compensable_hours' => Dec::round($compensable),
            'insured_monthly_salary' => Dec::round($insured),
            'lost_earnings' => Dec::round($lostEarnings),
            'compensation' => Dec::round(Dec::div(Dec::mul($lostEarnings, $compensationPercent), '100')),
            'wage_reduction' => Dec::round(Dec::div(Dec::mul($compensable, $monthlySalary), $targetHours)),
        ];
    }

    /**
     * The hours one working day stands for: a fifth of the weekly hours (SECO 716.400, question 22).
     */
    public static function fullWorkingDayHours(string $weeklyHours): string
    {
        return Dec::div($weeklyHours, '5');
    }
}
