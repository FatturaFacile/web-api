<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Calculation;

/**
 * INPS Gestione Separata rate of a year: the rate of the latest year not after the payment year.
 */
final class InpsRate
{
    /**
     * @param  array<int|string, string>  $rates  year => percent
     */
    public static function forYear(array $rates, int $year): string
    {
        krsort($rates);

        foreach ($rates as $from => $rate) {
            if ((int) $from <= $year) {
                return $rate;
            }
        }

        // Before the first known year the earliest rate applies.
        return (string) end($rates);
    }
}
