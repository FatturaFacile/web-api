<?php

declare(strict_types=1);

namespace Modules\Forecasting\Infrastructure\Services;

use InvalidArgumentException;
use Modules\Forecasting\Domain\Contracts\ForecastingEngineInterface;
use Modules\Forecasting\Domain\ValueObjects\DemandForecastResult;

/**
 * Multiplicative Holt-Winters (level, trend, seasonal index) with a grid search of the smoothing constants, compared with
 * a plain mean model. The model with the lower one-step error wins, so a flat noisy series is not forced into a seasonal
 * shape. Needs two full seasons for the seasonal model; shorter series use the mean.
 */
final class HoltWintersForecastingEngine implements ForecastingEngineInterface
{
    private const array Alphas = [0.1, 0.2, 0.3, 0.5, 0.7];

    private const array Betas = [0.0, 0.05, 0.1, 0.2];

    private const array Gammas = [0.0, 0.1, 0.3, 0.5];

    public function forecast(array $series, int $seasonLength, int $periodsAhead = 1): DemandForecastResult
    {
        if ($series === []) {
            throw new InvalidArgumentException('A forecast needs at least one observation.');
        }

        if ($periodsAhead < 1 || $seasonLength < 1) {
            throw new InvalidArgumentException('The season length and the forecast periods must be at least 1.');
        }

        $series = array_map('floatval', $series);
        $mean = $this->meanModel($series, $periodsAhead);

        if (count($series) < 2 * $seasonLength || $seasonLength < 2 || min($series) <= 0.0) {
            return $mean;
        }

        $best = null;

        foreach (self::Alphas as $alpha) {
            foreach (self::Betas as $beta) {
                foreach (self::Gammas as $gamma) {
                    $fit = $this->fit($series, $seasonLength, $alpha, $beta, $gamma, $periodsAhead);

                    if ($best === null || $fit['sse'] < $best['sse']) {
                        $best = $fit;
                    }
                }
            }
        }

        // Both models are scored on the same one-step forecasts (the periods after the first season).
        $meanMse = $this->meanOneStepMse($series, $seasonLength);
        $holtMse = $best['sse'] / $best['count'];

        if ($holtMse >= $meanMse) {
            return $mean;
        }

        return new DemandForecastResult($best['predictions'], sqrt($holtMse), $best['seasonality'], 'holt_winters');
    }

    /**
     * @param  list<float>  $series
     */
    private function meanModel(array $series, int $periodsAhead): DemandForecastResult
    {
        $count = count($series);
        $mean = array_sum($series) / $count;
        $variance = $count > 1 ? array_sum(array_map(static fn (float $value): float => ($value - $mean) ** 2, $series)) / ($count - 1) : 0.0;

        return new DemandForecastResult(array_fill(0, $periodsAhead, max(0.0, $mean)), sqrt($variance), 1.0, 'mean');
    }

    /**
     * Mean squared error of forecasting each period from the mean of the ones before it.
     *
     * @param  list<float>  $series
     */
    private function meanOneStepMse(array $series, int $from): float
    {
        $sum = array_sum(array_slice($series, 0, $from));
        $sse = 0.0;

        for ($t = $from; $t < count($series); $t++) {
            $sse += ($series[$t] - $sum / $t) ** 2;
            $sum += $series[$t];
        }

        return $sse / (count($series) - $from);
    }

    /**
     * @param  list<float>  $series
     * @return array{sse: float, count: int, predictions: list<float>, seasonality: float}
     */
    private function fit(array $series, int $season, float $alpha, float $beta, float $gamma, int $periodsAhead): array
    {
        $count = count($series);
        $firstMean = array_sum(array_slice($series, 0, $season)) / $season;
        $secondMean = array_sum(array_slice($series, $season, $season)) / $season;
        $level = $firstMean;
        $trend = ($secondMean - $firstMean) / $season;
        $seasonal = [];

        // The seasonal factors start from the first season only, so the one-step errors of the later periods are honest.
        for ($i = 0; $i < $season; $i++) {
            $seasonal[$i] = $series[$i] / $firstMean;
        }

        $sse = 0.0;
        $errors = 0;

        for ($t = $season; $t < $count; $t++) {
            $index = $t % $season;
            $predicted = ($level + $trend) * $seasonal[$index];
            $sse += ($series[$t] - $predicted) ** 2;
            $errors++;

            $previousLevel = $level;
            $level = $alpha * ($series[$t] / $seasonal[$index]) + (1.0 - $alpha) * ($level + $trend);
            $trend = $beta * ($level - $previousLevel) + (1.0 - $beta) * $trend;
            $seasonal[$index] = $gamma * ($series[$t] / $level) + (1.0 - $gamma) * $seasonal[$index];
        }

        $predictions = [];

        for ($h = 1; $h <= $periodsAhead; $h++) {
            $predictions[] = max(0.0, ($level + $h * $trend) * $seasonal[($count + $h - 1) % $season]);
        }

        return ['sse' => $sse, 'count' => max(1, $errors), 'predictions' => $predictions, 'seasonality' => $seasonal[$count % $season]];
    }
}
