<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Actions\Sales\ConvertOrderToDeliveryNote;
use App\Models\DeliveryNote;
use App\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockPackingList;
use Modules\Inventory\Models\StockPickLine;
use Modules\Inventory\Models\StockPickList;
use Modules\Inventory\Support\Quantity;

/**
 * Confirms the shipment of a completed pick list of a sales order: it creates the delivery note (DDT) through the
 * sales flow (ConvertOrderToDeliveryNote, which numbers it, updates the delivered quantities and the order status and
 * issues the stock), for exactly the quantities that were picked. The stock leaves from the warehouse of the pick list
 * and first from the bins the goods were staged in (OutboundIssuePreference), so the picked units are the ones that
 * go out. The packing lists of the pick list are linked to the delivery note, the reservations behind it are marked
 * fulfilled and the wave is closed when all its lists are done.
 *
 * Picked lines must have been moved to a staging bin: a pick confirmed without destination already took the stock out,
 * and a delivery note on top would issue it twice.
 */
final readonly class ShipmentConfirmationService
{
    public function __construct(
        private ConvertOrderToDeliveryNote $deliveryNotes,
        private OutboundIssuePreference $preference,
        private SalesOrderReservationService $reservations,
        private PickWaveService $waves,
    ) {}

    /**
     * @throws StockRuleViolation when the list is not a completed, unshipped pick list of a sales order, or its picks
     *                            were not staged, or the picked quantities exceed what the order still has open
     */
    public function confirm(StockPickList $list): DeliveryNote
    {
        return DB::transaction(function () use ($list): DeliveryNote {
            /** @var StockPickList $locked */
            $locked = StockPickList::query()->whereKey($list->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'completed' || $locked->shipped_at !== null) {
                throw new StockRuleViolation("Only a completed pick list that is not shipped yet can be confirmed for shipment (status [{$locked->status}]).");
            }

            if ($locked->origin_document_type !== (new SalesOrder)->getMorphClass() || $locked->origin_document_id === null) {
                throw new StockRuleViolation('Only the pick list of a sales order can be shipped with a delivery note.');
            }

            /** @var SalesOrder $order */
            $order = SalesOrder::query()->findOrFail($locked->origin_document_id);
            $picked = StockPickLine::query()->where('stock_pick_list_id', $locked->id)->where('quantity_picked', '>', 0)->get();

            if ($picked->isEmpty()) {
                throw new StockRuleViolation('Nothing was picked on this list.');
            }

            if ($picked->contains(static fn (StockPickLine $line): bool => $line->destination_bin_id === null)) {
                throw new StockRuleViolation('Some picks were confirmed without a staging bin: their stock already left and cannot be shipped again with a delivery note.');
            }

            $lines = $this->orderLines($order, $picked);
            $binIds = $picked->pluck('destination_bin_id')->map(static fn ($id): string => (string) $id)->unique()->values()->all();

            $note = $this->preference->during((string) $locked->warehouse_id, $binIds, fn (): DeliveryNote => $this->deliveryNotes->execute($order, $lines));

            $locked->update(['status' => 'shipped', 'shipped_at' => Carbon::now(), 'delivery_note_id' => $note->id]);
            StockPackingList::query()->where('stock_pick_list_id', $locked->id)->whereNull('delivery_note_id')->update(['delivery_note_id' => $note->id]);
            $this->reservations->markFulfilled(array_keys($lines));

            if ($locked->pick_wave_id !== null) {
                $this->waves->closeIfDone((string) $locked->pick_wave_id);
            }

            return $note;
        });
    }

    /**
     * Spreads the picked quantity of each product over the open lines of the order that sell it, in line order.
     *
     * @param  Collection<int, StockPickLine>  $picked
     * @return array<string, string> sales order item id => quantity
     */
    private function orderLines(SalesOrder $order, Collection $picked): array
    {
        $productOf = StockItem::query()->whereIn('id', $picked->pluck('stock_item_id')->unique())->pluck('product_id', 'id');
        $perProduct = [];

        foreach ($picked as $line) {
            $productId = $productOf[$line->stock_item_id] ?? null;

            if ($productId === null) {
                throw new StockRuleViolation('A picked item is not a catalog product: it cannot appear on a delivery note.');
            }

            $perProduct[(string) $productId] = Quantity::add($perProduct[(string) $productId] ?? '0', (string) $line->quantity_picked);
        }

        $lines = [];

        foreach ($order->items()->get() as $orderItem) {
            $remaining = $perProduct[(string) $orderItem->product_id] ?? null;

            if ($remaining === null || ! Quantity::isPositive($remaining)) {
                continue;
            }

            $open = Quantity::max('0', Quantity::sub((string) $orderItem->quantity, (string) $orderItem->quantity_delivered));
            $take = Quantity::min($open, $remaining);

            if (Quantity::isPositive($take)) {
                $lines[(string) $orderItem->id] = $take;
                $perProduct[(string) $orderItem->product_id] = Quantity::sub($remaining, $take);
            }
        }

        foreach ($perProduct as $left) {
            if (Quantity::isPositive($left)) {
                throw new StockRuleViolation('More was picked than the sales order still has open.');
            }
        }

        return $lines;
    }
}
