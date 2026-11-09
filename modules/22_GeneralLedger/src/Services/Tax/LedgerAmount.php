<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

use RoundingMode;

/**
 * The boundary between the domain events, whose payloads still carry some amounts as floats for their outbox
 * serialisation, and the ledger, which only works on bcmath decimal strings. A posting listener converts each incoming
 * value once here and then builds its entry with Decimal only: no arithmetic ever runs on a float.
 */
final class LedgerAmount
{
    /**
     * A value of an event, a model or a stored line as a decimal string of the given scale (4 for journal amounts, as
     * `journal_lines` stores them; 6 for exchange rates). A float is formatted at that scale, a string keeps every
     * digit up to it, rounding half away from zero like the posting service.
     */
    public static function of(float|int|string|null $value, int $scale = 4): string
    {
        $text = is_float($value) ? number_format($value, $scale, '.', '') : trim((string) ($value ?? '0'));

        return bcround(Decimal::normalize($text === '' ? '0' : $text), $scale, RoundingMode::HalfAwayFromZero);
    }

    /**
     * An exchange rate as a 6-decimal string; a missing, zero or negative rate means the document is in the accounting
     * currency, so 1.
     */
    public static function rate(float|int|string|null $value): string
    {
        $rate = self::of($value, 6);

        return Decimal::isPositive($rate) ? $rate : '1.000000';
    }
}
