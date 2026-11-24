<?php

declare(strict_types=1);

use Modules\Forecasting\Domain\Entities\CustomerPaymentBehaviorModel;
use Modules\Forecasting\Domain\Entities\SkuDemandForecast;
use Modules\Forecasting\Domain\Enums\ForecastHorizon;
use Modules\Forecasting\Domain\Enums\JurisdictionCode;
use Modules\Forecasting\Domain\Enums\PaymentRiskTier;
use Modules\Forecasting\Domain\Services\NormalDistribution;
use Modules\Forecasting\Domain\Services\PaymentDelayStatistics;
use Modules\Forecasting\Domain\ValueObjects\ConfidenceBand;
use Modules\Forecasting\Domain\ValueObjects\ForecastAccuracyMetrics;

test('safety stock follows SS = Z * sqrt(LT * sigma_D^2 + D^2 * sigma_LT^2)', function (): void {
    // D = 4/day, sigma_D = 1.8/day, LT = 21 days, sigma_LT = 9 days, Z = 1.65.
    $expected = 1.65 * sqrt(21 * 1.8 ** 2 + 4 ** 2 * 9 ** 2);

    expect(SkuDemandForecast::safetyStock(1.65, 21.0, 1.8, 4.0, 9.0))->toEqualWithDelta($expected, 1e-9)
        ->and(SkuDemandForecast::safetyStock(1.65, 14.0, 0.0, 5.0, 0.0))->toBe(0.0);
});

test('reorder point is demand over the lead time plus safety stock', function (): void {
    expect(SkuDemandForecast::reorderPoint(4.0, 21.0, 61.0))->toBe(145.0);
});

test('negative inputs of the safety stock formula are rejected', function (): void {
    SkuDemandForecast::safetyStock(1.65, -1.0, 1.0, 1.0, 1.0);
})->throws(InvalidArgumentException::class);

test('the normal quantile matches the known z-scores', function (): void {
    expect(NormalDistribution::quantile(0.5))->toEqualWithDelta(0.0, 1e-9)
        ->and(NormalDistribution::quantile(0.95))->toEqualWithDelta(1.6448536, 1e-6)
        ->and(NormalDistribution::quantile(0.9))->toEqualWithDelta(NormalDistribution::Z80, 1e-6)
        ->and(NormalDistribution::quantile(0.1))->toEqualWithDelta(-NormalDistribution::Z80, 1e-6)
        ->and(NormalDistribution::quantile(0.999))->toEqualWithDelta(3.0902323, 1e-6);
});

test('MAPE and WAPE are computed from actuals and forecasts', function (): void {
    $metrics = ForecastAccuracyMetrics::fromPairs([100, 200, 50], [110, 180, 50]);

    // |errors| = 10, 20, 0 -> MAPE = mean(10%, 10%, 0%) = 6.67%, WAPE = 30 / 350 = 8.57%
    expect($metrics->mape)->toEqualWithDelta(6.6667, 1e-3)
        ->and($metrics->wape)->toEqualWithDelta(8.5714, 1e-3)
        ->and($metrics->sampleSize)->toBe(3);
});

test('MAPE skips periods without sales while WAPE keeps their error', function (): void {
    $metrics = ForecastAccuracyMetrics::fromPairs([0, 100], [10, 100]);

    expect($metrics->mape)->toBe(0.0)->and($metrics->wape)->toEqualWithDelta(10.0, 1e-9);
});

test('accuracy of empty or mismatched series', function (): void {
    expect(ForecastAccuracyMetrics::fromPairs([], [])->toArray())->toBe(['mape' => 0.0, 'wape' => 0.0, 'sample_size' => 0]);

    ForecastAccuracyMetrics::fromPairs([1], []);
})->throws(InvalidArgumentException::class);

test('a confidence band keeps p10 <= p50 <= p90', function (): void {
    $band = ConfidenceBand::sorted(300.0, 100.0, 200.0);

    expect($band->toArray())->toBe(['p10' => 100.0, 'p50' => 200.0, 'p90' => 300.0])
        ->and($band->spread())->toBe(200.0)
        ->and($band->plus(ConfidenceBand::point(10.0))->p10)->toBe(110.0)
        ->and($band->minus(ConfidenceBand::point(10.0))->p90)->toBe(290.0);

    new ConfidenceBand(5.0, 1.0, 9.0);
})->throws(InvalidArgumentException::class);

