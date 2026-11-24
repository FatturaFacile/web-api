<?php

declare(strict_types=1);

namespace Modules\Projects\Support;

/**
 * Arithmetic of a time and materials draft (Mod 081): the markup (ricarico) on recharged costs and the billing cap.
 *
 * The markup applies to recharged costs (expenses, supplier bills), never to hours, which are billed at the rate card.
 * The cap bounds everything ever billed under the contract: a line that would cross it is cut to what is left and the
 * lines after it are dropped.
 */
final class TimeAndMaterialsCalculator
{
    /**
     * Recharged amount of a cost with the markup percentage added.
     */
    public static function withMarkup(string $cost, string $markupPercent): string
    {
        return Money::amount(Money::mul($cost, Money::add(1, Money::div($markupPercent, 100))));
    }

    /**
     * Applies the cap to line amounts in order. Returns the billed amount of every line (same keys, possibly zero) and
     * the amount lost to the cap.
     *
     * @param  array<array-key, string>  $amounts
     * @return array{billed: array<array-key, string>, capped_out: string}
     */
    public static function applyCap(array $amounts, ?string $capAmount, string $alreadyBilled): array
    {
        if ($capAmount === null) {
            return ['billed' => array_map(Money::amount(...), $amounts), 'capped_out' => '0.00'];
        }

        $room = Money::max(Money::sub($capAmount, $alreadyBilled), 0);
        $billed = [];
        $lost = Money::of(0);

        foreach ($amounts as $key => $amount) {
            $take = Money::min($amount, $room);
            $billed[$key] = Money::amount($take);
            $room = Money::sub($room, $take);
            $lost = Money::add($lost, Money::sub($amount, $take));
        }

        return ['billed' => $billed, 'capped_out' => Money::amount($lost)];
    }
}
