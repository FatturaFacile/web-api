<?php

declare(strict_types=1);

use Modules\Forecasting\Domain\Services\LiquidityProjectionService;
use Modules\Forecasting\Domain\ValueObjects\StatutoryCashEvent;
use Modules\Forecasting\Infrastructure\Services\HoltWintersForecastingEngine;

/**
 * @return list<float>
 */
function seasonalSeries(): array
{
    $index = [0.8, 0.6, 0.85, 1.0, 1.25, 1.65, 1.2, 0.9, 0.95, 1.25, 1.65, 1.3];
    $series = [];

    for ($month = 0; $month < 24; $month++) {
        $series[] = round(100 * $index[$month % 12] * (1 + 0.12 * $month / 12), 2);
    }

    return $series;
}

test('Holt-Winters recovers a seasonal series with a trend', function (): void {
    $result = (new HoltWintersForecastingEngine)->forecast(seasonalSeries(), 12, 3);

    // The next months are January, February and March of the third year: the trend lifts the seasonal pattern.
    $expected = [100 * 0.8 * 1.24, 100 * 0.6 * 1.26, 100 * 0.85 * 1.28];

    expect($result->model)->toBe('holt_winters')
        ->and($result->predictions)->toHaveCount(3)
        ->and($result->predictions[0])->toEqualWithDelta($expected[0], $expected[0] * 0.1)
        ->and($result->predictions[1])->toEqualWithDelta($expected[1], $expected[1] * 0.12)
        ->and($result->predictions[2])->toEqualWithDelta($expected[2], $expected[2] * 0.12)
        ->and($result->seasonalityIndex)->toBeLessThan(1.0);
});

test('a flat noisy series falls back to the mean model with the sample deviation', function (): void {
    $deviations = [13, -9, 3, -14, 8, -6, 11, -13, -3, 10, -7, 5, 14, -11, -10, 7, -12, 9, -5, 12, -8, 6, -13, 13];
    $series = array_map(static fn (int $deviation): float => 120.0 + $deviation, $deviations);
    $result = (new HoltWintersForecastingEngine)->forecast($series, 12, 1);
    $variance = array_sum(array_map(static fn (int $deviation): float => $deviation ** 2, $deviations)) / 23;

    expect($result->model)->toBe('mean')
        ->and($result->predictions[0])->toEqualWithDelta(120.0, 1e-9)
        ->and($result->residualStdDev)->toEqualWithDelta(sqrt($variance), 1e-9)
        ->and($result->seasonalityIndex)->toBe(1.0);
});

test('a short series uses the mean and a series with zeros is not forced into a multiplicative model', function (): void {
    $engine = new HoltWintersForecastingEngine;

    expect($engine->forecast([10.0, 20.0, 30.0], 12)->model)->toBe('mean')
        ->and($engine->forecast(array_merge(array_fill(0, 12, 0.0), array_fill(0, 12, 5.0)), 12)->model)->toBe('mean')
        ->and($engine->forecast([5.0], 12)->residualStdDev)->toBe(0.0);

    expect(fn () => $engine->forecast([], 12))->toThrow(InvalidArgumentException::class);
});

/**
 * @return array<string, mixed>
 */
