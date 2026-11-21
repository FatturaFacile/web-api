<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\Bom;

use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgBom;

/**
 * Detects circular BOM references at any depth: an item that is, directly or through any chain of sub-assemblies, a
 * component of itself. The structure of the company is read once as a graph (parent item to component items, from the
 * revisions in preparation, awaiting approval or released) and walked iteratively, so there is no depth limit and no
 * recursion.
 */
final class BomCycleDetector
{
    /**
     * Refuses the components when one of them reaches the parent item again.
     *
     * @param  list<string>  $componentItemIds  items the revision under change uses as components
     * @param  string|null  $excludeBomId  the revision being rewritten: its stored components are replaced by the given ones
     */
    public function assertAcyclic(string $tenantId, string $parentItemId, array $componentItemIds, ?string $excludeBomId = null): void
    {
        $path = $this->findCycle($tenantId, $parentItemId, $componentItemIds, $excludeBomId);
        if ($path === null) {
            return;
        }

        $codes = DB::table('mfg_items')->whereIn('id', $path)->pluck('item_code', 'id')->all();
        $chain = implode(' > ', array_map(static fn (string $id): string => (string) ($codes[$id] ?? $id), $path));

        throw new ManufacturingOperationException("Circular BOM reference: {$chain}.", 'components');
    }

    /**
     * The chain of items parent > ... > parent when a component reaches the parent again, null when the structure is
     * acyclic.
     *
     * @param  list<string>  $componentItemIds
     * @return list<string>|null
     */
    public function findCycle(string $tenantId, string $parentItemId, array $componentItemIds, ?string $excludeBomId = null): ?array
    {
        $graph = $this->graph($tenantId, $excludeBomId);

        foreach ($componentItemIds as $start) {
            if ($start === $parentItemId) {
                return [$parentItemId, $parentItemId];
            }

            // Iterative depth-first search from the component looking for the parent item.
            $stack = [[$start, [$parentItemId, $start]]];
            $seen = [$start => true];
            while ($stack !== []) {
                [$node, $trail] = array_pop($stack);
                foreach ($graph[$node] ?? [] as $child) {
                    if ($child === $parentItemId) {
                        return [...$trail, $parentItemId];
                    }
                    if (! isset($seen[$child])) {
                        $seen[$child] = true;
                        $stack[] = [$child, [...$trail, $child]];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Parent item to the distinct component items of its live revisions.
     *
     * @return array<string, list<string>>
     */
    private function graph(string $tenantId, ?string $excludeBomId): array
    {
        $rows = DB::table('mfg_bom_components as line')
            ->join('mfg_boms as bom', 'bom.id', '=', 'line.bom_id')
            ->where('bom.tenant_id', $tenantId)
            ->whereIn('bom.status', MfgBom::LiveStatuses)
            ->whereNull('bom.deleted_at')
            ->whereNull('line.deleted_at')
            ->when($excludeBomId !== null, static fn ($query) => $query->where('bom.id', '!=', $excludeBomId))
            ->select('bom.mfg_item_id as parent', 'line.component_item_id as child')
            ->distinct()
            ->get();

        $graph = [];
        foreach ($rows as $row) {
            $graph[(string) $row->parent][] = (string) $row->child;
        }

        return $graph;
    }
}
