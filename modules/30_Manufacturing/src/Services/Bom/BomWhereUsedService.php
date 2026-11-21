<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\Bom;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Support\Money;

/**
 * Bottom-up BOM implosion: every intermediate sub-assembly and top-level finished good that consumes an item at any
 * depth, with the cumulative coefficient (units of the item per unit of that parent, scrap and yield included, fixed
 * setup waste left out). The structure effective on the date is read once and walked iteratively.
 */
final class BomWhereUsedService
{
    /** Deeper structures than this are refused. */
    private const int MaxDepth = 50;

    /**
     * Implosion of an item.
     *
     * @return array{item_id: string, item_code: string, date: string, parents: list<array<string, mixed>>, top_level: list<array<string, mixed>>}
     */
    public function implode(MfgItem $item, ?string $date = null): array
    {
        $day = CarbonImmutable::parse($date ?? now())->toDateString();
        $usedBy = $this->usedBy((string) $item->tenant_id, $day);

        $parents = [];
        $tops = [];
        $queue = [[(string) $item->id, '1', [(string) $item->id => true], [$item->item_code], 0]];

        while ($queue !== []) {
            [$child, $coefficient, $seen, $trail, $depth] = array_shift($queue);
            if ($depth >= self::MaxDepth) {
                throw new ManufacturingOperationException('The BOM structure is deeper than '.self::MaxDepth.' levels.', 'components');
            }

            foreach ($usedBy[$child] ?? [] as $use) {
                if (isset($seen[$use['parent_item_id']])) {
                    throw new ManufacturingOperationException("Item [{$use['parent_item_code']}] is a component of itself through BOM [{$use['bom_number']}].", 'components');
                }

                $cumulative = Money::mul($coefficient, $use['factor']);
                $path = [...$trail, $use['parent_item_code']];
                $isTop = ! isset($usedBy[$use['parent_item_id']]);

                $parents[] = [
                    'level' => $depth + 1,
                    'parent_item_id' => $use['parent_item_id'],
                    'parent_item_code' => $use['parent_item_code'],
                    'parent_item_name' => $use['parent_item_name'],
                    'parent_item_type' => $use['parent_item_type'],
                    'bom_id' => $use['bom_id'],
                    'bom_number' => $use['bom_number'],
                    'revision' => $use['revision'],
                    'is_phantom' => $use['is_phantom'],
                    'quantity_per' => $use['quantity_per'],
                    'cumulative_coefficient' => Money::round($cumulative, 6),
                    'is_top_level' => $isTop,
                    'path' => $path,
                ];

                if ($isTop) {
                    $tops[$use['parent_item_id']] ??= ['item_id' => $use['parent_item_id'], 'item_code' => $use['parent_item_code'], 'item_name' => $use['parent_item_name'], 'cumulative_coefficient' => '0'];
                    $tops[$use['parent_item_id']]['cumulative_coefficient'] = Money::add($tops[$use['parent_item_id']]['cumulative_coefficient'], $cumulative);
                }

                $queue[] = [$use['parent_item_id'], $cumulative, [...$seen, $use['parent_item_id'] => true], $path, $depth + 1];
            }
        }

        return [
            'item_id' => (string) $item->id,
            'item_code' => $item->item_code,
            'date' => $day,
            'parents' => $parents,
            'top_level' => array_values(array_map(static fn (array $top): array => [...$top, 'cumulative_coefficient' => Money::round($top['cumulative_coefficient'], 6)], $tops)),
        ];
    }

    /**
     * Component item to the lines (of the revisions effective on the date) that use it.
     *
     * @return array<string, list<array{parent_item_id: string, parent_item_code: string, parent_item_name: string, parent_item_type: string, bom_id: string, bom_number: string, revision: string, is_phantom: bool, quantity_per: string, factor: string}>>
     */
    private function usedBy(string $tenantId, string $day): array
    {
        $rows = DB::table('mfg_bom_components as line')
            ->join('mfg_boms as bom', 'bom.id', '=', 'line.bom_id')
            ->join('mfg_items as parent', 'parent.id', '=', 'bom.mfg_item_id')
            ->where('bom.tenant_id', $tenantId)
            ->whereIn('bom.status', MfgBom::EffectiveStatuses)
            ->whereNull('bom.deleted_at')
            ->whereNull('line.deleted_at')
            ->where(static fn ($query) => $query->whereNull('bom.effective_from')->orWhereDate('bom.effective_from', '<=', $day))
            ->where(static fn ($query) => $query->whereNull('bom.effective_to')->orWhereDate('bom.effective_to', '>=', $day))
            ->select('line.component_item_id', 'line.quantity_per', 'line.scrap_percentage', 'line.yield_percentage', 'bom.id as bom_id', 'bom.bom_number', 'bom.revision', 'bom.base_quantity', 'bom.bom_type', 'parent.id as parent_id', 'parent.item_code as parent_code', 'parent.name as parent_name', 'parent.item_type as parent_type')
            ->orderBy('parent.item_code')
            ->get();

        $usedBy = [];
        foreach ($rows as $row) {
            $grossUp = Money::add('1', Money::div($row->scrap_percentage, '100'));
            $yield = Money::isPositive($row->yield_percentage) ? Money::div($row->yield_percentage, '100') : '1';
            $perBase = Money::mul($row->quantity_per, Money::div($grossUp, $yield));

            $usedBy[(string) $row->component_item_id][] = [
                'parent_item_id' => (string) $row->parent_id,
                'parent_item_code' => (string) $row->parent_code,
                'parent_item_name' => (string) $row->parent_name,
                'parent_item_type' => (string) $row->parent_type,
                'bom_id' => (string) $row->bom_id,
                'bom_number' => (string) $row->bom_number,
                'revision' => (string) $row->revision,
                'is_phantom' => $row->parent_type === 'phantom' || $row->bom_type === 'phantom',
                'quantity_per' => Money::round($row->quantity_per, 4),
                'factor' => Money::div($perBase, Money::isPositive($row->base_quantity) ? $row->base_quantity : '1'),
            ];
        }

        return $usedBy;
    }
}