function projectionInputs(): array
{
    return [
        'receivables' => [
            ['amount' => 50000.0, 'earliest' => '2028-01-10', 'expected' => '2028-01-20', 'latest' => '2028-02-05'],
            ['amount' => 30000.0, 'earliest' => '2028-01-02', 'expected' => '2028-01-05', 'latest' => '2028-01-09'],
        ],
        'payables' => [['amount' => 40000.0, 'date' => '2028-01-15'], ['amount' => 25000.0, 'date' => '2028-02-10']],
        'recurring' => [['amount' => 6500.0, 'date' => '2028-01-31', 'direction' => 'outflow'], ['amount' => 4000.0, 'date' => '2028-02-15', 'direction' => 'inflow']],
        'statutory' => [new StatutoryCashEvent(new DateTimeImmutable('2028-01-16'), new DateTimeImmutable('2028-01-17'), 'F24', StatutoryCashEvent::CategoryTax, 10000.0)],
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return list<array<string, mixed>>
 */
function runProjection(array $overrides = [], float $startingCash = 20000.0, int $days = 60): array
{
    $inputs = projectionInputs();

    return (new LiquidityProjectionService)->project(
        new DateTimeImmutable('2027-12-31'),
        $days,
        $startingCash,
        $inputs['receivables'],
        $inputs['payables'],
        $inputs['recurring'],
        $inputs['statutory'],
        $overrides['shift'] ?? 0,
        $overrides['growth'] ?? 0.0,
        $overrides['extension'] ?? 0,
        $overrides['interest'] ?? 0.0,
    );
}

test('the balance bands are ordered on every day', function (): void {
    foreach ([[], ['shift' => 15], ['shift' => -10, 'growth' => 20.0], ['interest' => 8.0], ['extension' => 20]] as $overrides) {
        foreach (runProjection($overrides, -5000.0) as $bucket) {
            expect($bucket['balance']['p10'])->toBeLessThanOrEqual($bucket['balance']['p50'])
                ->and($bucket['balance']['p50'])->toBeLessThanOrEqual($bucket['balance']['p90']);
        }
    }
});

test('the projection has one bucket per day and carries the statutory event on its cash date', function (): void {
    $buckets = runProjection();

    expect($buckets)->toHaveCount(60)
        ->and($buckets[0]['date'])->toBe('2028-01-01')
        ->and($buckets[59]['date'])->toBe('2028-02-29');

    $fiscal = array_values(array_filter($buckets, static fn (array $bucket): bool => $bucket['statutory_outflows'] !== []));

    expect($fiscal)->toHaveCount(1)
        ->and($fiscal[0]['date'])->toBe('2028-01-17')
        ->and($fiscal[0]['statutory_outflows'][0]['code'])->toBe('F24')
        ->and($fiscal[0]['statutory_total'])->toBe(10000.0);
});

test('cumulative cash adds up: starting cash plus receipts minus payments', function (): void {
    $last = runProjection([], 20000.0, 60)[59];

    // All receivables fall inside 60 days at p50 (80,000), payables 65,000, rent 6,500 out, subscription 4,000 in, F24 10,000 out.
    expect($last['balance']['p50'])->toBe(20000.0 + 80000.0 - 65000.0 - 6500.0 + 4000.0 - 10000.0);
});

test('a later collection lowers the cash trough', function (): void {
    $trough = static fn (array $buckets): float => min(array_map(static fn (array $bucket): float => $bucket['balance']['p50'], $buckets));

    $baseline = $trough(runProjection());
    $delayed = $trough(runProjection(['shift' => 15]));

    expect($delayed)->toBeLessThan($baseline);
});

test('the supplier extension, revenue growth and interest change the balance', function (): void {
    $final = static fn (array $buckets): float => $buckets[count($buckets) - 1]['balance']['p50'];

    expect($final(runProjection(['growth' => 10.0])))->toBeGreaterThan($final(runProjection()))
        ->and($final(runProjection(['interest' => 10.0], -50000.0)))->toBeLessThan($final(runProjection([], -50000.0)));

    $withExtension = runProjection(['extension' => 30], 20000.0, 40);
    $without = runProjection([], 20000.0, 40);

    // The second bill (due Feb 10) leaves the horizon when it is paid 30 days later.
    expect($withExtension[39]['balance']['p50'])->toBeGreaterThan($without[39]['balance']['p50']);
});

test('dates before the horizon start fall on its first day and dates after its end are ignored', function (): void {
    $buckets = (new LiquidityProjectionService)->project(
        new DateTimeImmutable('2027-12-31'),
        10,
        0.0,
        [['amount' => 100.0, 'earliest' => '2027-11-01', 'expected' => '2027-11-01', 'latest' => '2028-06-01']],
        [],
        [],
        [],
    );

    expect($buckets[0]['inflows'])->toBe(['p10' => 0.0, 'p50' => 100.0, 'p90' => 100.0])
        ->and($buckets[9]['balance'])->toBe(['p10' => 0.0, 'p50' => 100.0, 'p90' => 100.0]);
});

test('a horizon below one day is rejected', function (): void {
    (new LiquidityProjectionService)->project(new DateTimeImmutable('2027-12-31'), 0, 0.0, [], [], [], []);
})->throws(InvalidArgumentException::class);
