<?php

declare(strict_types=1);

use Modules\MarketingAutomation\Domain\Enums\RfmLabel;
use Modules\MarketingAutomation\Services\MetricsService;
use Modules\MarketingAutomation\Services\RfmScoringService;

test('recency is scored on fixed business bands', function (?int $days, int $score): void {
    expect((new RfmScoringService)->recencyScore($days))->toBe($score);
})->with([
    [0, 5], [30, 5], [31, 4], [90, 4], [91, 3], [180, 3], [181, 2], [365, 2], [366, 1], [null, 1],
]);

test('frequency and monetary scores are quintiles of the population', function (): void {
    $service = new RfmScoringService;
    $population = [1, 1, 2, 3, 4, 5, 6, 7, 8, 9];

    expect($service->quantileScore($population, 1))->toBe(1)
        ->and($service->quantileScore($population, 9))->toBe(5)
        ->and($service->quantileScore([], 10))->toBe(1)
        ->and($service->quantileScore($population, 100))->toBe(5);
});

test('the matrix cell decides the label of the customer', function (): void {
    $cases = [
        [5, 5, 5, RfmLabel::Champions],
        [3, 4, 3, RfmLabel::Loyal],
        [5, 1, 2, RfmLabel::NewCustomers],
        [4, 2, 1, RfmLabel::Promising],
        [2, 4, 4, RfmLabel::AtRisk],
        [1, 5, 5, RfmLabel::AtRisk],
        [2, 1, 1, RfmLabel::Hibernating],
        [1, 1, 2, RfmLabel::Lost],
    ];

    foreach ($cases as [$r, $f, $m, $label]) {
        expect((new RfmScoringService)->label($r, $f, $m))->toBe($label);
    }
});

test('ROI is margin minus cost over cost and never divides by zero', function (): void {
    $metrics = new MetricsService;

    expect($metrics->roi(500.0, 0.0, 1000.0))->toBe([null, 'no_cost'])
        ->and($metrics->roi(0.0, 200.0, 0.0))->toBe([-100.0, 'no_revenue'])
        ->and($metrics->roi(600.0, 200.0, 2000.0))->toBe([200.0, 'computed'])
        ->and($metrics->roi(100.0, 200.0, 400.0))->toBe([-50.0, 'computed']);
});
