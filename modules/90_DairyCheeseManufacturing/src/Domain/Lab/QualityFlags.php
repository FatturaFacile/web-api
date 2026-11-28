<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Lab;

/**
 * Anomaly flags of a laboratory result: positive inhibitors, high somatic cells, high bacterial count, a freezing point
 * above the limit (added water). Thresholds come from config('dairy_cheese.quality_limits').
 */
final class QualityFlags
{
    /**
     * @param  array{scc_cells_ml: int|float, tbc_cfu_ml: int|float, freezing_point_c: float}  $limits
     * @return list<string>
     */
    public static function evaluate(?string $inhibitors, ?int $scc, ?int $tbc, ?float $freezingPoint, array $limits): array
    {
        $flags = [];

        if ($inhibitors === 'positive') {
            $flags[] = 'inhibitor_positive';
        }

        if ($scc !== null && $scc > $limits['scc_cells_ml']) {
            $flags[] = 'scc_high';
        }

        if ($tbc !== null && $tbc > $limits['tbc_cfu_ml']) {
            $flags[] = 'tbc_high';
        }

        if ($freezingPoint !== null && $freezingPoint > $limits['freezing_point_c']) {
            $flags[] = 'freezing_point_anomaly';
        }

        return $flags;
    }
}
