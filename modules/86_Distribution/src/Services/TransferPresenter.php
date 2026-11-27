<?php

declare(strict_types=1);

namespace Modules\Distribution\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Distribution\Models\TransferMeta;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\TransferDiscrepancy;
use Modules\Inventory\Models\TransferOrder;
use Modules\Inventory\Models\TransferOrderLine;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Support\Quantity;

/**
 * Builds the API shape of transfer orders and in-transit lines: warehouses, items, lots, transport data and overdue flag.
 */
final class TransferPresenter
{
    /** Statuses in which the goods are on the way and the expected arrival can be overdue. */
    private const array ON_THE_WAY = ['in_transit', 'partially_received'];

    /**
     * @param  Collection<int, TransferOrder>  $orders
     * @return list<array<string, mixed>>
     */
    public function orders(Collection $orders): array
    {
        $orders->load(['sourceWarehouse', 'destinationWarehouse', 'lines.item', 'lines.shippedLots', 'discrepancies']);
        $lots = $this->lots($orders);
        $meta = TransferMeta::query()->whereIn('transfer_order_id', $orders->pluck('id')->all())->get()->keyBy('transfer_order_id');

        return $orders->map(fn (TransferOrder $order): array => $this->order($order, $meta->get($order->id), $lots))->values()->all();
    }

    /**
     * @param  Collection<int, TransferOrder>  $orders
     * @return array<string, string>
     */
    private function lots(Collection $orders): array
    {
        $ids = $orders->flatMap(static fn (TransferOrder $order) => $order->lines->pluck('lot_id'))->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return StockLot::query()->whereIn('id', $ids)->pluck('lot_number', 'id')->map(static fn ($number): string => (string) $number)->all();
    }

    /**
     * @param  array<string, string>  $lots
     * @return array<string, mixed>
     */
    private function order(TransferOrder $order, ?TransferMeta $meta, array $lots): array
    {
        $expected = $meta?->expected_arrival_date;

        return [
            'id' => (string) $order->id,
            'transfer_number' => (string) $order->transfer_number,
            'status' => (string) $order->status,
            'source_warehouse' => $this->warehouse($order->sourceWarehouse),
            'destination_warehouse' => $this->warehouse($order->destinationWarehouse),
            'requested_date' => $order->requested_date?->toDateString(),
            'expected_arrival_date' => $expected?->toDateString(),
            'shipped_at' => $order->shipped_at?->toIso8601String(),
            'received_at' => $order->received_at?->toIso8601String(),
            'carrier' => $meta?->carrier,
            'tracking_number' => $meta?->tracking_number,
            'ddt_reference' => $meta?->ddt_reference,
            'is_overdue' => $this->overdue((string) $order->status, $expected),
            'notes' => $order->notes,
            'lines' => $order->lines->map(fn (TransferOrderLine $line): array => [
                'id' => (string) $line->id,
                'stock_item_id' => (string) $line->stock_item_id,
                'sku' => (string) $line->item?->sku,
                'name' => (string) $line->item?->name,
                'uom' => (string) $line->item?->uom,
                'lot_id' => $line->lot_id,
                'lot_number' => $line->lot_id !== null ? ($lots[(string) $line->lot_id] ?? null) : null,
                'quantity' => Quantity::of((string) $line->quantity),
                'quantity_shipped' => Quantity::of((string) $line->quantity_shipped),
                'quantity_received' => Quantity::of((string) $line->quantity_received),
                'quantity_damaged' => Quantity::of((string) $line->quantity_damaged),
                'discrepancy_qty' => Quantity::of((string) $line->discrepancy_qty),
                'discrepancy_reason' => $line->discrepancy_reason,
                'unit_cost' => Quantity::of((string) ($line->item?->current_moving_average_cost ?? 0)),
            ])->values()->all(),
            'discrepancies' => $order->discrepancies->map(static fn (TransferDiscrepancy $discrepancy): array => [
                'id' => (string) $discrepancy->id,
                'status' => (string) $discrepancy->status,
                'quantity' => Quantity::of((string) $discrepancy->discrepancy_qty),
                'reason' => $discrepancy->discrepancy_reason,
            ])->values()->all(),
        ];
    }

    /**
     * @return array{id: string, code: string, name: string}|null
     */
    public function warehouse(?Warehouse $warehouse): ?array
    {
        return $warehouse === null ? null : ['id' => (string) $warehouse->id, 'code' => (string) $warehouse->code, 'name' => (string) $warehouse->name];
    }

    public function overdue(string $status, ?Carbon $expected): bool
    {
        return in_array($status, self::ON_THE_WAY, true) && $expected !== null && $expected->lt(Carbon::today());
    }
}
