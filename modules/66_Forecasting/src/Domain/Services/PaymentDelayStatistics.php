<?php

declare(strict_types=1);

namespace Modules\Forecasting\Domain\Services;

use InvalidArgumentException;

/**
 * Exponentially weighted statistics of the payment delays of a customer: an invoice settled `ageDays` ago weighs
 * 0.5 ^ (ageDays / halfLifeDays), so the last 6 to 12 months drive the estimate.
 */
final class PaymentDelayStatistics
{
    public const int DefaultHalfLifeDays = 90;

    /** Days after the due date still counted as on time. */
    public const float OnTimeToleranceDays = 1.0;

    /**
     * @param  list<array{days_late: float|int, age_days: int}>  $samples
     * @return array{mean: float, std_dev: float, on_time_probability: float, count: int}
     */
    public static function compute(array $samples, int $halfLifeDays = self::DefaultHalfLifeDays): array
    {
        if ($halfLifeDays < 1) {
            throw new InvalidArgumentException('The half-life must be at least one day.');
        }

        if ($samples === []) {
            return ['mean' => 0.0, 'std_dev' => 0.0, 'on_time_probability' => 1.0, 'count' => 0];
        }

        $weightTotal = 0.0;
        $weightedSum = 0.0;
        $weights = [];

        foreach ($samples as $index => $sample) {
            $weights[$index] = 0.5 ** (max(0, $sample['age_days']) / $halfLifeDays);
            $weightTotal += $weights[$index];
            $weightedSum += $weights[$index] * (float) $sample['days_late'];
        }

        $mean = $weightedSum / $weightTotal;
        $variance = 0.0;
        $onTime = 0.0;

        foreach ($samples as $index => $sample) {
            $variance += $weights[$index] * ((float) $sample['days_late'] - $mean) ** 2;

            if ((float) $sample['days_late'] <= self::OnTimeToleranceDays) {
                $onTime += $weights[$index];
            }
        }

        return [
            'mean' => $mean,
            'std_dev' => sqrt($variance / $weightTotal),
            'on_time_probability' => $onTime / $weightTotal,
            'count' => count($samples),
        ];
    }
}
