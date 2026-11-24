<?php

declare(strict_types=1);

use Modules\SwissFiscal\Domain\Enums\SwissVatRate;
use Modules\SwissFiscal\Domain\Exceptions\SwissFiscalException;
use Modules\SwissFiscal\Domain\ValueObjects\Currency;
use Modules\SwissFiscal\Domain\ValueObjects\ExchangeRateSnapshot;
use Modules\SwissFiscal\Domain\ValueObjects\Money;

it('parses decimals into minor units and prints them back', function (): void {
    expect(Money::of('1949.75', Currency::CHF)->minor)->toBe(194975)
        ->and(Money::of('-0.05', Currency::EUR)->toDecimalString())->toBe('-0.05')
        ->and(Money::of('12.345', Currency::USD)->minor)->toBe(1235)
        ->and(Money::of('7', Currency::CHF)->toDecimalString())->toBe('7.00');
});

it('rounds CHF to the nearest 5 rappen with ties away from zero', function (string $amount, string $expected): void {
    expect(Money::of($amount, Currency::CHF)->roundToCashIncrement()->toDecimalString())->toBe($expected);
})->with([
    ['0.02', '0.00'], ['0.03', '0.05'], ['0.05', '0.05'], ['1.22', '1.20'], ['1.23', '1.25'],
    ['1.28', '1.30'], ['-0.03', '-0.05'], ['-0.02', '0.00'], ['99.99', '100.00'],
]);

it('keeps cent rounding for EUR and USD', function (): void {
    expect(Money::of('1.23', Currency::EUR)->roundToCashIncrement()->toDecimalString())->toBe('1.23');
});

it('computes Swiss VAT on net and extracts it from gross', function (): void {
    $net = Money::of('100.00', Currency::CHF);

    expect(SwissVatRate::Standard->onNet($net)->toDecimalString())->toBe('8.10')
        ->and(SwissVatRate::Reduced->onNet($net)->toDecimalString())->toBe('2.60')
        ->and(SwissVatRate::Accommodation->onNet($net)->toDecimalString())->toBe('3.80')
        ->and(SwissVatRate::Exempt->onNet($net)->isZero())->toBeTrue()
        ->and(SwissVatRate::Standard->inGross(Money::of('108.10', Currency::CHF))->toDecimalString())->toBe('8.10');
});

it('allocates without losing a minor unit', function (): void {
    $parts = Money::of('0.05', Currency::CHF)->allocate([1, 1, 1]);

    expect(array_map(fn (Money $m): int => $m->minor, $parts))->toBe([2, 2, 1])
        ->and(array_sum(array_map(fn (Money $m): int => $m->minor, $parts)))->toBe(5);
});

it('multiplies by fractional quantities and discounts', function (): void {
    expect(Money::of('19.99', Currency::EUR)->times('2.5')->toDecimalString())->toBe('49.98')
        ->and(Money::of('200.00', Currency::CHF)->percentage('12.5')->toDecimalString())->toBe('25.00');
});

it('refuses to combine different currencies', function (): void {
    Money::of('1', Currency::CHF)->plus(Money::of('1', Currency::EUR));
})->throws(SwissFiscalException::class);

it('converts with a locked exchange rate snapshot', function (): void {
    $snapshot = new ExchangeRateSnapshot(Currency::EUR, Currency::CHF, '0.9421', new DateTimeImmutable('2026-01-15'), 'ecb');

    expect($snapshot->convert(Money::of('1000.00', Currency::EUR))->toDecimalString())->toBe('942.10')
        ->and($snapshot->convert(Money::of('0.01', Currency::EUR))->toDecimalString())->toBe('0.01')
        ->and($snapshot->inverse()->base)->toBe(Currency::CHF);
});

it('rejects a snapshot applied to the wrong currency', function (): void {
    (new ExchangeRateSnapshot(Currency::EUR, Currency::CHF, '0.94', new DateTimeImmutable))->convert(Money::of('1', Currency::USD));
})->throws(SwissFiscalException::class);
