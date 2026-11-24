<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Domain\Services;

use InvalidArgumentException;

/**
 * Pure rules of the center tree: no cycles, bounded depth, a profit center never sits under a cost center and the
 * validity of a child stays inside the validity of its parent. Works on a flat map so it needs no database.
 */
final class HierarchyValidatorService
{
    public const int MaxDepth = 10;

    /**
     * @param  array<string, array{parent_id: string|null, center_type: string, valid_from: string|null, valid_to: string|null}>  $tree  current centers by id
     * @param  string  $id  the center being created or moved (may be new)
     * @param  array{parent_id: string|null, center_type: string, valid_from: string|null, valid_to: string|null}  $candidate
     *
     * @throws InvalidArgumentException
     */
    public function assertValid(array $tree, string $id, array $candidate): void
    {
        $parentId = $candidate['parent_id'];
        if ($parentId === null) {
            return;
        }
        if ($parentId === $id) {
            throw new InvalidArgumentException('A center cannot be its own parent.');
        }
        if (! isset($tree[$parentId])) {
            throw new InvalidArgumentException('The parent center does not exist.');
        }
        if (in_array($id, $this->ancestorIds($tree, $parentId), true)) {
            throw new InvalidArgumentException('Moving the center under one of its descendants would create a cycle.');
        }
        if ($this->depthOf($tree, $parentId) + 1 > self::MaxDepth) {
            throw new InvalidArgumentException('The center tree cannot be deeper than '.self::MaxDepth.' levels.');
        }
        $parent = $tree[$parentId];
        if ($parent['center_type'] === 'COST_CENTER' && $candidate['center_type'] === 'PROFIT_CENTER') {
            throw new InvalidArgumentException('A profit center cannot be placed under a cost center.');
        }
        if ($parent['valid_from'] !== null && $candidate['valid_from'] !== null && $candidate['valid_from'] < $parent['valid_from']) {
            throw new InvalidArgumentException('The center cannot be valid before its parent.');
        }
        if ($parent['valid_to'] !== null && ($candidate['valid_to'] === null || $candidate['valid_to'] > $parent['valid_to'])) {
            throw new InvalidArgumentException('The center cannot stay valid after its parent.');
        }
    }

    /**
     * Ids from the given center up to the root, the center itself included.
     *
     * @param  array<string, array{parent_id: string|null}>  $tree
     * @return list<string>
     */
    public function ancestorIds(array $tree, string $id): array
    {
        $chain = [];
        $current = $id;
        while ($current !== null && isset($tree[$current]) && ! in_array($current, $chain, true)) {
            $chain[] = $current;
            $current = $tree[$current]['parent_id'];
        }

        return $chain;
    }

    /**
     * Ids of every descendant of the center, the center itself excluded.
     *
     * @param  array<string, array{parent_id: string|null}>  $tree
     * @return list<string>
     */
    public function descendantIds(array $tree, string $id): array
    {
        $children = [];
        foreach ($tree as $childId => $node) {
            if ($node['parent_id'] !== null) {
                $children[$node['parent_id']][] = (string) $childId;
            }
        }
        $result = [];
        $queue = [$id];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($children[$current] ?? [] as $child) {
                if (! in_array($child, $result, true)) {
                    $result[] = $child;
                    $queue[] = $child;
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, array{parent_id: string|null}>  $tree
     */
    public function depthOf(array $tree, string $id): int
    {
        return max(0, count($this->ancestorIds($tree, $id)) - 1);
    }

    /**
     * Materialized path "/rootId/.../id/" of the center.
     *
     * @param  array<string, array{parent_id: string|null}>  $tree
     */
    public function pathOf(array $tree, string $id): string
    {
        return '/'.implode('/', array_reverse($this->ancestorIds($tree, $id))).'/';
    }

    /**
     * A center accepts postings on a date when it is active and the date is inside its validity window.
     *
     * @param  array{is_active: bool, valid_from: string|null, valid_to: string|null}  $center
     */
    public function acceptsPostings(array $center, string $date): bool
    {
        return $center['is_active']
            && ($center['valid_from'] === null || $date >= $center['valid_from'])
            && ($center['valid_to'] === null || $date <= $center['valid_to']);
    }
}
