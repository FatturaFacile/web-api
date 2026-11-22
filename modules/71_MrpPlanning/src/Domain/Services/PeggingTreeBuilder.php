<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Services;

use Modules\MrpPlanning\Domain\Enums\DemandSourceType;

/**
 * Builds the multi-level pegging graph of one proposal from the pegging lists of the proposals of a run.
 *
 * Upstream ("where-used"): every pegged demand source of a proposal is a root demand (sales order line Mod 037, MPS line
 * Mod 062, safety stock Mod 051, allocated stock, production order components) or the parent proposal whose explosion
 * created the requirement, which is followed up recursively. Downstream: the proposals that are pegged to this one,
 * i.e. the components it creates, recursively.
 */
final class PeggingTreeBuilder
{
    private const int MaxDepth = 64;

    /**
     * @param  array<string, array{id: string, item_id: string, item_code: string|null, item_name: string|null, proposal_type: string, status: string, quantity: string, need_by_date: string|null, pegging: list<array{type: string, id: string, quantity: string}>}>  $nodes  every proposal of the run by id
     * @return array{proposal: array<string, mixed>, upstream: list<array<string, mixed>>, downstream: list<array<string, mixed>>, root_demands: list<array{type: string, id: string, quantity: string}>}|null
     */
    public function build(string $proposalId, array $nodes): ?array
    {
        if (! isset($nodes[$proposalId])) {
            return null;
        }

        $children = [];
        foreach ($nodes as $node) {
            foreach ($node['pegging'] as $peg) {
                if ($peg['type'] === DemandSourceType::MrpProposal->value) {
                    $children[$peg['id']][$node['id']] = $node['id'];
                }
            }
        }

        $roots = [];

        return [
            'proposal' => $this->summary($nodes[$proposalId]),
            'upstream' => $this->up($proposalId, $nodes, [$proposalId => true], $roots, 0),
            'downstream' => $this->down($proposalId, $nodes, $children, [$proposalId => true], 0),
            'root_demands' => $this->mergeRoots($roots),
        ];
    }

    /**
     * @param  array<string, array{id: string, item_id: string, item_code: string|null, item_name: string|null, proposal_type: string, status: string, quantity: string, need_by_date: string|null, pegging: list<array{type: string, id: string, quantity: string}>}>  $nodes
     * @param  array<string, true>  $path
     * @param  list<array{type: string, id: string, quantity: string}>  $roots
     * @return list<array<string, mixed>>
     */
    private function up(string $id, array $nodes, array $path, array &$roots, int $depth): array
    {
        $result = [];
        foreach ($nodes[$id]['pegging'] as $peg) {
            if ($peg['type'] === DemandSourceType::MrpProposal->value && isset($nodes[$peg['id']]) && ! isset($path[$peg['id']]) && $depth < self::MaxDepth) {
                $result[] = [
                    'type' => $peg['type'],
                    'id' => $peg['id'],
                    'quantity' => $peg['quantity'],
                    'proposal' => $this->summary($nodes[$peg['id']]),
                    'upstream' => $this->up($peg['id'], $nodes, [...$path, $peg['id'] => true], $roots, $depth + 1),
                ];

                continue;
            }

            if ($peg['type'] !== DemandSourceType::MrpProposal->value) {
                $roots[] = $peg;
            }
            $result[] = ['type' => $peg['type'], 'id' => $peg['id'], 'quantity' => $peg['quantity'], 'proposal' => null, 'upstream' => []];
        }

        return $result;
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, array<string, string>>  $children
     * @param  array<string, true>  $path
     * @return list<array<string, mixed>>
     */
    private function down(string $id, array $nodes, array $children, array $path, int $depth): array
    {
        $result = [];
        foreach ($children[$id] ?? [] as $childId) {
            if (isset($path[$childId]) || $depth >= self::MaxDepth) {
                continue;
            }
            $result[] = [...$this->summary($nodes[$childId]), 'children' => $this->down($childId, $nodes, $children, [...$path, $childId => true], $depth + 1)];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function summary(array $node): array
    {
        return [
            'id' => $node['id'],
            'item_id' => $node['item_id'],
            'item_code' => $node['item_code'],
            'item_name' => $node['item_name'],
            'proposal_type' => $node['proposal_type'],
            'status' => $node['status'],
            'quantity' => $node['quantity'],
            'need_by_date' => $node['need_by_date'],
        ];
    }

    /**
     * Root demands of the same source added up.
     *
     * @param  list<array{type: string, id: string, quantity: string}>  $roots
     * @return list<array{type: string, id: string, quantity: string}>
     */
    private function mergeRoots(array $roots): array
    {
        $merged = [];
        foreach ($roots as $root) {
            $key = $root['type'].'|'.$root['id'];
            $merged[$key] = isset($merged[$key])
                ? ['type' => $root['type'], 'id' => $root['id'], 'quantity' => bcadd($merged[$key]['quantity'], $root['quantity'], 4)]
                : $root;
        }

        return array_values($merged);
    }
}
