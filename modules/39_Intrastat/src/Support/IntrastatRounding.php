<?php

declare(strict_types=1);

namespace Modules\Intrastat\Support;

/**
 * Rounding of the amounts and quantities of the INTRA lists.
 *
 * Source: ADM, determinazione prot. n. 493869/RU del 23 dicembre 2021, Allegato XI, Titolo II Sezione 1 notes 4 and 5,
 * Sezione 3 note 2, Titolo III Sezione 1 notes 4 and 5, Sezione 3 note 3:
 * https://www.adm.gov.it/portale/documents/20182/23456462/Allegato+11+Istruzioni+per+l%27uso+e+la+compilazione+degli+elenchi+riepilogativi.pdf/e7887bad-97cc-97bc-4761-6e37b1ec5b39?t=1640615553784
 *
 * - Amounts and statistical value in whole euro: down when the fraction is below 0.50 euro, up otherwise; an amount
 *   below 0.50 euro is always reported as 1 euro.
 * - Net mass and supplementary units rounded to the unit; a net mass below 1 kg is reported as 1.
 */
final class IntrastatRounding
{
    /**
     * @param  numeric-string|int|float|null  $amount
     */
    public static function euro(string|int|float|null $amount): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        $value = abs((float) $amount);

        if ($value === 0.0) {
            return 0;
        }

        return $value < 0.5 ? 1 : (int) round($value, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * @param  numeric-string|int|float|null  $kilograms
     */
    public static function netMass(string|int|float|null $kilograms): ?int
    {
        if ($kilograms === null || $kilograms === '') {
            return null;
        }

        $value = abs((float) $kilograms);

        if ($value === 0.0) {
            return 0;
        }

        return $value < 1 ? 1 : (int) round($value, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * @param  numeric-string|int|float|null  $units
     */
    public static function units(string|int|float|null $units): ?int
    {
        if ($units === null || $units === '') {
            return null;
        }

        return (int) round(abs((float) $units), 0, PHP_ROUND_HALF_UP);
    }
}
