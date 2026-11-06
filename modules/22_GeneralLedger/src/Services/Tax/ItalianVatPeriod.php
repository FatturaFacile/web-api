<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

use DateTimeInterface;

/**
 * Period helpers for the Italian periodic VAT (LIPE and F24). Port of FatturaFacile.Application.Taxation.ItalianVatPeriod.
 */
final class ItalianVatPeriod
{
    public static function isMonthly(DateTimeInterface $from, DateTimeInterface $to): bool
    {
        return $from->format('Y-m') === $to->format('Y-m');
    }

    public static function quarter(DateTimeInterface $date): int
    {
        return (int) floor(((int) $date->format('n') - 1) / 3) + 1;
    }

    /**
     * Gets the F24 tax code (codice tributo) of the VAT payment: 6001-6012 monthly, 6031-6033 quarterly; the fourth
     * quarter of ordinary quarterly taxpayers is paid with the annual balance (6099).
     */
    public static function f24TaxCode(DateTimeInterface $from, DateTimeInterface $to): string
    {
        if (self::isMonthly($from, $to)) {
            return sprintf('60%02d', (int) $from->format('n'));
        }

        $quarter = self::quarter($from);

        return $quarter === 4 ? '6099' : sprintf('603%d', $quarter);
    }
}
