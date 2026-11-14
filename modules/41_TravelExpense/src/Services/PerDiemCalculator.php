<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Services;

use Carbon\CarbonImmutable;
use Modules\TravelExpense\Models\TravelPerDiemRate;
use Modules\TravelExpense\Models\TravelTrip;
use Modules\TravelExpense\Support\Money;

/**
 * The per-diem arithmetic, free of the database: the hours away on each day of a trip and the allowance of a day from a
 * rate row.
 *
 * A day counts in full from the full-day threshold of the rate, in half from the half-day threshold and not at all
 * below it. The deductions for the meals and the lodging the employer provided reduce the allowance and, with the same
 * proportion, the tax-free threshold (Italy: art. 51, comma 5, TUIR, a third for one of lodging or meal, two thirds for
 * both). A rate without a tax-free threshold is entirely tax free (Swiss flat expenses of an approved Spesenreglement).
 */
final class PerDiemCalculator
{
    private const int MinutesPerDay = 1440;

    /**
     * The hours the employee is away on every calendar day of the trip, keyed by Y-m-d. Without a departure time the
     * first day counts from midnight, without a return time the last day to midnight.
     *
     * @return array<string, string>
     */
    public function hoursPerDay(TravelTrip $trip): array
    {
        $start = CarbonImmutable::parse($trip->start_date->toDateString());
        $end = CarbonImmutable::parse($trip->end_date->toDateString());
        $departure = $this->minutes($trip->departure_time) ?? 0;
        $return = $this->minutes($trip->return_time) ?? self::MinutesPerDay;
        $days = [];

        if ($start->equalTo($end)) {
            $days[$start->toDateString()] = $this->hours(max(0, $return - $departure));

            return $days;
        }

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $minutes = match (true) {
                $day->equalTo($start) => self::MinutesPerDay - $departure,
                $day->equalTo($end) => $return,
                default => self::MinutesPerDay,
            };
            $days[$day->toDateString()] = $this->hours($minutes);
        }

        return $days;
    }

    /**
     * @param  array{breakfast_provided?: bool, lunch_provided?: bool, dinner_provided?: bool, lodging_provided?: bool}  $provided
     * @return array{hours_away: string, day_factor: string, gross_amount: string, deduction_amount: string, amount: string, tax_free_amount: string, taxable_amount: string}
     */
    public function calculateDay(TravelPerDiemRate $rate, string $hoursAway, array $provided = []): array
    {
        $factor = $this->dayFactor($rate, $hoursAway);
        $gross = Money::multiply((string) $rate->daily_amount, $factor);
        $percent = $this->deductionPercent($rate, $provided);
        $deduction = Money::percentage($gross, $percent);
        $amount = Money::sub($gross, $deduction);

        if ($rate->tax_free_daily_amount === null) {
            $taxFree = $amount;
        } else {
            $threshold = Money::percentage(Money::multiply((string) $rate->tax_free_daily_amount, $factor), bcsub('100', $percent, 4));
            $taxFree = Money::min($amount, $threshold);
        }

        return [
            'hours_away' => $hoursAway,
            'day_factor' => $factor,
            'gross_amount' => $gross,
            'deduction_amount' => $deduction,
            'amount' => $amount,
            'tax_free_amount' => $taxFree,
            'taxable_amount' => Money::sub($amount, $taxFree),
        ];
    }

    public function dayFactor(TravelPerDiemRate $rate, string $hoursAway): string
    {
        return match (true) {
            bccomp($hoursAway, (string) $rate->full_day_min_hours, 2) >= 0 => '1.00',
            bccomp($hoursAway, (string) $rate->half_day_min_hours, 2) >= 0 => '0.50',
            default => '0.00',
        };
    }

    /**
     * The share of the daily amount the employer's meals and lodging take away, never above 100.
     *
     * @param  array{breakfast_provided?: bool, lunch_provided?: bool, dinner_provided?: bool, lodging_provided?: bool}  $provided
     */
    public function deductionPercent(TravelPerDiemRate $rate, array $provided): string
    {
        $percent = '0';

        if ($provided['lodging_provided'] ?? false) {
            $percent = bcadd($percent, (string) $rate->lodging_deduction_percent, 4);
        }

        if ($provided['breakfast_provided'] ?? false) {
            $percent = bcadd($percent, (string) $rate->breakfast_deduction_percent, 4);
        }

        $meals = (int) ($provided['lunch_provided'] ?? false) + (int) ($provided['dinner_provided'] ?? false);
        $mealCount = $rate->deduct_each_meal ? $meals : min(1, $meals);
        $percent = bcadd($percent, bcmul((string) $rate->meal_deduction_percent, (string) $mealCount, 4), 4);

        return bccomp($percent, '100', 4) > 0 ? '100.0000' : $percent;
    }

    private function minutes(?string $time): ?int
    {
        if ($time === null || preg_match('/^(\d{1,2}):(\d{2})$/', $time, $parts) !== 1) {
            return null;
        }

        return ((int) $parts[1]) * 60 + (int) $parts[2];
    }

    private function hours(int $minutes): string
    {
        return bcdiv((string) $minutes, '60', 2);
    }
}
