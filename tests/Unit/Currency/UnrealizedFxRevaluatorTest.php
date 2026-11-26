<?php

declare(strict_types=1);

use Modules\Treasury\Currency\Domain\Enums\FxDirection;
use Modules\Treasury\Currency\Domain\Enums\FxResult;
use Modules\Treasury\Currency\Domain\Enums\RevaluationLineType;
use Modules\Treasury\Currency\Domain\Enums\UnrealizedGainTreatment;
use Modules\Treasury\Currency\Domain\Services\RealizedFxCalculator;
use Modules\Treasury\Currency\Domain\Services\UnrealizedFxRevaluator;
use Modules\Treasury\Currency\Domain\Support\Dec;
use Modules\Treasury\Currency\Domain\ValueObjects\ExchangeRateValue;

/**
 * Unrealised revaluation at a period end: Δ = currency balance × closing rate − functional book balance. Balances are signed
 * (payables negative), so a positive Δ is a gain; losses are always booked, gains follow the company treatment.
 *
 * @return array{line_type: RevaluationLineType, currency: string, reference: ?string, account_id: ?string, currency_balance: string, book_value: string}
 */
function fxPosition(RevaluationLineType $type, string $currency, string $balance, string $book): array
{
    return ['line_type' => $type, 'currency' => $currency, 'reference' => null, 'account_id' => null, 'currency_balance' => $balance, 'book_value' => $book];
}

test('an open receivable worth more at the closing rate is an unrealised gain', function (): void {
    $line = (new UnrealizedFxRevaluator)->revalue(fxPosition(RevaluationLineType::Receivable, 'USD', '1220.00', '976.00'), ExchangeRateValue::of('0.833333'), UnrealizedGainTreatment::Recognise);

    expect($line['revalued_value'])->toBe('1016.67')
        ->and($line['difference'])->toBe('40.67')
        ->and($line['result'])->toBe(FxResult::Gain)
        ->and($line['treatment'])->toBe(UnrealizedGainTreatment::Recognise)
        ->and($line['closing_rate'])->toBe('0.833333');
});

test('an open payable worth more at the closing rate is an unrealised loss', function (): void {
    // The payable is a negative balance: -500 USD booked at -400.00, now -416.67.
    $line = (new UnrealizedFxRevaluator)->revalue(fxPosition(RevaluationLineType::Payable, 'USD', '-500.00', '-400.00'), ExchangeRateValue::of('0.833333'), UnrealizedGainTreatment::Provision);

    expect($line['revalued_value'])->toBe('-416.67')
        ->and($line['difference'])->toBe('-16.67')
        ->and($line['result'])->toBe(FxResult::Loss)
        ->and($line['treatment'])->toBe(UnrealizedGainTreatment::Recognise);
});

test('a foreign-currency bank account is revalued like an asset', function (): void {
    $line = (new UnrealizedFxRevaluator)->revalue(fxPosition(RevaluationLineType::Bank, 'EUR', '2500.00', '2353.00'), ExchangeRateValue::of('0.950000'), UnrealizedGainTreatment::Recognise);

    expect($line['revalued_value'])->toBe('2375.00')
        ->and($line['difference'])->toBe('22.00')
        ->and($line['result'])->toBe(FxResult::Gain);
});

test('a position already at the closing rate is neutral', function (): void {
    $line = (new UnrealizedFxRevaluator)->revalue(fxPosition(RevaluationLineType::Receivable, 'USD', '1000.00', '800.00'), ExchangeRateValue::of('0.800000'), UnrealizedGainTreatment::Recognise);

    expect($line['difference'])->toBe('0.00')->and($line['result'])->toBe(FxResult::Neutral);
});

