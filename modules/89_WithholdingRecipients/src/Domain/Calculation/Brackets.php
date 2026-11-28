<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Calculation;

use Modules\WithholdingRecipients\Domain\ValueObjects\Money;

/**
 * Bracket tables: marginal (progressive IRPEF, FIRR) or flat-by-bracket (Swiss source tax on the daily amount).
 */
final class Brackets
{
    /**
     * Tax of a cumulative amount, each slice at the rate of its bracket.
     *
     * @param  list<array{up_to: string|null, rate: string}>  $brackets
     */
    public static function marginal(Money $amount, array $brackets): Money
    {
        $tax = Money::zero();
        $lower = Money::zero();

        foreach ($brackets as $bracket) {
            if (! $amount->greaterThan($lower)) {
                break;
            }

            $upper = $bracket['up_to'] === null ? $amount : Money::of($bracket['up_to'])->min($amount);
            $tax = $tax->plus($upper->minus($lower)->percent($bracket['rate']));
            $lower = Money::of($bracket['up_to'] ?? 0)->max($lower);

            if ($bracket['up_to'] === null) {
                break;
            }
        }

        return $tax;
    }

    /**
     * Rate (percent) of the first bracket that contains the amount.
     *
     * @param  list<array{up_to: string|null, rate: string}>  $brackets
     */
    public static function rateFor(Money $amount, array $brackets): string
    {
        foreach ($brackets as $bracket) {
            if ($bracket['up_to'] === null || $amount->lessThanOrEqual(Money::of($bracket['up_to']))) {
                return $bracket['rate'];
            }
        }

        return '0';
    }
}
