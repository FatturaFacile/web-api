<?php

declare(strict_types=1);

use Modules\Invoicing\Services\Receivables\AgingBucketPolicy;

test('the default buckets are current, 1-30, 31-60, 61-90, 91-120 and 121+', function (): void {
    $policy = new AgingBucketPolicy;

    expect($policy->keys())->toBe(['current', '1_30', '31_60', '61_90', '91_120', '121_plus']);
});

test('a day count lands in the bucket whose range holds it, with the boundaries on the upper bound', function (int $days, string $key): void {
    expect((new AgingBucketPolicy)->keyFor($days))->toBe($key);
})->with([
    'not yet due' => [-5, 'current'],
    'due today' => [0, 'current'],
    'one day late' => [1, '1_30'],
    'thirty days late' => [30, '1_30'],
    'thirty one days late' => [31, '31_60'],
    'sixty days late' => [60, '31_60'],
    'sixty one days late' => [61, '61_90'],
    'ninety days late' => [90, '61_90'],
    'ninety one days late' => [91, '91_120'],
    'one hundred twenty days late' => [120, '91_120'],
    'one hundred twenty one days late' => [121, '121_plus'],
    'years late' => [900, '121_plus'],
]);

test('the bounds are configurable and the over-90 aggregate follows them', function (): void {
    $policy = new AgingBucketPolicy([15, 45, 90]);

    expect($policy->keys())->toBe(['current', '1_15', '16_45', '46_90', '91_plus'])
        ->and($policy->keyFor(16))->toBe('16_45')
        ->and($policy->keysOver(90))->toBe(['91_plus'])
        ->and((new AgingBucketPolicy)->keysOver(90))->toBe(['91_120', '121_plus']);
});

test('empty totals hold a zero for every bucket', function (): void {
    expect((new AgingBucketPolicy)->emptyTotals())->toBe(['current' => '0.00', '1_30' => '0.00', '31_60' => '0.00', '61_90' => '0.00', '91_120' => '0.00', '121_plus' => '0.00']);
});

test('bounds that are empty, start at zero or do not ascend are refused', function (array $bounds): void {
    new AgingBucketPolicy($bounds);
})->with([
    'empty' => [[]],
    'zero first' => [[0, 30]],
    'descending' => [[60, 30]],
    'repeated' => [[30, 30]],
])->throws(InvalidArgumentException::class);