test('a gain follows the company treatment while a loss is always recognised', function (): void {
    $revaluator = new UnrealizedFxRevaluator;
    $closing = ExchangeRateValue::of('0.833333');

    $provision = $revaluator->revalue(fxPosition(RevaluationLineType::Receivable, 'USD', '1220.00', '976.00'), $closing, UnrealizedGainTreatment::Provision);
    $suppress = $revaluator->revalue(fxPosition(RevaluationLineType::Receivable, 'USD', '1220.00', '976.00'), $closing, UnrealizedGainTreatment::Suppress);
    $loss = $revaluator->revalue(fxPosition(RevaluationLineType::Payable, 'USD', '-500.00', '-400.00'), $closing, UnrealizedGainTreatment::Suppress);

    expect($provision['treatment'])->toBe(UnrealizedGainTreatment::Provision)
        ->and($suppress['treatment'])->toBe(UnrealizedGainTreatment::Suppress)
        ->and($loss['treatment'])->toBe(UnrealizedGainTreatment::Recognise);
});

test('the totals split gains, losses, the deferred gain and the reserve of a recognised net gain', function (): void {
    $revaluator = new UnrealizedFxRevaluator;
    $closing = ExchangeRateValue::of('0.833333');
    $positions = [
        fxPosition(RevaluationLineType::Receivable, 'USD', '1220.00', '976.00'),
        fxPosition(RevaluationLineType::Payable, 'USD', '-500.00', '-400.00'),
        fxPosition(RevaluationLineType::Bank, 'USD', '60.00', '48.00'),
    ];

    $recognise = array_map(static fn (array $position): array => $revaluator->revalue($position, $closing, UnrealizedGainTreatment::Recognise), $positions);
    $provision = array_map(static fn (array $position): array => $revaluator->revalue($position, $closing, UnrealizedGainTreatment::Provision), $positions);

    // Gains: 40.67 and 2.00 (60 × 0.833333 = 49.99998 → 50.00 − 48.00); loss: 16.67.
    $totalsRecognise = $revaluator->totals($recognise);
    $totalsProvision = $revaluator->totals($provision);

    expect($totalsRecognise['total_gain'])->toBe('42.67')
        ->and($totalsRecognise['total_loss'])->toBe('16.67')
        ->and($totalsRecognise['net_result'])->toBe('26.00')
        ->and($totalsRecognise['recognised_gain'])->toBe('42.67')
        ->and($totalsRecognise['deferred_gain'])->toBe('0.00')
        ->and($totalsRecognise['reserve_amount'])->toBe('26.00')
        ->and($totalsProvision['recognised_gain'])->toBe('0.00')
        ->and($totalsProvision['deferred_gain'])->toBe('42.67')
        ->and($totalsProvision['reserve_amount'])->toBe('0.00');
});

test('revaluation, reversal and payment count the exchange difference once', function (): void {
    $revaluator = new UnrealizedFxRevaluator;
    $realized = new RealizedFxCalculator;

    // A 1000 USD receivable frozen at 0.80 (book 800.00); at the month end USD is 0.85; the customer pays at 0.90.
    $unrealized = $revaluator->revalue(fxPosition(RevaluationLineType::Receivable, 'USD', '1000.00', '800.00'), ExchangeRateValue::of('0.850000'), UnrealizedGainTreatment::Recognise);
    $reversal = Dec::money(Dec::neg($unrealized['difference']));

    // The revaluation is reversed on day one, so the payment is measured against the ORIGINAL document rate.
    $payment = $realized->forSettlement(FxDirection::Receivable, '1000.00', ExchangeRateValue::of('0.800000'), ExchangeRateValue::of('0.900000'));
    // Measured against the revalued rate it would be 50.00 and the revaluation would be counted twice.
    $wrong = $realized->forSettlement(FxDirection::Receivable, '1000.00', ExchangeRateValue::of('0.850000'), ExchangeRateValue::of('0.900000'));

    $cumulative = Dec::money(Dec::add(Dec::add($unrealized['difference'], $reversal), $payment['effect']));

    expect($unrealized['difference'])->toBe('50.00')
        ->and($reversal)->toBe('-50.00')
        ->and($payment['effect'])->toBe('100.00')
        ->and($wrong['effect'])->toBe('50.00')
        // The result over the life of the receivable is the economic one: 900.00 received − 800.00 booked.
        ->and($cumulative)->toBe('100.00');
});
