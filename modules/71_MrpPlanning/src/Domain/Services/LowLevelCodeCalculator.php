<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Services;

use Modules\MrpPlanning\Domain\Exceptions\BomCycleException;
use Modules\MrpPlanning\Domain\ValueObjects\ItemPlanningData;

/**
 * Low-level code (LLC): the deepest level at which an item appears in any bill of materials, so that an item is netted
 * only after every parent has been planned and its dependent demand exploded. Finished goods are level 0.
 */
final class LowLevelCodeCalculator
{
    /**
     * @param  array<string, ItemPlanningData>  $items
     * @return array<string, int>
     *
     * @throws BomCycleException when the bills of materials are not a DAG
     */
    public function calculate(array $items): array
    {
        $this->assertAcyclic($items);

        $levels = array_map(static fn (): int => 0, $items);
        $inDegree = array_map(static fn (): int => 0, $items);

        foreach ($items as $item) {
            foreach ($this->children($item, $items) as $child) {
                $inDegree[$child]++;
            }
        }

        $queue = array_keys(array_filter($inDegree, static fn (int $degree): bool => $degree === 0));
        while ($queue !== []) {
            $id = array_shift($queue);
            foreach ($this->children($items[$id], $items) as $child) {
                $levels[$child] = max($levels[$child], $levels[$id] + 1);
                if (--$inDegree[$child] === 0) {
                    $queue[] = $child;
                }
            }
        }

        return $levels;
    }

    /**
     * @param  array<string, ItemPlanningData>  $items
     *
     * @throws BomCycleException
     */
    private function assertAcyclic(array $items): void
    {
        $state = [];
        $path = [];

        $visit = function (string $id) use (&$visit, &$state, &$path, $items): void {
            $state[$id] = 1;
            $path[] = $id;
            foreach ($this->children($items[$id], $items) as $child) {
                if (($state[$child] ?? 0) === 1) {
                    $start = array_search($child, $path, true);
                    throw new BomCycleException([...array_slice($path, (int) $start), $child]);
                }
                if (($state[$child] ?? 0) === 0) {
                    $visit($child);
                }
            }
            array_pop($path);
            $state[$id] = 2;
        };

        foreach (array_keys($items) as $id) {
            if (($state[$id] ?? 0) === 0) {
                $visit((string) $id);
            }
        }
    }

    /**
     * Distinct planned children of an item.
     *
     * @param  array<string, ItemPlanningData>  $items
     * @return list<string>
     */
    private function children(ItemPlanningData $item, array $items): array
    {
        $ids = [];
        foreach ($item->components as $line) {
            if (isset($items[$line->childItemId])) {
                $ids[$line->childItemId] = $line->childItemId;
            }
        }

        return array_values($ids);
    }
}
