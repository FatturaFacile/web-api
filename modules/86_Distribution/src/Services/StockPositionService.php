<?php

declare(strict_types=1);

namespace Modules\Distribution\Services;

use Illuminate\Support\Carbon;
use Modules\Distribution\Models\TransferMeta;
use Modules\Inventory\Enums\TransferStatus;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\TransferOrder;
use Modules\Inventory\Models\TransferOrderLine;
use Modules\Inventory\Support\Quantity;

/**
 * Per warehouse and item stock positions read from the one stock ledger of the platform (quants and transfer orders): physical
 * stock outside the transit bins, reservations, and the quantities still on the way to a warehouse.
 */
final class StockPositionService
{
    /** Transfer statuses whose stock already left the source and has not reached the destination. */
    private const array IN_TRANSIT = ['in_transit', 'partially_received'];

    public static function key(string $warehouseId, string $itemId): string
    {
        return $warehouseId.'|'.$itemId;
    }

    /**
     * Physical stock outside the transit bins.
     *
     * @param  list<string>  $warehouseIds  empty means every warehouse
     * @param  list<string>  $itemIds  empty means every item
     * @return array<string, array{on_hand: string, reserved: string, available: string}>
     */
    public function onHand(array $warehouseIds = [], array $itemIds = []): array
    {
        $rows = StockQuant::query()
            ->join('warehouse_bins', 'warehouse_bins.id', '=', 'stock_quants.warehouse_bin_id')
            ->join('warehouse_racks', 'warehouse_racks.id', '=', 'warehouse_bins.warehouse_rack_id')
            ->join('warehouse_aisles', 'warehouse_aisles.id', '=', 'warehouse_racks.warehouse_aisle_id')
            ->join('warehouse_zones', 'warehouse_zones.id', '=', 'warehouse_aisles.warehouse_zone_id')
            ->whereNull('stock_quants.deleted_at')
            ->where('warehouse_zones.zone_type', '!=', 'transit')
            ->when($warehouseIds !== [], static fn ($query) => $query->whereIn('warehouse_zones.warehouse_id', $warehouseIds))
            ->when($itemIds !== [], static fn ($query) => $query->whereIn('stock_quants.stock_item_id', $itemIds))
            ->groupBy('warehouse_zones.warehouse_id', 'stock_quants.stock_item_id')
            ->selectRaw('warehouse_zones.warehouse_id as wid, stock_quants.stock_item_id as iid, SUM(stock_quants.quantity_on_hand) as on_hand, SUM(stock_quants.quantity_reserved) as reserved')
            ->get();

        $positions = [];

        foreach ($rows as $row) {
            $onHand = Quantity::of($row->getAttribute('on_hand'));
            $reserved = Quantity::of($row->getAttribute('reserved'));
            $positions[self::key((string) $row->getAttribute('wid'), (string) $row->getAttribute('iid'))] = [
                'on_hand' => $onHand,
                'reserved' => $reserved,
                'available' => Quantity::max('0', Quantity::sub($onHand, $reserved)),
            ];
        }

        return $positions;
    }

    /**
     * Units shipped to a warehouse and not yet received (or reported damaged): the in-transit inbound.
     *
     * @param  list<string>  $warehouseIds
     * @param  list<string>  $itemIds
     * @return array<string, string>
     */
    public function inTransitInbound(array $warehouseIds = [], array $itemIds = []): array
    {
        $inbound = [];

        foreach ($this->openLines(self::IN_TRANSIT, $warehouseIds, $itemIds, 'destination') as $row) {
            $key = self::key($row['warehouse_id'], $row['stock_item_id']);
            $inbound[$key] = Quantity::add($inbound[$key] ?? '0', $row['quantity']);
        }

        return $inbound;
    }

    /**
     * Receipts the destination can count on: approved transfers (full quantity) and transfers on the way (what is outstanding),
     * each with its expected arrival date.
     *
     * @param  list<string>  $warehouseIds
     * @param  list<string>  $itemIds
     * @return list<array{transfer_order_id: string, warehouse_id: string, stock_item_id: string, quantity: string, date: string, status: string}>
     */
    public function scheduledReceipts(array $warehouseIds = [], array $itemIds = []): array
    {
        return $this->openLines(['approved', ...self::IN_TRANSIT], $warehouseIds, $itemIds, 'destination');
    }

    /**
     * Issues the source already committed to: approved transfers not yet shipped.
     *
     * @param  list<string>  $warehouseIds
     * @param  list<string>  $itemIds
     * @return list<array{transfer_order_id: string, warehouse_id: string, stock_item_id: string, quantity: string, date: string, status: string}>
     */
    public function scheduledIssues(array $warehouseIds = [], array $itemIds = []): array
    {
        return $this->openLines(['approved'], $warehouseIds, $itemIds, 'source');
    }

    /**
     * @return array{on_hand: string, reserved: string, available: string, in_transit_inbound: string}
     */
    public function snapshot(string $warehouseId, string $itemId): array
    {
        $key = self::key($warehouseId, $itemId);
        $position = $this->onHand([$warehouseId], [$itemId])[$key] ?? ['on_hand' => '0.0000', 'reserved' => '0.0000', 'available' => '0.0000'];

        return $position + ['in_transit_inbound' => $this->inTransitInbound([$warehouseId], [$itemId])[$key] ?? '0.0000'];
    }

    /**
     * @param  list<string>  $statuses
     * @param  list<string>  $warehouseIds
     * @param  list<string>  $itemIds
     * @param  'source'|'destination'  $side
     * @return list<array{transfer_order_id: string, warehouse_id: string, stock_item_id: string, quantity: string, date: string, status: string}>
     */
    private function openLines(array $statuses, array $warehouseIds, array $itemIds, string $side): array
    {
        $column = $side === 'source' ? 'source_warehouse_id' : 'destination_warehouse_id';

        $orders = TransferOrder::query()
            ->whereIn('status', $statuses)
            ->when($warehouseIds !== [], static fn ($query) => $query->whereIn($column, $warehouseIds))
            ->with(['lines' => static fn ($lines) => $lines->when($itemIds !== [], static fn ($inner) => $inner->whereIn('stock_item_id', $itemIds))])
            ->get();

        if ($orders->isEmpty()) {
            return [];
        }

        $meta = TransferMeta::query()->whereIn('transfer_order_id', $orders->pluck('id')->all())->get()->keyBy('transfer_order_id');
        $today = Carbon::today()->toDateString();
        $rows = [];

        foreach ($orders as $order) {
            $expected = $meta->get($order->id)?->expected_arrival_date?->toDateString()
                ?? $order->requested_date?->toDateString()
                ?? $today;

            foreach ($order->lines as $line) {
                $quantity = $order->status === TransferStatus::Approved->value
                    ? Quantity::of((string) $line->quantity)
                    : $this->outstanding($line);

                if (! Quantity::isPositive($quantity)) {
                    continue;
                }

                $rows[] = [
                    'transfer_order_id' => (string) $order->id,
                    'warehouse_id' => (string) $order->{$column},
                    'stock_item_id' => (string) $line->stock_item_id,
                    'quantity' => $quantity,
                    'date' => $expected,
                    'status' => (string) $order->status,
                ];
            }
        }

        return $rows;
    }

    private function outstanding(TransferOrderLine $line): string
    {
        return Quantity::sub(Quantity::sub((string) $line->quantity_shipped, (string) $line->quantity_received), (string) $line->quantity_damaged);
    }
}
