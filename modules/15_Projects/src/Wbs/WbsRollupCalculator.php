<?php

declare(strict_types=1);

namespace Modules\Projects\Wbs;

use Modules\Projects\Support\Money;

/**
 * Bottom-up roll-up of a WBS tree, at any depth.
 *
 * Only leaves carry their own values: a node with children takes the sum of its children, whatever was typed on it, so
 * nothing is counted twice at any level. Percent complete of a summary node is weighted by planned hours, falling back
 * to planned cost and finally to a plain average when no weight exists. Start and end are the earliest start and the
 * latest end of the children.
 *
 * @phpstan-type NodeInput array{id: string, parent_id: string|null, planned_hours?: string|int|float|null, actual_hours?: string|int|float|null, planned_cost?: string|int|float|null, actual_cost?: string|int|float|null, planned_revenue?: string|int|float|null, percent_complete?: string|int|float|null, start_date?: string|null, end_date?: string|null}
 * @phpstan-type Rolled array{planned_hours: string, actual_hours: string, planned_cost: string, actual_cost: string, planned_revenue: string, percent_complete: string, start_date: string|null, end_date: string|null, is_leaf: bool}
 */
final class WbsRollupCalculator
{
    private const array Amounts = ['planned_hours', 'actual_hours', 'planned_cost', 'actual_cost', 'planned_revenue'];

    /**
     * @param  list<NodeInput>  $nodes
     * @return array{nodes: array<string, Rolled>, totals: Rolled}
     */
    public function rollUp(array $nodes): array
    {
        $byId = [];

        foreach ($nodes as $node) {
            $byId[$node['id']] = $node;
        }

        $parentOf = [];
        $childCount = [];

        foreach ($byId as $id => $node) {
            $parent = $node['parent_id'] ?? null;
            $parentOf[$id] = $parent !== null && isset($byId[$parent]) && $parent !== $id ? $parent : null;

            if ($parentOf[$id] !== null) {
                $childCount[$parentOf[$id]] = ($childCount[$parentOf[$id]] ?? 0) + 1;
            }
        }

        // Deepest nodes first, so a parent is always computed after all its children.
        $depth = [];

        foreach (array_keys($byId) as $id) {
            $depth[$id] = $this->depth($id, $parentOf);
        }

        $order = array_keys($byId);
        usort($order, static fn (string $a, string $b): int => $depth[$b] <=> $depth[$a]);

        $accumulators = [];
        $result = [];

        foreach ($order as $id) {
            $isLeaf = ! isset($childCount[$id]);
            $rolled = $isLeaf ? $this->leaf($byId[$id]) : $this->summary($accumulators[$id] ?? $this->emptyAccumulator());
            $result[$id] = $rolled;

            $parent = $parentOf[$id];
            $accumulators[$parent ?? ''] = $this->accumulate($accumulators[$parent ?? ''] ?? $this->emptyAccumulator(), $rolled);
        }

        return [
            'nodes' => $result,
            'totals' => $this->summary($accumulators[''] ?? $this->emptyAccumulator()),
        ];
    }

    /**
     * @param  array<string, string|null>  $parentOf
     */
    private function depth(string $id, array $parentOf): int
    {
        $depth = 0;
        $seen = [$id => true];

        while (($parent = $parentOf[$id] ?? null) !== null && ! isset($seen[$parent])) {
            $seen[$parent] = true;
            $id = $parent;
            $depth++;
        }

        return $depth;
    }

    /**
     * @param  NodeInput  $node
     * @return Rolled
     */
    private function leaf(array $node): array
    {
        $rolled = ['is_leaf' => true];

        foreach (self::Amounts as $field) {
            $rolled[$field] = Money::round($node[$field] ?? 0);
        }

        $rolled['percent_complete'] = Money::round(Money::min(100, Money::max(0, $node['percent_complete'] ?? 0)));
        $rolled['start_date'] = $node['start_date'] ?? null;
        $rolled['end_date'] = $node['end_date'] ?? null;

        /** @var Rolled $rolled */
        return $rolled;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyAccumulator(): array
    {
        return [
            'planned_hours' => '0', 'actual_hours' => '0', 'planned_cost' => '0', 'actual_cost' => '0', 'planned_revenue' => '0',
            'start_date' => null, 'end_date' => null,
            'hours_weighted' => '0', 'hours_weight' => '0', 'cost_weighted' => '0', 'cost_weight' => '0', 'pct_sum' => '0', 'count' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $acc
     * @param  Rolled  $child
     * @return array<string, mixed>
     */
    private function accumulate(array $acc, array $child): array
    {
        foreach (self::Amounts as $field) {
            $acc[$field] = Money::add($acc[$field], $child[$field]);
        }

        $acc['hours_weighted'] = Money::add($acc['hours_weighted'], Money::mul($child['planned_hours'], $child['percent_complete']));
        $acc['hours_weight'] = Money::add($acc['hours_weight'], $child['planned_hours']);
        $acc['cost_weighted'] = Money::add($acc['cost_weighted'], Money::mul($child['planned_cost'], $child['percent_complete']));
        $acc['cost_weight'] = Money::add($acc['cost_weight'], $child['planned_cost']);
        $acc['pct_sum'] = Money::add($acc['pct_sum'], $child['percent_complete']);
        $acc['count']++;

        if ($child['start_date'] !== null && ($acc['start_date'] === null || $child['start_date'] < $acc['start_date'])) {
            $acc['start_date'] = $child['start_date'];
        }

        if ($child['end_date'] !== null && ($acc['end_date'] === null || $child['end_date'] > $acc['end_date'])) {
            $acc['end_date'] = $child['end_date'];
        }

        return $acc;
    }

    /**
     * @param  array<string, mixed>  $acc
     * @return Rolled
     */
    private function summary(array $acc): array
    {
        $percent = match (true) {
            Money::isPositive($acc['hours_weight']) => Money::div($acc['hours_weighted'], $acc['hours_weight']),
            Money::isPositive($acc['cost_weight']) => Money::div($acc['cost_weighted'], $acc['cost_weight']),
            $acc['count'] > 0 => Money::div($acc['pct_sum'], $acc['count']),
            default => '0',
        };

        $rolled = ['is_leaf' => false];

        foreach (self::Amounts as $field) {
            $rolled[$field] = Money::round($acc[$field]);
        }

        $rolled['percent_complete'] = Money::round($percent ?? '0');
        $rolled['start_date'] = $acc['start_date'];
        $rolled['end_date'] = $acc['end_date'];

        /** @var Rolled $rolled */
        return $rolled;
    }
}
