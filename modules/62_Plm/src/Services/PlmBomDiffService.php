<?php

declare(strict_types=1);

namespace Modules\Plm\Services;

use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgBomComponent;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Support\Money;

/**
 * Multi-level BOM diff (Added=green, Removed=red, Modified=yellow, Unchanged) and
 * hierarchical Cost Roll-up (Rev. A vs. Rev. B or eBOM vs. mBOM).
 */
final class PlmBomDiffService
{
    /**
     * Compares two Manufacturing BOMs across all levels with cost roll-up.
     *
     * @return array<string, mixed>
     */
    public function compareBoms(MfgBom $fromBom, MfgBom $toBom): array
    {
        $before = $this->flattenBom($fromBom);
        $after = $this->flattenBom($toBom);

        return $this->buildDiffResult(
            fromLabel: "{$fromBom->bom_number} ({$fromBom->revision})",
            toLabel: "{$toBom->bom_number} ({$toBom->revision})",
            before: $before,
            after: $after,
            fromBomId: (string) $fromBom->id,
            toBomId: (string) $toBom->id,
        );
    }

    /**
     * Compares an eBOM part list (from CAD) against an mBOM (Mod 059).
     *
     * @param  list<array<string, mixed>>  $ebomParts
     * @return array<string, mixed>
     */
    public function compareEbomWithMbom(array $ebomParts, ?MfgBom $mbom, string $tenantId): array
    {
        $before = $mbom instanceof MfgBom ? $this->flattenBom($mbom) : [];
        $codes = array_values(array_unique(array_map(static fn (array $p): string => (string) ($p['item_code'] ?? ''), $ebomParts)));
        $itemsByCode = $codes === []
            ? []
            : MfgItem::query()->where('tenant_id', $tenantId)->whereIn('item_code', $codes)->get()->keyBy('item_code')->all();

        $after = [];
        foreach ($ebomParts as $part) {
            $code = (string) ($part['item_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $item = $itemsByCode[$code] ?? null;
            $unitCost = $item instanceof MfgItem ? $item->standardUnitCost() : Money::of((string) ($part['unit_cost'] ?? '0'));
            if (! Money::isPositive($unitCost) && isset($part['unit_cost'])) {
                $unitCost = Money::of((string) $part['unit_cost']);
            }
            $qty = Money::qty((string) ($part['quantity'] ?? '1'));
            $level = max(1, (int) ($part['level'] ?? 1));
            $key = "{$level}:{$code}";

            $after[$key] = [
                'key' => $key,
                'level' => $level,
                'component_item_id' => $item instanceof MfgItem ? (string) $item->id : null,
                'item_code' => $code,
                'item_name' => $item instanceof MfgItem ? $item->name : (string) ($part['name'] ?? $code),
                'uom' => $item instanceof MfgItem ? $item->uom : (string) ($part['uom'] ?? 'PCS'),
                'quantity' => $qty,
                'unit_cost' => Money::round($unitCost, 4),
                'extended_cost' => Money::round(Money::mul($qty, $unitCost), 4),
                'path' => isset($part['parent_code']) && $part['parent_code'] !== null ? [$part['parent_code'], $code] : [$code],
            ];
        }

        return $this->buildDiffResult(
            fromLabel: $mbom instanceof MfgBom ? "mBOM {$mbom->bom_number} ({$mbom->revision})" : 'mBOM (None)',
            toLabel: 'eBOM (CAD)',
            before: $before,
            after: $after,
            fromBomId: $mbom instanceof MfgBom ? (string) $mbom->id : null,
            toBomId: null,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function flattenBom(MfgBom $bom, int $level = 1, string $multiplier = '1', array $trail = [], array &$visited = []): array
    {
        if ($level > 25) {
            return [];
        }

        $result = [];
        $baseQty = Money::isPositive($bom->base_quantity) ? $bom->base_quantity : '1';
        $ratio = Money::div($multiplier, $baseQty);
        $components = MfgBomComponent::query()->with('componentItem')->where('bom_id', $bom->id)->orderBy('sequence')->get();

        foreach ($components as $component) {
            $item = $component->componentItem;
            if (! $item instanceof MfgItem) {
                continue;
            }
            $qty = Money::mul((string) $component->quantity_per, $ratio);
            $unitCost = $item->standardUnitCost();
            $extCost = Money::mul($qty, $unitCost);
            $path = [...$trail, $item->item_code];
            $key = "{$level}:{$item->item_code}";

            $result[$key] = [
                'key' => $key,
                'level' => $level,
                'component_item_id' => (string) $item->id,
                'item_code' => $item->item_code,
                'item_name' => $item->name,
                'uom' => $component->uom,
                'quantity' => Money::qty($qty),
                'unit_cost' => Money::round($unitCost, 4),
                'extended_cost' => Money::round($extCost, 4),
                'path' => $path,
            ];

            if (! isset($visited[(string) $item->id])) {
                $visited[(string) $item->id] = true;
                $subBom = MfgBom::query()
                    ->where('tenant_id', $bom->tenant_id)
                    ->where('mfg_item_id', $item->id)
                    ->whereIn('status', MfgBom::EffectiveStatuses)
                    ->orderByDesc('version')
                    ->first();

                if ($subBom instanceof MfgBom) {
                    $result += $this->flattenBom($subBom, $level + 1, $qty, $path, $visited);
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, array<string, mixed>>  $before
     * @param  array<string, array<string, mixed>>  $after
     * @return array<string, mixed>
     */
    private function buildDiffResult(string $fromLabel, string $toLabel, array $before, array $after, ?string $fromBomId, ?string $toBomId): array
    {
        $nodes = [];
        $added = [];
        $removed = [];
        $modified = [];
        $unchangedCount = 0;

        $totalBefore = '0';
        $totalAfter = '0';

        foreach ($after as $key => $rowAfter) {
            $totalAfter = Money::add($totalAfter, (string) $rowAfter['extended_cost']);
            if (! isset($before[$key])) {
                $node = [
                    ...$rowAfter,
                    'status' => 'added',
                    'color' => 'green',
                    'before_quantity' => null,
                    'after_quantity' => $rowAfter['quantity'],
                    'before_extended_cost' => '0.0000',
                    'after_extended_cost' => $rowAfter['extended_cost'],
                    'cost_delta' => $rowAfter['extended_cost'],
                ];
                $nodes[] = $node;
                $added[] = $node;

                continue;
            }

            $rowBefore = $before[$key];
            $qtyChanged = Money::compare((string) $rowBefore['quantity'], (string) $rowAfter['quantity']) !== 0;
            $costChanged = Money::compare((string) $rowBefore['unit_cost'], (string) $rowAfter['unit_cost']) !== 0;
            $delta = Money::sub((string) $rowAfter['extended_cost'], (string) $rowBefore['extended_cost']);

            if ($qtyChanged || $costChanged) {
                $node = [
                    ...$rowAfter,
                    'status' => 'modified',
                    'color' => 'yellow',
                    'before_quantity' => $rowBefore['quantity'],
                    'after_quantity' => $rowAfter['quantity'],
                    'before_unit_cost' => $rowBefore['unit_cost'],
                    'after_unit_cost' => $rowAfter['unit_cost'],
                    'before_extended_cost' => $rowBefore['extended_cost'],
                    'after_extended_cost' => $rowAfter['extended_cost'],
                    'cost_delta' => Money::round($delta, 4),
                ];
                $nodes[] = $node;
                $modified[] = $node;
            } else {
                $unchangedCount++;
                $nodes[] = [
                    ...$rowAfter,
                    'status' => 'unchanged',
                    'color' => 'default',
                    'before_quantity' => $rowBefore['quantity'],
                    'after_quantity' => $rowAfter['quantity'],
                    'before_extended_cost' => $rowBefore['extended_cost'],
                    'after_extended_cost' => $rowAfter['extended_cost'],
                    'cost_delta' => '0.0000',
                ];
            }
        }

        foreach ($before as $key => $rowBefore) {
            $totalBefore = Money::add($totalBefore, (string) $rowBefore['extended_cost']);
            if (! isset($after[$key])) {
                $delta = Money::sub('0', (string) $rowBefore['extended_cost']);
                $node = [
                    ...$rowBefore,
                    'status' => 'removed',
                    'color' => 'red',
                    'before_quantity' => $rowBefore['quantity'],
                    'after_quantity' => null,
                    'before_extended_cost' => $rowBefore['extended_cost'],
                    'after_extended_cost' => '0.0000',
                    'cost_delta' => Money::round($delta, 4),
                ];
                $nodes[] = $node;
                $removed[] = $node;
            }
        }

        $totalDelta = Money::sub($totalAfter, $totalBefore);
        $deltaPct = Money::isPositive($totalBefore)
            ? Money::round(Money::mul(Money::div($totalDelta, $totalBefore), '100'), 2)
            : '0.00';

        return [
            'from_bom_id' => $fromBomId,
            'to_bom_id' => $toBomId,
            'from_label' => $fromLabel,
            'to_label' => $toLabel,
            'nodes' => $nodes,
            'added' => $added,
            'removed' => $removed,
            'modified' => $modified,
            'summary' => [
                'added' => count($added),
                'removed' => count($removed),
                'modified' => count($modified),
                'unchanged' => $unchangedCount,
            ],
            'cost_rollup' => [
                'before_total_cost' => Money::round($totalBefore, 4),
                'after_total_cost' => Money::round($totalAfter, 4),
                'cost_delta' => Money::round($totalDelta, 4),
                'cost_delta_percentage' => $deltaPct,
            ],
        ];
    }
}
