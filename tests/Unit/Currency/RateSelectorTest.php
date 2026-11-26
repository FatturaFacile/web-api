<?php

declare(strict_types=1);

use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\Exceptions\ExchangeRateMissingException;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;
use Tests\Support\Currency\FxRates;

/**
 * The rate chosen for a pair and a day: the rate of the day, the latest earlier one within five days (weekends, holidays), by
 * inversion or through EUR, contract and tenant rates, ESTV monthly averages, and the blocking `exchange_rate_missing`.
 * 2026-11-20 is a Friday, 2026-11-21 a Saturday, 2026-11-22 a Sunday, 2026-11-23 a Monday.
 */
test('the rate of the exact day is chosen', function (): void {
    $selector = FxRates::selector([
        FxRates::rate('EUR', 'CHF', '0.941200', '2026-11-19'),
        FxRates::rate('EUR', 'CHF', '0.943300', '2026-11-20'),
    ]);

    $resolved = $selector->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-20', null);

    expect($resolved->rate->value)->toBe('0.943300')
        ->and($resolved->validOn)->toBe('2026-11-20')
        ->and($resolved->isFallbackDate())->toBeFalse()
        ->and($resolved->isDerived)->toBeFalse()
        ->and($resolved->source)->toBe(RateSource::Ecb);
});

test('a weekend or a holiday takes the latest earlier rate', function (): void {
    $selector = FxRates::selector([FxRates::rate('EUR', 'CHF', '0.943300', '2026-11-20')]);

    foreach (['2026-11-21', '2026-11-22', '2026-11-23'] as $day) {
        $resolved = $selector->select(CurrencyPair::of('EUR', 'CHF'), $day, null);

        expect($resolved->rate->value)->toBe('0.943300')
            ->and($resolved->validOn)->toBe('2026-11-20')
            ->and($resolved->requestedDate)->toBe($day)
            ->and($resolved->isFallbackDate())->toBeTrue();
    }
});

