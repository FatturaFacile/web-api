<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockLotLink;

/**
 * Walks the genealogy of a lot: back from a finished or packaged lot to the raw materials and their suppliers, or
 * forward from a raw lot to everything made from it and the deliveries that carried it, for a recall.
 */
final class LotTraceabilityService
{
    /** Deeper chains than this are refused rather than walked: real genealogies are a few levels deep. */
    private const int MAX_DEPTH = 25;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function link(StockLot $lot, StockLot $component, array $attributes = []): StockLotLink
    {
        if ((string) $lot->id === (string) $component->id || $this->reaches($component, (string) $lot->id)) {
            throw new StockRuleViolation('A lot cannot be a component of itself, directly or through other lots.');
        }

        return StockLotLink::create([
            'tenant_id' => $lot->tenant_id,
            'lot_id' => $lot->id,
            'component_lot_id' => $component->id,
            'quantity_consumed' => $attributes['quantity_consumed'] ?? null,
            'production_order_id' => $attributes['production_order_id'] ?? null,
            'notes' => $attributes['notes'] ?? null,
        ]);
    }

    /**
     * The tree of lots the given lot comes from.
     *
     * @return array<string, mixed>
     */
    public function backward(StockLot $lot): array
    {
        return $this->node($lot, null, 'component', [(string) $lot->id => true], 0);
    }

    /**
     * The tree of lots made from the given lot, each with the deliveries that shipped it.
     *
     * @return array<string, mixed>
     */
    public function forward(StockLot $lot): array
    {
        return $this->node($lot, null, 'derived', [(string) $lot->id => true], 0);
    }

    /**
     * @param  array<string, bool>  $path  the lots above this node, so a damaged chain cannot loop
     * @return array<string, mixed>
     */
    private function node(StockLot $lot, ?StockLotLink $via, string $direction, array $path, int $depth): array
    {
        $links = $depth >= self::MAX_DEPTH ? collect() : StockLotLink::query()
            ->where($direction === 'component' ? 'lot_id' : 'component_lot_id', $lot->id)
            ->with($direction === 'component' ? 'componentLot.item' : 'lot.item')
            ->orderBy('id')
            ->get();

        $children = [];
        foreach ($links as $link) {
            $next = $direction === 'component' ? $link->componentLot : $link->lot;

            if ($next === null || isset($path[(string) $next->id])) {
                continue;
            }

            $children[] = $this->node($next, $link, $direction, $path + [(string) $next->id => true], $depth + 1);
        }

        return [
            'lot_id' => (string) $lot->id,
            'lot_number' => $lot->lot_number,
            'lot_level' => $lot->lot_level,
            'stock_item_id' => (string) $lot->stock_item_id,
            'sku' => $lot->item?->sku,
            'item_name' => $lot->item?->name,
            'supplier_id' => $lot->supplier_id,
            'supplier_lot_number' => $lot->supplier_lot_number,
            'manufacturing_date' => $lot->manufacturing_date?->toDateString(),
            'expiration_date' => $lot->expiration_date?->toDateString(),
            'received_at' => $lot->received_at?->toDateString(),
            'quantity_consumed' => $via?->quantity_consumed,
            'production_order_id' => $via?->production_order_id,
            'deliveries' => $direction === 'derived' ? $this->deliveriesOf($lot) : [],
            'lots' => $children,
        ];
    }

    /**
     * @return list<array{delivery_note_id: string, ddt_number: string, contact_id: string, date: string, quantity: string}>
     */
    private function deliveriesOf(StockLot $lot): array
    {
        $productId = $lot->item?->product_id;

        if ($productId === null) {
            return [];
        }

        return DB::table('delivery_note_items as i')
            ->join('delivery_notes as n', 'n.id', '=', 'i.delivery_note_id')
            ->where('n.tenant_id', $lot->tenant_id)
            ->where('i.product_id', $productId)
            ->where('i.batch_number', $lot->lot_number)
            ->whereNull('n.deleted_at')
            ->orderBy('n.date')
            ->get(['n.id', 'n.ddt_number', 'n.contact_id', 'n.date', 'i.quantity'])
            ->map(static fn (object $row): array => [
                'delivery_note_id' => (string) $row->id,
                'ddt_number' => (string) $row->ddt_number,
                'contact_id' => (string) $row->contact_id,
                'date' => (string) $row->date,
                'quantity' => (string) $row->quantity,
            ])
            ->all();
    }

    private function reaches(StockLot $from, string $targetLotId): bool
    {
        $frontier = [(string) $from->id];
        $seen = [];

        for ($depth = 0; $frontier !== [] && $depth < self::MAX_DEPTH; $depth++) {
            $next = StockLotLink::query()->whereIn('lot_id', $frontier)->pluck('component_lot_id')->map(static fn ($id): string => (string) $id)->all();

            if (in_array($targetLotId, $next, true)) {
                return true;
            }

            $seen += array_flip($frontier);
            $frontier = array_values(array_filter(array_unique($next), static fn (string $id): bool => ! isset($seen[$id])));
        }

        return false;
    }
}
