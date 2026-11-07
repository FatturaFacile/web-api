<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\StockPickLine;
use Modules\Inventory\Models\StockPickList;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Models\StockReservationAllocation;

/**
 * Closes the stops of a pick list. A confirmation frees what the line held and moves what was really picked: to the
 * packing or shipping bin when one is given, out of stock otherwise. A short pick leaves the difference free in its bin.
 */
final class PickConfirmationService
{
    public function __construct(
        private readonly DoubleEntryMovementServiceInterface $movements
    ) {}

    public function confirm(StockPickLine $line, string $quantityPicked, string $userId, ?string $destinationBinId = null): StockPickLine
    {
        return DB::transaction(function () use ($line, $quantityPicked, $userId, $destinationBinId): StockPickLine {
            /** @var StockPickLine $locked */
            $locked = StockPickLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new StockRuleViolation('This pick line is already closed.');
            }

            if (Decimal::compare($quantityPicked, (string) $locked->quantity_to_pick) > 0) {
                throw new StockRuleViolation('The picked quantity exceeds the quantity on the line.');
            }

            $this->freeHold($locked);

            if (Decimal::isPositive($quantityPicked)) {
                $list = StockPickList::findOrFail($locked->stock_pick_list_id);

                $this->movements->executeTransfer(new StockTransferDTO(
                    stockItemId: $locked->stock_item_id,
                    sourceBinId: $locked->warehouse_bin_id,
                    destinationBinId: $destinationBinId,
                    quantity: (float) $quantityPicked,
                    movementType: 'pick',
                    lotId: $locked->lot_id,
                    originDocumentType: $list->origin_document_type,
                    originDocumentId: $list->origin_document_id,
                    userId: $userId,
                    notes: "Pick list {$list->pick_list_number}"
                ));
            }

            $isShort = Decimal::compare($quantityPicked, (string) $locked->quantity_to_pick) < 0;

            $locked->update([
                'quantity_picked' => bcadd($quantityPicked, '0', 4),
                'status' => $isShort ? 'shorted' : 'picked',
                'picked_at' => now(),
                'picked_by_user_id' => $userId,
            ]);

            $this->advanceList((string) $locked->stock_pick_list_id);

            return $locked;
        });
    }

    /**
     * Gives back everything still pending on the list.
     */
    public function cancel(StockPickList $list): StockPickList
    {
        return DB::transaction(function () use ($list): StockPickList {
            $locked = StockPickList::query()->whereKey($list->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['completed', 'cancelled'], true)) {
                throw new StockRuleViolation("A pick list in status [{$locked->status}] cannot be cancelled.");
            }

            foreach (StockPickLine::query()->where('stock_pick_list_id', $locked->id)->where('status', 'pending')->lockForUpdate()->get() as $line) {
                if ($line->stock_reservation_id === null) {
                    $this->freeHold($line);
                }

                $line->update(['status' => 'cancelled']);
            }

            $locked->update(['status' => 'cancelled']);

            return $locked;
        });
    }

    /**
     * Takes the units of the line off the reservation count of its quant, and off the reservation they came from.
     */
    private function freeHold(StockPickLine $line): void
    {
        $quant = StockQuant::query()
            ->where('warehouse_bin_id', $line->warehouse_bin_id)
            ->where('stock_item_id', $line->stock_item_id)
            ->where('lot_id', $line->lot_id)
            ->lockForUpdate()
            ->first();

        if ($quant === null) {
            throw new StockRuleViolation('The stock of this pick line is no longer in its bin.');
        }

        $reserved = bcsub((string) $quant->quantity_reserved, (string) $line->quantity_to_pick, 4);
        $quant->quantity_reserved = bccomp($reserved, '0', 4) < 0 ? '0.0000' : $reserved;
        $quant->save();

        if ($line->stock_reservation_id === null) {
            return;
        }

        $allocation = StockReservationAllocation::query()
            ->where('stock_reservation_id', $line->stock_reservation_id)
            ->where('stock_quant_id', $quant->id)
            ->lockForUpdate()
            ->first();

        if ($allocation !== null) {
            $allocation->delete();
        }

        if (! StockReservationAllocation::query()->where('stock_reservation_id', $line->stock_reservation_id)->exists()) {
            StockReservation::query()->whereKey($line->stock_reservation_id)->update(['status' => 'picked']);
        }
    }

    private function advanceList(string $listId): void
    {
        $list = StockPickList::query()->whereKey($listId)->lockForUpdate()->firstOrFail();
        $hasPending = StockPickLine::query()->where('stock_pick_list_id', $listId)->where('status', 'pending')->exists();

        $list->update($hasPending ? ['status' => 'picking'] : ['status' => 'completed', 'completed_at' => now()]);
    }
}
