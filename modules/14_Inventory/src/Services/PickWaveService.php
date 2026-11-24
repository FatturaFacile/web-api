<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Models\SalesOrder;
use App\Services\Sales\DocumentNumberingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Actions\GeneratePickListAction;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\PickWave;
use Modules\Inventory\Models\StockPickList;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Support\Quantity;

/**
 * Wave picking for sales orders. Releasing a wave reserves the open lines of each order (SalesOrderReservationService)
 * and builds one pick list per order from its reservations, all routed through the warehouse and staged in the
 * wave's shipping bin: confirming a pick moves the goods there, and the shipment confirmation of each list then issues
 * the delivery note from that bin. What the warehouse cannot cover is listed in the wave's `shortages`.
 */
final readonly class PickWaveService
{
    /** Bin types that can stage picked goods waiting for shipment. */
    public const array STAGING_BIN_TYPES = ['staging', 'shipping'];

    public function __construct(
        private SalesOrderReservationService $reservations,
        private GeneratePickListAction $pickLists,
        private PickConfirmationService $confirmations,
        private DocumentNumberingService $numbering,
    ) {}

    /**
     * @param  list<string>  $salesOrderIds
     *
     * @throws StockRuleViolation when the staging bin is not a staging or shipping bin of the warehouse, or nothing of
     *                            the orders can be picked
     */
    public function release(Warehouse $warehouse, array $salesOrderIds, WarehouseBin $stagingBin, ?string $pickerId, string $userId, ?string $notes = null): PickWave
    {
        $zone = $stagingBin->rack?->aisle?->zone;

        if ($zone === null || (string) $zone->warehouse_id !== (string) $warehouse->id || ! in_array($stagingBin->bin_type, self::STAGING_BIN_TYPES, true)) {
            throw new StockRuleViolation('The staging bin must be a staging or shipping bin of the wave warehouse.');
        }

        return DB::transaction(function () use ($warehouse, $salesOrderIds, $stagingBin, $pickerId, $userId, $notes): PickWave {
            $wave = PickWave::create([
                'tenant_id' => $warehouse->tenant_id,
                'company_id' => $warehouse->company_id,
                'warehouse_id' => $warehouse->id,
                'wave_number' => $this->numbering->next('pick_wave', null, (string) $warehouse->tenant_id)['number'],
                'status' => 'released',
                'staging_bin_id' => $stagingBin->id,
                'assigned_picker_user_id' => $pickerId,
                'notes' => $notes,
                'created_by' => $userId,
                'released_at' => Carbon::now(),
            ]);

            $shortages = [];
            $lists = 0;

            foreach (SalesOrder::query()->whereIn('id', array_values(array_unique($salesOrderIds)))->orderBy('id')->lockForUpdate()->get() as $order) {
                foreach ($this->reservations->reserve($order, $warehouse, $userId) as $line) {
                    if (Quantity::isPositive($line['short'])) {
                        $shortages[] = ['sales_order_id' => (string) $order->id, 'sales_order_item_id' => $line['sales_order_item_id'], 'stock_item_id' => $line['stock_item_id'], 'short' => $line['short']];
                    }
                }

                $items = $this->reservations->activeReservations($order, $warehouse)
                    ->reject(static fn ($reservation): bool => StockPickList::query()
                        ->whereNotIn('status', ['cancelled'])
                        ->whereHas('lines', static fn ($lines) => $lines->where('stock_reservation_id', $reservation->id))
                        ->exists())
                    ->map(static fn ($reservation): array => ['stock_item_id' => (string) $reservation->stock_item_id, 'reservation_id' => (string) $reservation->id])
                    ->values()
                    ->all();

                if ($items === []) {
                    continue;
                }

                $this->pickLists->execute((string) $warehouse->id, $items, $pickerId, $order->getMorphClass(), (string) $order->id, (string) $wave->id);
                $lists++;
            }

            if ($lists === 0) {
                throw new StockRuleViolation("Nothing of these sales orders can be picked from warehouse [{$warehouse->name}].");
            }

            $wave->update(['shortages' => $shortages === [] ? null : $shortages]);

            return $wave->load('pickLists.lines');
        });
    }

    /**
     * Cancels the pick lists of the wave that are not done; the orders keep their reservations.
     */
    public function cancel(PickWave $wave): PickWave
    {
        return DB::transaction(function () use ($wave): PickWave {
            $locked = PickWave::query()->whereKey($wave->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'released') {
                throw new StockRuleViolation("A wave in status [{$locked->status}] cannot be cancelled.");
            }

            foreach (StockPickList::query()->where('pick_wave_id', $locked->id)->whereNotIn('status', ['completed', 'cancelled', 'shipped'])->get() as $list) {
                $this->confirmations->cancel($list);
            }

            $locked->update(['status' => 'cancelled', 'completed_at' => Carbon::now()]);

            return $locked->load('pickLists.lines');
        });
    }

    /**
     * Closes the wave when every pick list is shipped or cancelled.
     */
    public function closeIfDone(string $waveId): void
    {
        $open = StockPickList::query()->where('pick_wave_id', $waveId)->whereNotIn('status', ['shipped', 'cancelled'])->exists();

        if (! $open) {
            PickWave::query()->whereKey($waveId)->where('status', 'released')->update(['status' => 'completed', 'completed_at' => Carbon::now()]);
        }
    }
}
