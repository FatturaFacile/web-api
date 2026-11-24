<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Collection;
use Modules\Inventory\Models\WarehouseBin;

/**
 * Orders the stops of a pick list as an S-shaped walk: zone by zone, up one aisle and down the next, skipping aisles
 * with nothing to pick. Bins with an explicit `pick_sequence` come first in that order, for warehouses that mapped
 * their route by hand. The warehouse stores no distances, so this reduces walking without proving a shortest path.
 */
final class PickRouteOptimizer
{
    /**
     * @template TStop of array{warehouse_bin_id: string}
     *
     * @param  list<TStop>  $stops
     * @param  Collection<string, WarehouseBin>  $bins
     * @return list<TStop>
     */
    public function sequence(array $stops, Collection $bins): array
    {
        $location = static function (array $stop) use ($bins): array {
            $bin = $bins->get($stop['warehouse_bin_id']);

            return [
                'manual' => $bin?->pick_sequence,
                'zone' => (string) $bin?->rack?->aisle?->zone?->code,
                'aisle' => (string) $bin?->rack?->aisle?->code,
                'rack' => (string) $bin?->rack?->code,
                'bin' => (string) $bin?->code,
            ];
        };

        $aisles = collect($stops)
            ->map(static fn (array $stop): string => $location($stop)['zone']."\0".$location($stop)['aisle'])
            ->unique()
            ->sort(SORT_NATURAL)
            ->values()
            ->flip();

        usort($stops, static function (array $left, array $right) use ($location, $aisles): int {
            $a = $location($left);
            $b = $location($right);

            if ($a['manual'] !== null || $b['manual'] !== null) {
                return [$a['manual'] ?? PHP_INT_MAX] <=> [$b['manual'] ?? PHP_INT_MAX] ?: strnatcmp($a['bin'], $b['bin']);
            }

            $leftAisle = $aisles[$a['zone']."\0".$a['aisle']];
            $rightAisle = $aisles[$b['zone']."\0".$b['aisle']];

            if ($leftAisle !== $rightAisle) {
                return $leftAisle <=> $rightAisle;
            }

            $walk = strnatcmp($a['rack'], $b['rack']) ?: strnatcmp($a['bin'], $b['bin']);

            return $leftAisle % 2 === 0 ? $walk : -$walk;
        });

        return $stops;
    }
}