test('horizons and jurisdictions', function (): void {
    expect(ForecastHorizon::Weeks13->days())->toBe(91)
        ->and(ForecastHorizon::Months12->days())->toBe(365)
        ->and(ForecastHorizon::Custom->days(120))->toBe(120)
        ->and(ForecastHorizon::fromDays(91))->toBe(ForecastHorizon::Weeks13)
        ->and(ForecastHorizon::fromDays(200))->toBe(ForecastHorizon::Custom)
        ->and(JurisdictionCode::fromCountry('ch'))->toBe(JurisdictionCode::Ch)
        ->and(JurisdictionCode::fromCountry('IT'))->toBe(JurisdictionCode::It)
        ->and(JurisdictionCode::fromCountry(null))->toBe(JurisdictionCode::It);

    expect(fn () => ForecastHorizon::fromDays(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ForecastHorizon::Custom->days())->toThrow(InvalidArgumentException::class);
});

test('risk tiers follow the expected delay', function (): void {
    expect(PaymentRiskTier::classify(-1.0, 0.9))->toBe(PaymentRiskTier::Low)
        ->and(PaymentRiskTier::classify(10.0, 0.2))->toBe(PaymentRiskTier::Moderate)
        ->and(PaymentRiskTier::classify(30.0, 0.0))->toBe(PaymentRiskTier::High)
        ->and(PaymentRiskTier::classify(45.0, 0.0))->toBe(PaymentRiskTier::Critical)
        ->and(PaymentRiskTier::Critical->severity())->toBeGreaterThan(PaymentRiskTier::High->severity());
});

test('recent payments weigh more than old ones', function (): void {
    // Delays worsen from +15 to +55 days over a year.
    $samples = [];

    foreach ([15, 18, 22, 26, 30, 45, 50, 55] as $index => $delay) {
        $samples[] = ['days_late' => $delay, 'age_days' => 360 - $index * 50];
    }

    $weighted = PaymentDelayStatistics::compute($samples);
    $flat = array_sum(array_column($samples, 'days_late')) / count($samples);

    expect($weighted['mean'])->toBeGreaterThan($flat + 5)
        ->and($weighted['count'])->toBe(8)
        ->and($weighted['on_time_probability'])->toBe(0.0);
});

test('payment statistics of an on-time payer and of no history', function (): void {
    $samples = array_map(static fn (int $delay): array => ['days_late' => $delay, 'age_days' => 30], [-3, -2, 0, 1, -1]);
    $result = PaymentDelayStatistics::compute($samples);

    expect($result['mean'])->toEqualWithDelta(-1.0, 1e-9)
        ->and($result['on_time_probability'])->toBe(1.0)
        ->and($result['std_dev'])->toBeGreaterThan(0.0)
        ->and(PaymentDelayStatistics::compute([])['on_time_probability'])->toBe(1.0);
});

test('the invoice collection date and window shift the due date by the predicted offset', function (): void {
    $model = new CustomerPaymentBehaviorModel('m', 't', 'c', 20, 10.2, 4.0, 0.3, 10, PaymentRiskTier::Moderate, new DateTimeImmutable('2027-01-01'));
    $due = new DateTimeImmutable('2027-03-01');
    $window = $model->calculateInvoiceConfidenceWindow($due);

    expect($model->predictCollectionDate($due)->format('Y-m-d'))->toBe('2027-03-11')
        ->and($window['p50']->format('Y-m-d'))->toBe('2027-03-11')
        // 1.2816 * 4 = 5.13 days either side of +10.
        ->and($window['p10']->format('Y-m-d'))->toBe('2027-03-06')
        ->and($window['p90']->format('Y-m-d'))->toBe('2027-03-16');
});

test('an early payer is collected before the due date', function (): void {
    $model = new CustomerPaymentBehaviorModel('m', 't', 'c', 20, -2.0, 0.0, 0.9, -2, PaymentRiskTier::Low, new DateTimeImmutable('2027-01-01'));

    expect($model->predictCollectionDate(new DateTimeImmutable('2027-03-01'))->format('Y-m-d'))->toBe('2027-02-27');
});
