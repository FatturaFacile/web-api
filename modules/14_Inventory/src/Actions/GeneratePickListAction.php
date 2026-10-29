<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockPickLine;
use Modules\Inventory\Models\StockPickList;
use Modules\Inventory\Models\StockQuant;

final readonly class GeneratePickListAction
{
    /**
     * @param  array<int, array{stock_item_id: string, quantity: float, lot_id?: ?string}>  $items
     */
    public function execute(
        string $warehouseId,
        array $items,
        ?string $assignedPickerId = null,
        ?string $originType = null,
        ?string $originId = null
    ): StockPickList {
        return DB::transaction(function () use ($warehouseId, $items, $assignedPickerId, $originType, $originId): StockPickList {
            $pickList = StockPickList::create([
                'warehouse_id' => $warehouseId,
                'pick_list_number' => 'PICK-'.now()->format('Ymd').'-'.strtoupper(Str::random(4)),
                'assigned_picker_user_id' => $assignedPickerId,
                'status' => 'draft',
                'origin_document_type' => $originType,
                'origin_document_id' => $originId,
            ]);

            $sequence = 1;
            foreach ($items as $item) {
                $stockItemId = (string) $item['stock_item_id'];
                $requiredQty = (float) $item['quantity'];
                $lotId = $item['lot_id'] ?? null;

                // Find optimal quants in warehouse ordered by pick path (aisle, rack, bin)
                $quantQuery = StockQuant::query()
                    ->where('warehouse_id', $warehouseId)
                    ->where('stock_item_id', $stockItemId)
                    ->where('available_quantity', '>', 0);

                if ($lotId) {
                    $quantQuery->where('lot_id', $lotId);
                }

                $quants = $quantQuery->with(['bin.rack.aisle'])->get()
                    ->sortBy(function (StockQuant $q) {
                        return [
                            $q->bin?->rack?->aisle?->code ?? 'ZZ',
                            $q->bin?->rack?->code ?? 'ZZ',
                            $q->bin?->code ?? 'ZZ',
                        ];
                    });

                $remainingToPick = $requiredQty;

                foreach ($quants as $quant) {
                    if ($remainingToPick <= 0) {
                        break;
                    }

                    $canPick = min($remainingToPick, (float) $quant->available_quantity);

                    StockPickLine::create([
                        'stock_pick_list_id' => $pickList->id,
                        'stock_item_id' => $stockItemId,
                        'warehouse_bin_id' => $quant->warehouse_bin_id,
                        'lot_id' => $quant->lot_id,
                        'serial_number_id' => $quant->serial_number_id,
                        'requested_quantity' => $canPick,
                        'picked_quantity' => 0.0,
                        'status' => 'pending',
                        'sequence_number' => $sequence++,
                    ]);

                    $remainingToPick -= $canPick;
                }

                if ($remainingToPick > 0) {
                    // Fallback line for shortage or unassigned bin
                    StockPickLine::create([
                        'stock_pick_list_id' => $pickList->id,
                        'stock_item_id' => $stockItemId,
                        'warehouse_bin_id' => $quants->first()?->warehouse_bin_id ?? (string) Str::uuid(),
                        'lot_id' => $lotId,
                        'requested_quantity' => $remainingToPick,
                        'picked_quantity' => 0.0,
                        'status' => 'shortage',
                        'sequence_number' => $sequence++,
                    ]);
                }
            }

            return $pickList->load('lines');
        });
    }
}