test('the window is five days: the fifth day back is taken, the sixth is refused with exchange_rate_missing', function (): void {
    $selector = FxRates::selector([FxRates::rate('EUR', 'CHF', '0.943300', '2026-11-20')]);

    expect($selector->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-25', null)->validOn)->toBe('2026-11-20');

    try {
        $selector->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-26', null);
        $this->fail('The sixth day must be refused.');
    } catch (ExchangeRateMissingException $exception) {
        expect($exception->errorCode())->toBe('exchange_rate_missing')
            ->and($exception->status())->toBe(422)
            ->and($exception->field())->toBe('exchange_rate')
            ->and($exception->windowDays)->toBe(5)
            ->and($exception->date)->toBe('2026-11-26');
    }
});

test('a rate dated after the requested day is never used', function (): void {
    $selector = FxRates::selector([FxRates::rate('EUR', 'CHF', '0.950000', '2026-11-24')]);

    expect(fn () => $selector->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-23', null))->toThrow(ExchangeRateMissingException::class);
});

test('a pair without any rate is refused, never replaced by an invented rate', function (): void {
    expect(fn () => FxRates::selector([])->select(CurrencyPair::of('EUR', 'JPY'), '2026-11-20', null))->toThrow(ExchangeRateMissingException::class);
});

test('a document in the functional currency has rate 1.000000', function (): void {
    $resolved = FxRates::selector([])->select(CurrencyPair::of('CHF', 'CHF'), '2026-11-20', null);

    expect($resolved->rate->value)->toBe('1.000000')
        ->and($resolved->isDerived)->toBeFalse()
        ->and($resolved->rateId)->toBeNull();
});

test('the opposite pair is inverted and flagged derived', function (): void {
    $selector = FxRates::selector([FxRates::rate('CHF', 'EUR', '1.061008', '2026-11-20')]);

    $resolved = $selector->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-20', null);

    expect($resolved->rate->value)->toBe('0.942500')
        ->and($resolved->isDerived)->toBeTrue();
});

test('a cross rate goes through EUR and is flagged derived', function (): void {
    // 1 EUR = 0.9412 CHF, 1 EUR = 1.0560 USD: 1 USD = 0.9412 / 1.0560 CHF
    $selector = FxRates::selector([
        FxRates::rate('EUR', 'CHF', '0.941200', '2026-11-20'),
        FxRates::rate('EUR', 'USD', '1.056000', '2026-11-20'),
    ]);

    $usdChf = $selector->select(CurrencyPair::of('USD', 'CHF'), '2026-11-20', null);
    $chfUsd = $selector->select(CurrencyPair::of('CHF', 'USD'), '2026-11-20', null);

    expect($usdChf->rate->value)->toBe('0.891288')
        ->and($usdChf->isDerived)->toBeTrue()
        ->and($chfUsd->rate->value)->toBe('1.121972')
        ->and($chfUsd->isDerived)->toBeTrue();
});

test('a cross rate needs both legs on the same day', function (): void {
    $selector = FxRates::selector([
        FxRates::rate('EUR', 'CHF', '0.941200', '2026-11-20'),
        FxRates::rate('EUR', 'USD', '1.056000', '2026-11-19'),
    ]);

    expect(fn () => $selector->select(CurrencyPair::of('USD', 'CHF'), '2026-11-20', null))->toThrow(ExchangeRateMissingException::class);
});

test('the freshest candidate wins between a direct rate and a cross rate', function (): void {
    $selector = FxRates::selector([
        FxRates::rate('USD', 'CHF', '0.890000', '2026-11-18'),
        FxRates::rate('EUR', 'CHF', '0.941200', '2026-11-20'),
        FxRates::rate('EUR', 'USD', '1.056000', '2026-11-20'),
    ]);

    $resolved = $selector->select(CurrencyPair::of('USD', 'CHF'), '2026-11-20', null);

    expect($resolved->validOn)->toBe('2026-11-20')->and($resolved->isDerived)->toBeTrue();
});

test('the rate of a company wins over the official rate of the same day, and another company never sees it', function (): void {
    $rates = [
        FxRates::rate('EUR', 'CHF', '0.941200', '2026-11-20'),
        FxRates::rate('EUR', 'CHF', '0.930000', '2026-11-20', tenantId: 'tenant-a', source: RateSource::Manual),
    ];

    $selector = FxRates::selector($rates);

    expect($selector->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-20', 'tenant-a')->rate->value)->toBe('0.930000')
        ->and($selector->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-20', 'tenant-b')->rate->value)->toBe('0.941200')
        ->and($selector->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-20', null)->rate->value)->toBe('0.941200');
});

test('a superseded version is not used', function (): void {
    $selector = FxRates::selector([
        FxRates::rate('EUR', 'CHF', '0.900000', '2026-11-20', tenantId: 'tenant-a', supersededBy: 'new', source: RateSource::Manual),
        FxRates::rate('EUR', 'CHF', '0.910000', '2026-11-20', tenantId: 'tenant-a', source: RateSource::Manual),
    ]);

    expect($selector->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-20', 'tenant-a')->rate->value)->toBe('0.910000');
});

test('a contract rate is chosen only by its reference and carries the contract flag', function (): void {
    $selector = FxRates::selector([
        FxRates::rate('EUR', 'CHF', '0.941200', '2026-11-20'),
        FxRates::rate('EUR', 'CHF', '0.920000', '2026-01-10', RateType::Contract, 'tenant-a', contractReference: 'CNT-77', source: RateSource::Manual),
    ]);

    $contract = $selector->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-20', 'tenant-a', RateType::Mid, 'CNT-77');
    $plain = $selector->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-20', 'tenant-a');
    $unknownReference = $selector->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-20', 'tenant-a', RateType::Mid, 'CNT-00');
    $otherTenant = $selector->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-20', 'tenant-b', RateType::Mid, 'CNT-77');

    expect($contract->rate->value)->toBe('0.920000')
        ->and($contract->isContract)->toBeTrue()
        ->and($plain->rate->value)->toBe('0.941200')
        ->and($plain->isContract)->toBeFalse()
        ->and($unknownReference->rate->value)->toBe('0.941200')
        ->and($otherTenant->rate->value)->toBe('0.941200');
});

test('asking for a contract rate that does not exist is refused', function (): void {
    $selector = FxRates::selector([FxRates::rate('EUR', 'CHF', '0.941200', '2026-11-20')]);

    expect(fn () => $selector->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-20', 'tenant-a', RateType::Contract))->toThrow(ExchangeRateMissingException::class);
});

test('the ESTV monthly average refers to the month of the day, not to a five-day window', function (): void {
    $selector = FxRates::selector([FxRates::rate('EUR', 'CHF', '0.945000', '2026-11-01', RateType::VatEstvMonthly, source: RateSource::Estv)]);

    expect($selector->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-27', null, RateType::VatEstvMonthly)->rate->value)->toBe('0.945000')
        ->and(fn () => $selector->select(CurrencyPair::of('EUR', 'CHF'), '2026-12-03', null, RateType::VatEstvMonthly))->toThrow(ExchangeRateMissingException::class);
});

test('a rate type is never mixed with another', function (): void {
    $selector = FxRates::selector([FxRates::rate('EUR', 'CHF', '0.945000', '2026-11-20', RateType::Closing)]);

    expect(fn () => $selector->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-20', null, RateType::Mid))->toThrow(ExchangeRateMissingException::class)
        ->and($selector->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-20', null, RateType::Closing)->rate->value)->toBe('0.945000');
});

test('among official rates of the same day the publisher order decides: manual, ECB, SNB, ESTV', function (): void {
    $snbOnly = FxRates::selector([FxRates::rate('EUR', 'CHF', '0.940000', '2026-11-20', source: RateSource::Snb)]);
    $both = FxRates::selector([
        FxRates::rate('EUR', 'CHF', '0.940000', '2026-11-20', source: RateSource::Snb),
        FxRates::rate('EUR', 'CHF', '0.941200', '2026-11-20', source: RateSource::Ecb),
    ]);
    $newerSnb = FxRates::selector([
        FxRates::rate('EUR', 'CHF', '0.940000', '2026-11-20', source: RateSource::Snb),
        FxRates::rate('EUR', 'CHF', '0.941200', '2026-11-19', source: RateSource::Ecb),
    ]);

    expect($snbOnly->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-20', null)->source)->toBe(RateSource::Snb)
        ->and($both->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-20', null)->rate->value)->toBe('0.941200')
        // A fresher day beats the publisher order.
        ->and($newerSnb->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-20', null)->rate->value)->toBe('0.940000');
});
