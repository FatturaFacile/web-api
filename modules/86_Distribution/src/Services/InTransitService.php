<?php

declare(strict_types=1);

namespace Modules\Distribution\Services;

use Illuminate\Support\Carbon;
use Modules\Distribution\Models\TransferMeta;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\TransferOrder;
use Modules\Inventory\Models\TransferOrderLine;
use Modules\Inventory\Support\Quantity;

/**
 * Goods in transit: every line of a transfer on the way with the units still outstanding, filterable by item, warehouse pair,
 * lot, expected arrival and overdue shipments.
 */
final class InTransitService
{
    public function __construct(private readonly TransferPresenter $presenter) {}

    /**
     * @param  array{stock_item_id?: ?string, source_warehouse_id?: ?string, destination_warehouse_id?: ?string, lot_id?: ?string, overdue?: ?bool, expected_to?: ?string}  $filters
     * @return array{lines: list<array<string, mixed>>, total_units: string, total_value: string}
     */
    public function lines(array $filters = []): array
    {
        $orders = TransferOrder::query()
            ->whereIn('status', ['in_transit', 'partially_received'])
            ->when($filters['source_warehouse_id'] ?? null, static fn ($query, $id) => $query->where('source_warehouse_id', $id))
            ->when($filters['destination_warehouse_id'] ?? null, static fn ($query, $id) => $query->where('destination_warehouse_id', $id))
            ->with(['sourceWarehouse', 'destinationWarehouse', 'lines.item'])
            ->orderBy('shipped_at')
            ->get();

        $meta = TransferMeta::query()->whereIn('transfer_order_id', $orders->pluck('id')->all())->get()->keyBy('transfer_order_id');
        $lotNumbers = StockLot::query()
            ->whereIn('id', $orders->flatMap(static fn (TransferOrder $order) => $order->lines->pluck('lot_id'))->filter()->unique()->all())
            ->pluck('lot_number', 'id');

        $lines = [];
        $units = '0';
        $value = '0';

        foreach ($orders as $order) {
            $orderMeta = $meta->get($order->id);
            $expected = $orderMeta?->expected_arrival_date;
            $overdue = $this->presenter->overdue((string) $order->status, $expected);

            if (($filters['overdue'] ?? false) && ! $overdue) {
                continue;
            }

            if (($filters['expected_to'] ?? null) !== null && ($expected === null || $expected->gt(Carbon::parse((string) $filters['expected_to'])))) {
                continue;
            }

            /** @var TransferOrderLine $line */
            foreach ($order->lines as $line) {
                $outstanding = Quantity::sub(Quantity::sub((string) $line->quantity_shipped, (string) $line->quantity_received), (string) $line->quantity_damaged);

                if (! Quantity::isPositive($outstanding)) {
                    continue;
                }

                if (($filters['stock_item_id'] ?? null) !== null && (string) $line->stock_item_id !== $filters['stock_item_id']) {
                    continue;
                }

                if (($filters['lot_id'] ?? null) !== null && (string) $line->lot_id !== $filters['lot_id']) {
                    continue;
                }

                $lineValue = Quantity::value($outstanding, (string) ($line->item?->current_moving_average_cost ?? 0));
                $units = Quantity::add($units, $outstanding);
                $value = Quantity::add($value, $lineValue, 2);

                $lines[] = [
                    'transfer_id' => (string) $order->id,
                    'transfer_number' => (string) $order->transfer_number,
                    'stock_item_id' => (string) $line->stock_item_id,
                    'sku' => (string) $line->item?->sku,
                    'name' => (string) $line->item?->name,
                    'uom' => (string) $line->item?->uom,
                    'lot_id' => $line->lot_id,
                    'lot_number' => $line->lot_id !== null ? ($lotNumbers[(string) $line->lot_id] ?? null) : null,
                    'source_warehouse' => $this->presenter->warehouse($order->sourceWarehouse),
                    'destination_warehouse' => $this->presenter->warehouse($order->destinationWarehouse),
                    'quantity_in_transit' => $outstanding,
                    'value' => Quantity::of($lineValue, 2),
                    'shipped_at' => $order->shipped_at?->toIso8601String(),
                    'expected_arrival_date' => $expected?->toDateString(),
                    'is_overdue' => $overdue,
                    'carrier' => $orderMeta?->carrier,
                    'tracking_number' => $orderMeta?->tracking_number,
                ];
            }
        }

        return ['lines' => $lines, 'total_units' => $units, 'total_value' => Quantity::of($value, 2)];
    }
}
