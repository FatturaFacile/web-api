<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\Enums\VatRateMethod;
use Modules\Treasury\Currency\Domain\Policies\FxVatPolicyResolver;
use Modules\Treasury\Currency\Domain\Policies\ItalianFxVatPolicy;
use Modules\Treasury\Currency\Domain\Policies\SwissFxVatPolicy;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;
use Tests\Support\Currency\FxRates;

/**
 * The VAT conversion strategy of a country: Switzerland (ESTV monthly average or daily rate, kept one tax period, VAT in CHF)
 * and Italy (Art. 13 c. 4 DPR 633/72: ECB rate of the date or the closest preceding one, VAT also in EUR).
 */
test('the strategy follows the country', function (): void {
    $resolver = new FxVatPolicyResolver;

    expect($resolver->forCountry('CH'))->toBeInstanceOf(SwissFxVatPolicy::class)
        ->and($resolver->forCountry('li'))->toBeInstanceOf(SwissFxVatPolicy::class)
        ->and($resolver->forCountry('IT'))->toBeInstanceOf(ItalianFxVatPolicy::class)
        ->and($resolver->forCountry(null))->toBeInstanceOf(ItalianFxVatPolicy::class);
});

test('Swiss VAT is declared in CHF at the ESTV monthly average or at the daily rate', function (): void {
    $policy = new SwissFxVatPolicy;

    expect($policy->vatCurrency())->toBe('CHF')
        ->and($policy->country())->toBe('CH')
        ->and($policy->rateTypeFor(VatRateMethod::EstvMonthly))->toBe(RateType::VatEstvMonthly)
        ->and($policy->rateTypeFor(VatRateMethod::Daily))->toBe(RateType::Mid)
        ->and($policy->allowedMethods())->toBe([VatRateMethod::EstvMonthly, VatRateMethod::Daily]);
});

test('a Swiss company keeps its VAT method for at least one tax period', function (): void {
    $policy = new SwissFxVatPolicy;
    $now = CarbonImmutable::parse('2026-11-26');

    expect($policy->canChangeMethod(null, $now))->toBeTrue()
        ->and($policy->canChangeMethod(CarbonImmutable::parse('2026-08-26'), $now))->toBeTrue()
        ->and($policy->canChangeMethod(CarbonImmutable::parse('2026-08-27'), $now))->toBeFalse()
        ->and($policy->canChangeMethod(CarbonImmutable::parse('2026-11-01'), $now))->toBeFalse();
});

test('Swiss VAT of a EUR invoice is converted at the ESTV monthly average and shown in CHF', function (): void {
    $selector = FxRates::selector([
        FxRates::rate('EUR', 'CHF', '0.945000', '2026-11-01', RateType::VatEstvMonthly, source: RateSource::Estv),
        FxRates::rate('EUR', 'CHF', '0.941200', '2026-11-20'),
    ]);
    $policy = new SwissFxVatPolicy;

    $resolved = $selector->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-20', null, $policy->rateTypeFor(VatRateMethod::EstvMonthly));

    // 81.00 EUR of VAT × 0.945 = 76.545 → 76.55 CHF (half up)
    expect($resolved->type)->toBe(RateType::VatEstvMonthly)
        ->and($policy->convertVat('81.00', $resolved))->toBe('76.55');
});

test('Swiss VAT with the daily method uses the rate of the document date', function (): void {
    $selector = FxRates::selector([FxRates::rate('EUR', 'CHF', '0.941200', '2026-11-20')]);
    $policy = new SwissFxVatPolicy;

    $resolved = $selector->select(CurrencyPair::of('EUR', 'CHF'), '2026-11-20', null, $policy->rateTypeFor(VatRateMethod::Daily));

    // 81.00 × 0.9412 = 76.2372 → 76.24
    expect($policy->convertVat('81.00', $resolved))->toBe('76.24');
});

test('Italian VAT is converted at the ECB rate of the date or the closest preceding one, in EUR', function (): void {
    $selector = FxRates::selector([FxRates::rate('USD', 'EUR', '0.946970', '2026-11-20')]);
    $policy = new ItalianFxVatPolicy;

    // The invoice is dated Sunday 22 November: the Friday rate is the closest preceding one.
    $resolved = $selector->select(CurrencyPair::of('USD', 'EUR'), '2026-11-22', null, $policy->rateTypeFor(VatRateMethod::Ecb));

    // 220.00 USD of VAT × 0.94697 = 208.3334 → 208.33 EUR
    expect($policy->vatCurrency())->toBe('EUR')
        ->and($resolved->validOn)->toBe('2026-11-20')
        ->and($policy->convertVat('220.00', $resolved))->toBe('208.33')
        ->and($policy->canChangeMethod(CarbonImmutable::parse('2026-11-25'), CarbonImmutable::parse('2026-11-26')))->toBeTrue()
        ->and($policy->allowedMethods())->toBe([VatRateMethod::Ecb, VatRateMethod::Daily]);
});
