<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Services\Sales\DocumentNumberingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\TransferOrder;
use Modules\Inventory\Models\TransferOrderLine;
use Modules\Inventory\Models\TransferOrderLineLot;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Support\Quantity;

/**
 * Transfer orders between warehouses, with stock in transit.
 *
 * Shipping takes each line from the source warehouse with the item's picking strategy (only stock available to orders:
 * no quarantine, no expired or quarantined lots, nothing reserved) and moves it into the transit bin of the destination
 * warehouse, keeping its lots: the whole order ships or nothing does. Receiving moves stock from transit into the
 * destination bins (the given bin, or the warehouse's default bin), lots first shipped first received, in one or more
 * receipts. Both legs are double-entry movements valued at the item's moving average, so the transfer does not change
 * the stock value; the number comes from the gapless document numbering (`transfer_order`).
 */
final readonly class TransferOrderService
{
    public function __construct(
        private DoubleEntryMovementServiceInterface $movements,
        private StockAllocationPlanner $planner,
        private TransitBinResolver $transitBins,
        private WarehouseDefaultBinResolver $defaultBins,
        private DocumentNumberingService $numbering,
    ) {}

    /**
     * @param  list<array{stock_item_id: string, quantity: string|int|float, lot_id?: ?string}>  $lines
     */
    public function create(Warehouse $source, Warehouse $destination, array $lines, string $userId, ?string $requestedDate = null, ?string $notes = null): TransferOrder
    {
        if ((string) $source->id === (string) $destination->id) {
            throw new StockRuleViolation('A transfer order moves stock between two different warehouses.');
        }

        return DB::transaction(function () use ($source, $destination, $lines, $userId, $requestedDate, $notes): TransferOrder {
            $order = TransferOrder::create([
                'tenant_id' => $source->tenant_id,
                'company_id' => $source->company_id,
                'transfer_number' => $this->numbering->next('transfer_order', null, (string) $source->tenant_id)['number'],
                'source_warehouse_id' => $source->id,
                'destination_warehouse_id' => $destination->id,
                'status' => 'draft',
                'requested_date' => $requestedDate,
                'created_by' => $userId,
                'notes' => $notes,
            ]);

            foreach ($lines as $line) {
                TransferOrderLine::create([
                    'tenant_id' => $order->tenant_id,
                    'transfer_order_id' => $order->id,
                    'stock_item_id' => $line['stock_item_id'],
                    'lot_id' => $line['lot_id'] ?? null,
                    'quantity' => Quantity::of($line['quantity']),
                    'quantity_shipped' => '0',
                    'quantity_received' => '0',
                ]);
            }

            return $order->load('lines.shippedLots');
        });
    }

    /**
     * @throws StockRuleViolation when the order is not a draft or the source warehouse cannot cover a line
     */
    public function ship(TransferOrder $order, string $userId): TransferOrder
    {
        return DB::transaction(function () use ($order, $userId): TransferOrder {
            $locked = $this->lock($order, ['draft']);
            /** @var Warehouse $source */
            $source = Warehouse::query()->findOrFail($locked->source_warehouse_id);
            /** @var Warehouse $destination */
            $destination = Warehouse::query()->findOrFail($locked->destination_warehouse_id);
            $transit = $this->transitBins->resolve($destination);

            foreach ($locked->lines()->get() as $line) {
                /** @var StockItem $item */
                $item = StockItem::query()->findOrFail($line->stock_item_id);
                $shares = $this->planner->split($this->planner->lockEligibleQuants($item, $source, $line->lot_id), Quantity::of((string) $line->quantity), (string) $source->name);
                $perLot = [];

                foreach ($shares as $share) {
                    $this->movements->executeTransfer(new StockTransferDTO(
                        stockItemId: (string) $item->id,
                        sourceBinId: (string) $share['quant']->warehouse_bin_id,
                        destinationBinId: (string) $transit->id,
                        quantity: (float) $share['quantity'],
                        movementType: 'transfer_out',
                        lotId: $share['quant']->lot_id,
                        originDocumentType: TransferOrder::class,
                        originDocumentId: (string) $locked->id,
                        userId: $userId,
                        notes: "Transfer order {$locked->transfer_number}: to transit"
                    ));

                    $key = (string) ($share['quant']->lot_id ?? '');
                    $perLot[$key] = Quantity::add($perLot[$key] ?? '0', $share['quantity']);
                }

                foreach ($perLot as $lotId => $quantity) {
                    TransferOrderLineLot::create([
                        'tenant_id' => $locked->tenant_id,
                        'transfer_order_line_id' => $line->id,
                        'lot_id' => $lotId === '' ? null : $lotId,
                        'quantity_shipped' => $quantity,
                        'quantity_received' => '0',
                    ]);
                }

                $line->update(['quantity_shipped' => Quantity::of((string) $line->quantity)]);
            }

            $locked->update(['status' => 'in_transit', 'transit_bin_id' => $transit->id, 'shipped_at' => Carbon::now(), 'shipped_by' => $userId]);

            return $locked->load('lines.shippedLots');
        });
    }

    /**
     * Receives stock from transit. Without receipts, everything still in transit is received into the default bin.
     *
     * @param  list<array{line_id: string, quantity: string|int|float, destination_bin_id?: ?string}>  $receipts
     *
     * @throws StockRuleViolation when the order is not in transit, a quantity exceeds what is in transit for its line,
     *                            or a destination bin is not a bin of the destination warehouse available to orders
     */
    public function receive(TransferOrder $order, array $receipts, string $userId): TransferOrder
    {
        return DB::transaction(function () use ($order, $receipts, $userId): TransferOrder {
            $locked = $this->lock($order, ['in_transit', 'partially_received']);
            $lines = $locked->lines()->get()->keyBy('id');

            if ($receipts === []) {
                $receipts = $lines
                    ->map(static fn (TransferOrderLine $line): array => ['line_id' => (string) $line->id, 'quantity' => Quantity::sub((string) $line->quantity_shipped, (string) $line->quantity_received)])
                    ->filter(static fn (array $receipt): bool => Quantity::isPositive($receipt['quantity']))
                    ->values()
                    ->all();
            }

            $defaultBin = null;

            foreach ($receipts as $receipt) {
                /** @var TransferOrderLine|null $line */
                $line = $lines->get($receipt['line_id']);

                if ($line === null) {
                    throw new StockRuleViolation('Every receipt must name a line of this transfer order.');
                }

                $quantity = Quantity::of($receipt['quantity']);
                $inTransit = Quantity::sub((string) $line->quantity_shipped, (string) $line->quantity_received);

                if (! Quantity::isPositive($quantity) || Quantity::compare($quantity, $inTransit) > 0) {
                    throw new StockRuleViolation("Line {$line->id} has {$inTransit} units in transit: {$quantity} cannot be received.");
                }

                $binId = ($receipt['destination_bin_id'] ?? null) !== null
                    ? $this->destinationBin($locked, (string) $receipt['destination_bin_id'])
                    : (string) ($defaultBin ??= $this->defaultBins->resolve((string) $locked->destination_warehouse_id))->id;

                $remaining = $quantity;

                foreach (TransferOrderLineLot::query()->where('transfer_order_line_id', $line->id)->orderBy('id')->lockForUpdate()->get() as $lot) {
                    $open = Quantity::sub((string) $lot->quantity_shipped, (string) $lot->quantity_received);
                    $take = Quantity::min($open, $remaining);

                    if (! Quantity::isPositive($take)) {
                        continue;
                    }

                    $this->movements->executeTransfer(new StockTransferDTO(
                        stockItemId: (string) $line->stock_item_id,
                        sourceBinId: (string) $locked->transit_bin_id,
                        destinationBinId: $binId,
                        quantity: (float) $take,
                        movementType: 'transfer_in',
                        lotId: $lot->lot_id,
                        originDocumentType: TransferOrder::class,
                        originDocumentId: (string) $locked->id,
                        userId: $userId,
                        notes: "Transfer order {$locked->transfer_number}: received from transit"
                    ));

                    $lot->update(['quantity_received' => Quantity::add((string) $lot->quantity_received, $take)]);
                    $remaining = Quantity::sub($remaining, $take);

                    if (! Quantity::isPositive($remaining)) {
                        break;
                    }
                }

                $line->update(['quantity_received' => Quantity::add((string) $line->quantity_received, $quantity)]);
            }

            $complete = $locked->lines()->get()->every(static fn (TransferOrderLine $line): bool => Quantity::compare((string) $line->quantity_received, (string) $line->quantity) >= 0);

            $locked->update([
                'status' => $complete ? 'received' : 'partially_received',
                'received_at' => $complete ? Carbon::now() : null,
                'received_by' => $userId,
            ]);

            return $locked->load('lines.shippedLots');
        });
    }

    public function cancel(TransferOrder $order): TransferOrder
    {
        return DB::transaction(function () use ($order): TransferOrder {
            $locked = $this->lock($order, ['draft']);
            $locked->update(['status' => 'cancelled']);

            return $locked->load('lines.shippedLots');
        });
    }

    /**
     * @param  list<string>  $statuses
     */
    private function lock(TransferOrder $order, array $statuses): TransferOrder
    {
        /** @var TransferOrder $locked */
        $locked = TransferOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

        if (! in_array($locked->status, $statuses, true)) {
            throw new StockRuleViolation("The transfer order is {$locked->status}: the operation needs status ".implode(' or ', $statuses).'.');
        }

        return $locked;
    }

    private function destinationBin(TransferOrder $order, string $binId): string
    {
        $bin = WarehouseBin::query()->with('rack.aisle.zone')->findOrFail($binId);
        $zone = $bin->rack?->aisle?->zone;

        if ($zone === null || (string) $zone->warehouse_id !== (string) $order->destination_warehouse_id || in_array($zone->zone_type, StockAllocationPlanner::BLOCKED_ZONE_TYPES, true)) {
            throw new StockRuleViolation("Bin [{$bin->barcode}] is not a bin of the destination warehouse that can receive stock.");
        }

        return (string) $bin->id;
    }
}
