<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Services;

use Illuminate\Support\Collection;
use Modules\Beekeeping\Models\Apiary;

/**
 * Finds the apiaries whose foraging circles (3 km by default) overlap: they share flora and varroa pressure.
 */
final class ForagingOverlapService
{
    /**
     * @param  Collection<int, Apiary>  $apiaries
     * @return list<array{apiary_id: string, other_apiary_id: string, distance_m: float, overlap_percent: float}>
     */
    public function overlaps(Collection $apiaries): array
    {
        $located = $apiaries->filter(static fn (Apiary $apiary): bool => $apiary->hasCoordinates())->values();
        $pairs = [];

        for ($i = 0; $i < $located->count(); $i++) {
            for ($j = $i + 1; $j < $located->count(); $j++) {
                $a = $located[$i]->geoPoint();
                $b = $located[$j]->geoPoint();

                if ($a !== null && $b !== null && $a->overlaps($b)) {
                    $pairs[] = [
                        'apiary_id' => $located[$i]->id,
                        'other_apiary_id' => $located[$j]->id,
                        'distance_m' => round($a->distanceTo($b), 1),
                        'overlap_percent' => $a->overlapPercent($b),
                    ];
                }
            }
        }

        return $pairs;
    }
}
