<?php

declare(strict_types=1);

use Modules\Treasury\Currency\Domain\Enums\FxDirection;
use Modules\Treasury\Currency\Domain\Enums\FxResult;
use Modules\Treasury\Currency\Domain\Services\CreditNoteRatePolicy;
use Modules\Treasury\Currency\Domain\Services\RealizedFxCalculator;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;
use Modules\Treasury\Currency\Domain\ValueObjects\ExchangeRateValue;
use Tests\Support\Currency\FxRates;

/**
 * Realised exchange difference of a settlement against the rate frozen on the document: Δ = settled amount × (payment rate −
 * document rate), in the functional currency. A receivable gains with a positive Δ, a payable with a negative one.
 */
function fxRate(string $rate): ExchangeRateValue
{
    return ExchangeRateValue::of($rate);
}

test('a receivable collected at a higher rate is a gain', function (): void {
    $result = (new RealizedFxCalculator)->forSettlement(FxDirection::Receivable, '1000.00', fxRate('0.800000'), fxRate('0.833333'));

    expect($result['document_value'])->toBe('800.00')
        ->and($result['payment_value'])->toBe('833.33')
        ->and($result['difference'])->toBe('33.33')
        ->and($result['effect'])->toBe('33.33')
        ->and($result['result'])->toBe(FxResult::Gain);
});

test('a receivable collected at a lower rate is a loss', function (): void {
    $result = (new RealizedFxCalculator)->forSettlement(FxDirection::Receivable, '1000.00', fxRate('0.800000'), fxRate('0.780000'));

    expect($result['difference'])->toBe('-20.00')
        ->and($result['effect'])->toBe('-20.00')
        ->and($result['result'])->toBe(FxResult::Loss);
});

test('a payable paid at a higher rate is a loss and at a lower rate a gain', function (): void {
    $calculator = new RealizedFxCalculator;

    $dearer = $calculator->forSettlement(FxDirection::Payable, '1000.00', fxRate('0.800000'), fxRate('0.833333'));
    $cheaper = $calculator->forSettlement(FxDirection::Payable, '1000.00', fxRate('0.800000'), fxRate('0.780000'));

    expect($dearer['difference'])->toBe('33.33')
        ->and($dearer['effect'])->toBe('-33.33')
        ->and($dearer['result'])->toBe(FxResult::Loss)
        ->and($cheaper['difference'])->toBe('-20.00')
        ->and($cheaper['effect'])->toBe('20.00')
        ->and($cheaper['result'])->toBe(FxResult::Gain);
});

test('a document in the functional currency carries no difference', function (): void {
    $result = (new RealizedFxCalculator)->forSettlement(FxDirection::Receivable, '1000.00', ExchangeRateValue::one(), ExchangeRateValue::one());

    expect($result['difference'])->toBe('0.00')
        ->and($result['effect'])->toBe('0.00')
        ->and($result['result'])->toBe(FxResult::Neutral);
});

test('a partial settlement carries the difference of the settled portion, and the portions add up to the whole', function (): void {
    $calculator = new RealizedFxCalculator;
    $doc = fxRate('0.800000');
    $payment = fxRate('0.833333');

    $first = $calculator->forPartialSettlement(FxDirection::Receivable, '1000.00', '400.00', $doc, $payment);
    $second = $calculator->forPartialSettlement(FxDirection::Receivable, '1000.00', '600.00', $doc, $payment);
    $whole = $calculator->forSettlement(FxDirection::Receivable, '1000.00', $doc, $payment);

    expect($first['document_value'])->toBe('320.00')
        ->and($first['difference'])->toBe('13.33')
        ->and($second['document_value'])->toBe('480.00')
        ->and($second['difference'])->toBe('20.00')
        ->and(bcadd($first['difference'], $second['difference'], 2))->toBe($whole['difference']);
});

test('a partial settlement of an empty item is neutral', function (): void {
    $result = (new RealizedFxCalculator)->forPartialSettlement(FxDirection::Receivable, '0', '0', fxRate('0.8'), fxRate('0.9'));

    expect($result['result'])->toBe(FxResult::Neutral);
});

test('a credit note uses the rate of its linked invoice, otherwise the rate of its date', function (): void {
    $policy = new CreditNoteRatePolicy;
    $dateRate = FxRates::selector([FxRates::rate('USD', 'EUR', '0.833333', '2026-11-20')])->select(CurrencyPair::of('USD', 'EUR'), '2026-11-20', null);

    expect($policy->rate('0.800000', $dateRate)->value)->toBe('0.800000')
        ->and($policy->rate(null, $dateRate)->value)->toBe('0.833333')
        ->and($policy->rate('', $dateRate)->value)->toBe('0.833333');
});

test('the difference of a credit note is measured against the rate of the invoice it corrects', function (): void {
    $dateRate = FxRates::selector([FxRates::rate('USD', 'EUR', '0.833333', '2026-11-20')])->select(CurrencyPair::of('USD', 'EUR'), '2026-11-20', null);
    $documentRate = (new CreditNoteRatePolicy)->rate('0.800000', $dateRate);

    // A 200 USD credit note settled (refunded) at 0.833333: the document value is -160.00, the money moved -166.67.
    $result = (new RealizedFxCalculator)->forSettlement(FxDirection::Receivable, '-200.00', $documentRate, $dateRate->rate);

    expect($result['document_value'])->toBe('-160.00')
        ->and($result['payment_value'])->toBe('-166.67')
        ->and($result['difference'])->toBe('-6.67')
        ->and($result['result'])->toBe(FxResult::Loss);
});

test('an invoice in one currency paid in another is measured on the functional counter-value', function (): void {
    // A 1000 EUR invoice frozen at 0.95 CHF (950.00) is paid with 960.00 CHF.
    $gain = (new RealizedFxCalculator)->forCrossCurrencySettlement(FxDirection::Receivable, '1000.00', fxRate('0.950000'), '960.00');
    $loss = (new RealizedFxCalculator)->forCrossCurrencySettlement(FxDirection::Receivable, '1000.00', fxRate('0.950000'), '940.50');
    $payable = (new RealizedFxCalculator)->forCrossCurrencySettlement(FxDirection::Payable, '1000.00', fxRate('0.950000'), '940.50');

    expect($gain['difference'])->toBe('10.00')
        ->and($gain['result'])->toBe(FxResult::Gain)
        ->and($loss['difference'])->toBe('-9.50')
        ->and($loss['result'])->toBe(FxResult::Loss)
        ->and($payable['result'])->toBe(FxResult::Gain)
        ->and($payable['effect'])->toBe('9.50');
});
