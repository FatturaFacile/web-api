<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Calculation;

use Carbon\CarbonImmutable;

/**
 * Cash principle (principio di cassa): the tax accrues in the month of the actual payment. With the extended cash rule
 * (cassa allargata) a payment made by 12 January for a service rendered the year before belongs to that year.
 * The F24 is due on the 16th of the following month, moved to the next working day when it falls on a weekend or an Italian
 * public holiday.
 */
final class CashPeriodResolver
{
    public function taxYear(CarbonImmutable $paymentDate, CarbonImmutable $serviceDate): int
    {
        $year = $paymentDate->year;

        if ($paymentDate->month === 1 && $paymentDate->day <= 12 && $serviceDate->year === $year - 1) {
            return $year - 1;
        }

        return $year;
    }

    /**
     * Month the withholding is reported in (YYYY-MM): always the month of the payment.
     */
    public function accrualMonth(CarbonImmutable $paymentDate): string
    {
        return $paymentDate->format('Y-m');
    }

    public function f24DueDate(CarbonImmutable $paymentDate): CarbonImmutable
    {
        $due = $paymentDate->startOfMonth()->addMonthNoOverflow()->setDay((int) config('withholding_recipients.f24.due_day', 16));

        while ($due->isWeekend() || $this->isHoliday($due)) {
            $due = $due->addDay();
        }

        return $due;
    }

    private function isHoliday(CarbonImmutable $date): bool
    {
        $fixed = ['01-01', '01-06', '04-25', '05-01', '06-02', '08-15', '11-01', '12-08', '12-25', '12-26'];

        if (in_array($date->format('m-d'), $fixed, true)) {
            return true;
        }

        return $date->equalTo($this->easter($date->year)->addDay());
    }

    /**
     * Easter Sunday (Gauss algorithm, no calendar extension needed).
     */
    private function easter(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }
}
