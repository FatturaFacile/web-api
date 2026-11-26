<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Services;

use Modules\Treasury\Currency\Domain\Enums\FxDirection;
use Modules\Treasury\Currency\Domain\Enums\FxResult;
use Modules\Treasury\Currency\Domain\Support\Dec;
use Modules\Treasury\Currency\Domain\ValueObjects\ExchangeRateValue;

/**
 * Realised exchange difference of an item settled (receipt or payment), in the functional currency, always against the rate
 * frozen on the document: Δ = settled currency amount × (payment rate − document rate). A receivable gains when Δ is positive,
 * a payable when it is negative. A partial settlement carries the difference of the settled portion only; a document in the
 * functional currency (rate 1) carries none. A revaluation is reversed at the start of the next period, so a payment after it
 * is measured against the original document rate and nothing is counted twice.
 */
final class RealizedFxCalculator
{
    /**
     * @param  string  $settledAmount  amount in the document currency applied to the item (negative for a credit note)
     * @return array{difference: string, effect: string, result: FxResult, document_value: string, payment_value: string}
     */
    public function forSettlement(FxDirection $direction, string $settledAmount, ExchangeRateValue $documentRate, ExchangeRateValue $paymentRate): array
    {
        $documentValue = Dec::money(Dec::mul($settledAmount, $documentRate->value));
        $paymentValue = Dec::money(Dec::mul($settledAmount, $paymentRate->value));

        return $this->outcome($direction, Dec::sub($paymentValue, $documentValue), $documentValue, $paymentValue);
    }

    /**
     * A partial settlement of an item: the difference of the settled portion, pro rata of what the full item would carry.
     *
     * @return array{difference: string, effect: string, result: FxResult, document_value: string, payment_value: string}
     */
    public function forPartialSettlement(FxDirection $direction, string $itemAmount, string $settledAmount, ExchangeRateValue $documentRate, ExchangeRateValue $paymentRate): array
    {
        if (Dec::isZero($itemAmount)) {
            return $this->outcome($direction, '0', '0.00', '0.00');
        }

        $fullDocument = Dec::mul($itemAmount, $documentRate->value);
        $fullPayment = Dec::mul($itemAmount, $paymentRate->value);
        $fraction = Dec::div($settledAmount, $itemAmount);
        $documentValue = Dec::money(Dec::mul($fullDocument, $fraction));
        $paymentValue = Dec::money(Dec::mul($fullPayment, $fraction));

        return $this->outcome($direction, Dec::sub($paymentValue, $documentValue), $documentValue, $paymentValue);
    }

    /**
     * A document in one currency settled with money in another (a EUR invoice paid in CHF): the payment is converted at the
     * rate of the payment day by the caller, and the difference is the functional counter-value received or paid minus the
     * book value of the settled part of the document.
     *
     * @param  string  $documentAmountSettled  document-currency amount cleared
     * @param  string  $paymentFunctionalAmount  functional counter-value of the money that moved, at the payment-day rate
     * @return array{difference: string, effect: string, result: FxResult, document_value: string, payment_value: string}
     */
    public function forCrossCurrencySettlement(FxDirection $direction, string $documentAmountSettled, ExchangeRateValue $documentRate, string $paymentFunctionalAmount): array
    {
        $documentValue = Dec::money(Dec::mul($documentAmountSettled, $documentRate->value));
        $paymentValue = Dec::money($paymentFunctionalAmount);

        return $this->outcome($direction, Dec::sub($paymentValue, $documentValue), $documentValue, $paymentValue);
    }

    /**
     * `difference` is the raw Δ (payment value minus document value, each converted to the cent so the ledger entry balances);
     * `effect` is its sign on the result of the company: positive for a gain, negative for a loss (a receivable gains with a
     * positive Δ, a payable with a negative one).
     *
     * @return array{difference: string, effect: string, result: FxResult, document_value: string, payment_value: string}
     */
    private function outcome(FxDirection $direction, string $delta, string $documentValue, string $paymentValue): array
    {
        $delta = Dec::money($delta);
        $effect = $direction === FxDirection::Receivable ? $delta : Dec::money(Dec::neg($delta));
        $result = Dec::isZero($effect) ? FxResult::Neutral : (Dec::isPositive($effect) ? FxResult::Gain : FxResult::Loss);

        return ['difference' => $delta, 'effect' => $effect, 'result' => $result, 'document_value' => $documentValue, 'payment_value' => $paymentValue];
    }
}
