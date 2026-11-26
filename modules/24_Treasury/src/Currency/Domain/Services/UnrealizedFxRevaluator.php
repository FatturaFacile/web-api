<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Services;

use Modules\Treasury\Currency\Domain\Enums\FxResult;
use Modules\Treasury\Currency\Domain\Enums\RevaluationLineType;
use Modules\Treasury\Currency\Domain\Enums\UnrealizedGainTreatment;
use Modules\Treasury\Currency\Domain\Support\Dec;
use Modules\Treasury\Currency\Domain\ValueObjects\ExchangeRateValue;

/**
 * Unrealised revaluation of the open monetary items and currency accounts at a period end:
 * Δ = currency balance × closing rate − functional book balance. Balances are signed (receivables and bank positive, payables
 * negative), so a positive Δ is always a gain. Losses are always booked (imparity principle); a gain follows the company
 * choice: recognised, booked to a provision/reserve account, or left out. The caller posts the lines and reverses them on the
 * first day of the next period.
 */
final class UnrealizedFxRevaluator
{
    /**
     * @param  array{line_type: RevaluationLineType, currency: string, reference: ?string, account_id?: ?string, currency_balance: string, book_value: string}  $position
     * @return array{line_type: RevaluationLineType, currency: string, reference: ?string, account_id: ?string, currency_balance: string, book_value: string, closing_rate: string, revalued_value: string, difference: string, result: FxResult, treatment: UnrealizedGainTreatment}
     */
    public function revalue(array $position, ExchangeRateValue $closingRate, UnrealizedGainTreatment $gainTreatment): array
    {
        $balance = Dec::norm($position['currency_balance']);
        $book = Dec::money($position['book_value']);
        $revalued = Dec::money(Dec::mul($balance, $closingRate->value));
        $difference = Dec::money(Dec::sub($revalued, $book));
        $result = Dec::isZero($difference) ? FxResult::Neutral : (Dec::isPositive($difference) ? FxResult::Gain : FxResult::Loss);

        return [
            'line_type' => $position['line_type'],
            'currency' => strtoupper($position['currency']),
            'reference' => $position['reference'],
            'account_id' => $position['account_id'] ?? null,
            'currency_balance' => Dec::money($balance),
            'book_value' => $book,
            'closing_rate' => $closingRate->value,
            'revalued_value' => $revalued,
            'difference' => $difference,
            'result' => $result,
            'treatment' => $result === FxResult::Gain ? $gainTreatment : UnrealizedGainTreatment::Recognise,
        ];
    }

    /**
     * Totals of a set of revalued lines: gains and losses (positive amounts), their net, the net gain that stays out of the
     * income statement (provision or suppressed) and the net gain recognised, which Italian companies keep as a
     * non-distributable reserve (Art. 2426 n. 8-bis c.c.).
     *
     * @param  list<array{difference: string, result: FxResult, treatment: UnrealizedGainTreatment}>  $lines
     * @return array{total_gain: string, total_loss: string, net_result: string, recognised_gain: string, deferred_gain: string, reserve_amount: string}
     */
    public function totals(array $lines): array
    {
        $gain = '0';
        $loss = '0';
        $recognised = '0';
        $deferred = '0';

        foreach ($lines as $line) {
            if ($line['result'] === FxResult::Gain) {
                $gain = Dec::add($gain, $line['difference']);
                $line['treatment'] === UnrealizedGainTreatment::Recognise
                    ? $recognised = Dec::add($recognised, $line['difference'])
                    : $deferred = Dec::add($deferred, $line['difference']);
            } elseif ($line['result'] === FxResult::Loss) {
                $loss = Dec::add($loss, Dec::abs($line['difference']));
            }
        }

        $net = Dec::sub($gain, $loss);
        $netRecognised = Dec::sub($recognised, $loss);

        return [
            'total_gain' => Dec::money($gain),
            'total_loss' => Dec::money($loss),
            'net_result' => Dec::money($net),
            'recognised_gain' => Dec::money($recognised),
            'deferred_gain' => Dec::money($deferred),
            'reserve_amount' => Dec::isPositive($netRecognised) ? Dec::money($netRecognised) : '0.00',
        ];
    }
}
