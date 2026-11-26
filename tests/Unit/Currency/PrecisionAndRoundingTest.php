<?php

declare(strict_types=1);

use Modules\Treasury\Currency\Domain\Services\FxAmountConverter;
use Modules\Treasury\Currency\Domain\Support\Dec;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyCode;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;
use Modules\Treasury\Currency\Domain\ValueObjects\ExchangeRateValue;
use Modules\Treasury\Currency\Domain\ValueObjects\Money;

/**
 * Amounts and rates are decimal strings, never floats; rates have six decimals, amounts two; rounding is half up; the lines of a
 * document add up to its total (the residual goes to the largest line); the 0.05 CHF rounding belongs to the issued document
 * total only.
 */
test('decimal arithmetic is exact on large amounts and six-decimal rates', function (): void {
    expect(Dec::money(Dec::mul('123456789012345.67', '1.234567')))->toBe('152415677640604.56')
        ->and(Dec::money(Dec::mul('999999999.99', '0.000001')))->toBe('1000.00')
        ->and(Dec::add('0.1', '0.2'))->toBe(Dec::norm('0.3'))
        ->and(Dec::cmp(Dec::add('0.1', '0.2'), '0.3'))->toBe(0);
});

test('rounding is half up, away from zero for negative amounts', function (): void {
    expect(Dec::money('1.005'))->toBe('1.01')
        ->and(Dec::money('1.004999'))->toBe('1.00')
        ->and(Dec::money('-1.005'))->toBe('-1.01')
        ->and(Dec::rate('0.9412345'))->toBe('0.941235')
        ->and(Dec::rate('0.9412344'))->toBe('0.941234');
});

test('a float or a malformed number is refused at the boundary', function (): void {
    expect(fn () => Dec::add(1.5, '1'))->toThrow(TypeError::class)
        ->and(fn () => Dec::norm('1e5'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Dec::norm('1,5'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Dec::norm('abc'))->toThrow(InvalidArgumentException::class)
        ->and(Dec::isDecimal('12.50'))->toBeTrue()
        ->and(Dec::isDecimal(12.5))->toBeFalse()
        ->and(Dec::isDecimal('1e5'))->toBeFalse();
});

test('the number of decimals ignores trailing zeros', function (): void {
    expect(Dec::decimals('0.941200'))->toBe(4)
        ->and(Dec::decimals('1'))->toBe(0)
        ->and(Dec::decimals('1.0000001'))->toBe(7);
});

test('an exchange rate typed by a person has at most six decimals and is positive', function (): void {
    expect(ExchangeRateValue::strict('0.941200')->value)->toBe('0.941200')
        ->and(ExchangeRateValue::strict('1')->value)->toBe('1.000000')
        ->and(fn () => ExchangeRateValue::strict('0.9412001'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ExchangeRateValue::strict('0'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ExchangeRateValue::strict('-0.5'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ExchangeRateValue::strict('abc'))->toThrow(InvalidArgumentException::class);
});

test('a published or computed rate is rounded to six decimals and its inverse is exact to six', function (): void {
    expect(ExchangeRateValue::of('0.9412345')->value)->toBe('0.941235')
        ->and(ExchangeRateValue::of('1.25')->inverse()->value)->toBe('0.800000')
        ->and(ExchangeRateValue::of('1.061008')->inverse()->value)->toBe('0.942500')
        ->and(fn () => ExchangeRateValue::of('0.0000004'))->toThrow(InvalidArgumentException::class);
});

test('money converts to two decimals half up and combines only the same currency', function (): void {
    $chf = Money::of('100.005', 'CHF');
    $converted = Money::of('1000', 'EUR')->convert(ExchangeRateValue::of('0.941235'), CurrencyCode::of('CHF'));

    expect($chf->amount)->toBe('100.01')
        ->and($chf->amount)->toBeString()
        ->and($converted->amount)->toBe('941.24')
        ->and($converted->currency->value)->toBe('CHF')
        ->and($chf->plus(Money::of('0.04', 'CHF'))->amount)->toBe('100.05')
        ->and($chf->minus(Money::of('0.01', 'CHF'))->amount)->toBe('100.00')
        ->and($chf->negate()->amount)->toBe('-100.01')
        ->and(fn () => $chf->plus(Money::of('1', 'EUR')))->toThrow(InvalidArgumentException::class);
});

test('currency codes and pairs are normalised and validated', function (): void {
    expect(CurrencyCode::of(' chf ')->value)->toBe('CHF')
        ->and(CurrencyPair::of('eur', 'chf')->key())->toBe('EUR/CHF')
        ->and(CurrencyPair::of('EUR', 'CHF')->inverse()->key())->toBe('CHF/EUR')
        ->and(CurrencyPair::of('CHF', 'CHF')->isIdentity())->toBeTrue()
        ->and(fn () => CurrencyCode::of('EURO'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CurrencyCode::of('E1R'))->toThrow(InvalidArgumentException::class);
});

test('the residual of the line conversion goes to the largest line and the lines add up to the total', function (): void {
    $converter = new FxAmountConverter;

    // 10.01 × 0.5 = 5.005 → 5.01 on every line (15.03), but the document is 30.03 × 0.5 = 15.015 → 15.02: one cent less.
    $even = $converter->convertLines(['10.01', '10.01', '10.01'], ExchangeRateValue::of('0.5'));
    // The largest line (200.00) takes the residual.
    $uneven = $converter->convertLines(['0.01', '200.00', '0.01'], ExchangeRateValue::of('0.333333'));

    expect($even['total'])->toBe('15.02')
        ->and($even['residual'])->toBe('-0.01')
        ->and($even['lines'])->toBe(['5.00', '5.01', '5.01'])
        ->and(array_reduce($even['lines'], static fn (string $sum, string $line): string => Dec::money(Dec::add($sum, $line)), '0'))->toBe($even['total'])
        ->and(array_reduce($uneven['lines'], static fn (string $sum, string $line): string => Dec::money(Dec::add($sum, $line)), '0'))->toBe($uneven['total']);
});

test('lines of a credit note convert with their sign and an empty document converts to nothing', function (): void {
    $converter = new FxAmountConverter;

    expect($converter->convertLines(['-100.00', '-0.01'], ExchangeRateValue::of('0.9'))['total'])->toBe('-90.01')
        ->and($converter->convertLines([], ExchangeRateValue::of('0.9')))->toBe(['lines' => [], 'total' => '0.00', 'residual' => '0.00']);
});

test('the 0.05 CHF rounding is a rule of the issued document total, never of the accounting conversion', function (): void {
    $converter = new FxAmountConverter;

    $document = $converter->swissDocumentTotal('100.33');
    $down = $converter->swissDocumentTotal('100.32');
    $half = $converter->swissDocumentTotal('100.325');

    // The accounting counter-value of a CHF line stays at two decimals.
    $accounting = $converter->convertLines(['100.33'], ExchangeRateValue::one());

    expect($document)->toBe(['total' => '100.35', 'rounding' => '0.02'])
        ->and($down)->toBe(['total' => '100.30', 'rounding' => '-0.02'])
        ->and($half['total'])->toBe('100.35')
        ->and($accounting['total'])->toBe('100.33')
        ->and($accounting['lines'])->toBe(['100.33']);
});
