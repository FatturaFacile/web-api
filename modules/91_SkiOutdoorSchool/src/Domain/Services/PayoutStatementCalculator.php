<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Services;

/**
 * Totals of a monthly payout statement (estratto conto compensi): worked hours split between private lessons and group courses, gross fees,
 * secretariat retentions, the withholding of the collaboration regime (on the fee after the retention) and the net payable. Whole cents.
 *
 * @phpstan-type Line array{source_type: string, hours: float, gross_cents: int, retention_cents: int}
 * @phpstan-type Totals array{hours_total: float, private_hours: float, group_hours: float, gross_cents: int, retention_cents: int, withholding_cents: int, net_cents: int}
 */
final class PayoutStatementCalculator
{
    /**
     * @param  list<Line>  $lines
     * @return Totals
     */
    public function totals(array $lines, float $withholdingPercent): array
    {
        $private = 0.0;
        $group = 0.0;
        $gross = 0;
        $retention = 0;

        foreach ($lines as $line) {
            $line['source_type'] === 'LESSON' ? $private += $line['hours'] : $group += $line['hours'];
            $gross += $line['gross_cents'];
            $retention += $line['retention_cents'];
        }

        $afterRetention = $gross - $retention;
        $withholding = (int) round($afterRetention * max(0.0, $withholdingPercent) / 100);

        return [
            'hours_total' => round($private + $group, 2),
            'private_hours' => round($private, 2),
            'group_hours' => round($group, 2),
            'gross_cents' => $gross,
            'retention_cents' => $retention,
            'withholding_cents' => $withholding,
            'net_cents' => $afterRetention - $withholding,
        ];
    }
}
