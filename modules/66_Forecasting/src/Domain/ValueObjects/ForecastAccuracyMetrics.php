<?php

declare(strict_types=1);

namespace Modules\Forecasting\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Forecast error measures in percent.
 *
 * MAPE = mean(|A - F| / A) over the periods with A > 0; WAPE = sum|A - F| / sum A.
 */
final readonly class ForecastAccuracyMetrics
{
    public function __construct(
        public float $mape,
        public float $wape,
        public int $sampleSize,
    ) {}

    /**
     * @param  list<float|int>  $actuals
     * @param  list<float|int>  $forecasts  same length and order as $actuals
     */
    public static function fromPairs(array $actuals, array $forecasts): self
    {
        if (count($actuals) !== count($forecasts)) {
            throw new InvalidArgumentException('Actuals and forecasts must have the same length.');
        }

        $absoluteError = 0.0;
        $actualTotal = 0.0;
        $percentErrors = [];

        foreach ($actuals as $index => $actual) {
            $error = abs((float) $actual - (float) $forecasts[$index]);
            $absoluteError += $error;
            $actualTotal += abs((float) $actual);

            if ((float) $actual > 0.0) {
                $percentErrors[] = $error / (float) $actual;
            }
        }

        return new self(
            $percentErrors === [] ? 0.0 : array_sum($percentErrors) / count($percentErrors) * 100.0,
            $actualTotal > 0.0 ? $absoluteError / $actualTotal * 100.0 : 0.0,
            count($actuals),
        );
    }

    /**
     * @return array{mape: float, wape: float, sample_size: int}
     */
    public function toArray(): array
    {
        return ['mape' => round($this->mape, 2), 'wape' => round($this->wape, 2), 'sample_size' => $this->sampleSize];
    }
}
