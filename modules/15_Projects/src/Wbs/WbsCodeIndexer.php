<?php

declare(strict_types=1);

namespace Modules\Projects\Wbs;

/**
 * Derives the dotted WBS codes (1, 1.1, 1.1.1) from the tree: siblings are numbered 1..n in sort order, so a move, an
 * insert or a delete only needs the new tree to renumber everything consistently.
 */
final class WbsCodeIndexer
{
    /**
     * @param  list<array{id: string, parent_id: string|null, sort_order: int}>  $nodes
     * @return array<string, array{code: string, sort_order: int}> code and gapless sort order of every node
     */
    public function index(array $nodes): array
    {
        $children = [];
        $ids = array_column($nodes, 'id');

        foreach ($nodes as $position => $node) {
            // A node whose parent is unknown is treated as a root, so a dangling reference never loses a node.
            $parent = $node['parent_id'] !== null && in_array($node['parent_id'], $ids, true) ? $node['parent_id'] : '';
            $children[$parent][] = [$node['sort_order'], $position, $node['id']];
        }

        $result = [];
        $this->walk('', '', $children, $result);

        return $result;
    }

    /**
     * @param  array<string, list<array{int, int, string}>>  $children
     * @param  array<string, array{code: string, sort_order: int}>  $result
     */
    private function walk(string $parent, string $prefix, array $children, array &$result): void
    {
        $siblings = $children[$parent] ?? [];
        usort($siblings, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        foreach ($siblings as $index => [, , $id]) {
            $code = $prefix === '' ? (string) ($index + 1) : $prefix.'.'.($index + 1);
            $result[$id] = ['code' => $code, 'sort_order' => $index + 1];
            $this->walk($id, $code, $children, $result);
        }
    }
}
