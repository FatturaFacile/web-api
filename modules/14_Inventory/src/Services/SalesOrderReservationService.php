<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Enums\SalesOrderStatus;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\StockReservationServiceInterface;
use Modules\Inventory\DTOs\StockReservationDTO;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Support\Quantity;

/**
 * Reserves stock for the open lines of a sales order, so that what is promised to a customer cannot be sold or picked
 * for someone else. Each line of an inventory-tracked product reserves what is still open (ordered minus delivered,
 * minus what is already reserved or picked for it) and available in the warehouse, following the item's picking
 * strategy (FIFO, FEFO or LIFO, StockAllocationPlanner). What the warehouse cannot cover is reported as short, not
 * reserved: the order keeps its quantity and can be reserved again when stock arrives.
 *
 * Reservations are kept per order line (`reserved_for_type` = `sales_order_item`). They lapse at the given expiry,
 * else at the end of the order's due date, else after the default of StockReservationService (7 days, a value that
 * module already applied to every reservation).
 */
final readonly class SalesOrderReservationService
{
    public const string RESERVED_FOR = 'sales_order_item';

    /** Order statuses whose lines may hold stock. */
    public const array RESERVABLE_STATUSES = ['confirmed', 'approved', 'in_production', 'in_fulfillment'];

    /** Reservation statuses that still hold or represent stock of the line until it is delivered. */
    public const array HOLDING_STATUSES = ['active', 'picked'];

    public function __construct(
        private StockReservationServiceInterface $reservations,
        private StockAllocationPlanner $planner,
        private ProductStockItemResolver $stockItems,
    ) {}

    /**
     * @return list<array{sales_order_item_id: string, product_id: string, stock_item_id: string, open: string, already_reserved: string, reserved: string, short: string, reservation_id: ?string}>
     *
     * @throws StockRuleViolation when the order status does not allow reservations
     */
    public function reserve(SalesOrder $order, Warehouse $warehouse, ?string $userId, ?string $expiresAt = null): array
    {
        if (! in_array((string) $order->status, self::RESERVABLE_STATUSES, true)) {
            throw new StockRuleViolation("A sales order in status [{$order->status}] cannot reserve stock.");
        }

        return DB::transaction(function () use ($order, $warehouse, $userId, $expiresAt): array {
            $lines = [];
            $dueEnd = $order->due_date !== null ? Carbon::parse($order->due_date)->endOfDay() : null;
            $expiry = $expiresAt ?? ($dueEnd !== null && $dueEnd->isFuture() ? $dueEnd->toIso8601String() : null);

            foreach ($order->items()->lockForUpdate()->get() as $orderItem) {
                $product = $orderItem->product_id !== null ? Product::query()->find($orderItem->product_id) : null;

                if ($product === null || ! $product->track_inventory) {
                    continue;
                }

                $item = $this->stockItems->resolve($product);
                $open = Quantity::max('0', Quantity::sub((string) $orderItem->quantity, (string) $orderItem->quantity_delivered));
                $already = $this->holding($orderItem);
                $need = Quantity::max('0', Quantity::sub($open, $already));
                $reserved = '0';
                $reservationId = null;

                if (Quantity::isPositive($need)) {
                    $available = array_reduce($this->planner->availability($item, $warehouse), static fn (string $sum, array $quant): string => Quantity::add($sum, $quant['quantity_available']), '0');
                    $reserved = Quantity::min($need, $available);

                    if (Quantity::isPositive($reserved)) {
                        $reservationId = (string) $this->reservations->allocate(new StockReservationDTO(
                            stockItemId: (string) $item->id,
                            warehouseId: (string) $warehouse->id,
                            reservedForType: self::RESERVED_FOR,
                            reservedForId: (string) $orderItem->id,
                            quantity: (float) $reserved,
                            expiresAt: $expiry,
                            userId: $userId,
                        ))->id;
                    }
                }

                $lines[] = [
                    'sales_order_item_id' => (string) $orderItem->id,
                    'product_id' => (string) $product->id,
                    'stock_item_id' => (string) $item->id,
                    'open' => $open,
                    'already_reserved' => $already,
                    'reserved' => Quantity::of($reserved),
                    'short' => Quantity::sub($need, $reserved),
                    'reservation_id' => $reservationId,
                ];
            }

            return $lines;
        });
    }

    /**
     * Releases every active reservation of the order's lines; returns how many were released.
     */
    public function release(SalesOrder $order): int
    {
        return DB::transaction(function () use ($order): int {
            $reservations = StockReservation::query()
                ->where('reserved_for_type', self::RESERVED_FOR)
                ->whereIn('reserved_for_id', $order->items()->pluck('id'))
                ->where('status', 'active')
                ->orderBy('id')
                ->get();

            foreach ($reservations as $reservation) {
                $this->reservations->release($reservation);
            }

            return $reservations->count();
        });
    }

    /**
     * The active reservations of the order in a warehouse, by line.
     *
     * @return Collection<int, StockReservation>
     */
    public function activeReservations(SalesOrder $order, Warehouse $warehouse): Collection
    {
        return StockReservation::query()
            ->where('reserved_for_type', self::RESERVED_FOR)
            ->whereIn('reserved_for_id', $order->items()->pluck('id'))
            ->where('warehouse_id', $warehouse->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->get();
    }

    /**
     * Whether the order may still be shipped by a delivery note (the sales flow decides the transition itself).
     */
    public static function isShippable(SalesOrder $order): bool
    {
        return SalesOrderStatus::allows((string) $order->status, SalesOrderStatus::InFulfillment)
            || SalesOrderStatus::allows((string) $order->status, SalesOrderStatus::Shipped);
    }

    private function holding(SalesOrderItem $orderItem): string
    {
        return Quantity::of((string) StockReservation::query()
            ->where('reserved_for_type', self::RESERVED_FOR)
            ->where('reserved_for_id', $orderItem->id)
            ->whereIn('status', self::HOLDING_STATUSES)
            ->sum('quantity_reserved'));
    }

    /**
     * Marks the picked reservations of the given order lines as fulfilled once their delivery note exists.
     *
     * @param  list<string>  $orderItemIds
     */
    public function markFulfilled(array $orderItemIds): void
    {
        StockReservation::query()
            ->where('reserved_for_type', self::RESERVED_FOR)
            ->whereIn('reserved_for_id', $orderItemIds)
            ->where('status', 'picked')
            ->update(['status' => 'fulfilled', 'updated_at' => Carbon::now()]);
    }
}
