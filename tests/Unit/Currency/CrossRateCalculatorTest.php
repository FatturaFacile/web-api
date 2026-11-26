<?php

declare(strict_types=1);

use Modules\Treasury\Currency\Domain\Services\CrossRateCalculator;

/**
 * Cross rates through the pivot currency: 1 A = (pivot units per A) / (pivot units per B) B, legs at full precision, only the
 * result rounded to six decimals.
 */
test('a cross rate divides the legs and rounds half up to six decimals', function (): void {
    $calculator = new CrossRateCalculator;

    expect($calculator->cross('0.9412', '1.056')->value)->toBe('0.891288')
        ->and($calculator->cross('1.056', '0.9412')->value)->toBe('1.121972')
        ->and($calculator->cross('1', '3')->value)->toBe('0.333333')
        ->and($calculator->cross('2', '3')->value)->toBe('0.666667');
});

test('the legs of a cross rate must be positive', function (): void {
    $calculator = new CrossRateCalculator;

    expect(fn () => $calculator->cross('0', '1.056'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $calculator->cross('1', '-2'))->toThrow(InvalidArgumentException::class);
});

test('every ordered pair among the tracked currencies is derived from the rates against EUR', function (): void {
    // 1 EUR = 0.9412 CHF = 1.056 USD
    $pairs = (new CrossRateCalculator)->derivePairs('EUR', ['CHF' => '0.9412', 'USD' => '1.056'], ['CHF', 'USD', 'EUR']);

    $byKey = [];

    foreach ($pairs as $derived) {
        $byKey[$derived['pair']->key()] = $derived['rate']->value;
    }

    expect($byKey)->toHaveCount(6)
        ->and($byKey['CHF/USD'])->toBe('1.121972')
        ->and($byKey['USD/CHF'])->toBe('0.891288')
        ->and($byKey['CHF/EUR'])->toBe('1.062473')
        ->and($byKey['USD/EUR'])->toBe('0.946970')
        ->and($byKey['EUR/CHF'])->toBe('0.941200')
        ->and($byKey['EUR/USD'])->toBe('1.056000');
});

test('a currency without a rate against the pivot is left out of the derived pairs', function (): void {
    $pairs = (new CrossRateCalculator)->derivePairs('EUR', ['CHF' => '0.9412'], ['CHF', 'USD', 'EUR']);

    $keys = array_map(static fn (array $derived): string => $derived['pair']->key(), $pairs);
    sort($keys);

    expect($keys)->toBe(['CHF/EUR', 'EUR/CHF']);
});
