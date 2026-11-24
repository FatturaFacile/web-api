<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Services\Sales\DocumentNumberingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\Contracts\TransferRouteValidator;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Enums\DiscrepancyStatus;
use Modules\Inventory\Enums\TransferStatus;
use Modules\Inventory\Events\TransferOrderReceived;
use Modules\Inventory\Events\TransferOrderShipped;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\TransferDiscrepancy;
use Modules\Inventory\Models\TransferOrder;
use Modules\Inventory\Models\TransferOrderLine;
use Modules\Inventory\Models\TransferOrderLineLot;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Support\Quantity;

/**
 * Transfer orders between two warehouses: the one transfer aggregate of the platform (lot and bin aware).
 *
 * Lifecycle: draft -> approved (manager sign-off) -> in_transit (dispatch: every line leaves the source with the item
 * picking strategy and waits in the transit bin of the destination) -> partially_received | received |
 * discrepancy_pending -> closed. A cancelled order never moved stock.
 *
 * Receipts report per line the good units (moved to the destination), the damaged units and, on the final receipt,
 * the shortage. Damaged and missing units stay on the transit bin until a manager resolves the discrepancy (write-off,
 * return to origin or carrier claim); the order closes with the last resolution.
 */
final readonly class TransferOrderService
{
    public function __construct(
        private DoubleEntryMovementServiceInterface $movements,
        private StockAllocationPlanner $planner,
        private TransitBinResolver $transitBins,
        private WarehouseDefaultBinResolver $defaultBins,
        private DocumentNumberingService $numbering,
        private TransferRouteValidator $routes,
    ) {}

    /**
     * @param  list<array{stock_item_id: string, lot_id?: ?string, quantity: string|int|float}>  $lines
     */
    public function create(Warehouse $source, Warehouse $destination, array $lines, ?string $userId, ?string $requestedDate = null, ?string $notes = null, string $purpose = 'inter_warehouse'): TransferOrder
    {
        if ((string) $source->id === (string) $destination->id) {
            throw new StockRuleViolation('A transfer order moves stock between two different warehouses.');
        }

        $this->routes->assertValid($purpose, $source, $destination);

        return DB::transaction(function () use ($source, $destination, $lines, $userId, $requestedDate, $notes, $purpose): TransferOrder {
            $order = TransferOrder::create([
                'tenant_id' => $source->tenant_id,
                'company_id' => $source->company_id,
                'transfer_number' => $this->numbering->next('transfer_order', null, (string) $source->tenant_id)['number'],
                'source_warehouse_id' => $source->id,
                'destination_warehouse_id' => $destination->id,
                'status' => TransferStatus::Draft->value,
                'purpose' => $purpose,
                'is_inter_company' => $source->company_entity_id !== null && $destination->company_entity_id !== null
                    && $source->company_entity_id !== $destination->company_entity_id,
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

    /** Manager sign-off: only an approved transfer can be dispatched. */
    public function approve(TransferOrder $order, ?string $userId = null): TransferOrder
    {
        return DB::transaction(function () use ($order, $userId): TransferOrder {
            $locked = $this->lock($order, [TransferStatus::Draft]);
            $locked->update([
                'status' => TransferStatus::Approved->value,
                'approved_by' => $userId !== null && Str::isUuid($userId) ? $userId : null,
                'approved_at' => Carbon::now(),
            ]);

            return $locked->load('lines.shippedLots');
        });
    }

    /**
     * @param  array<string, mixed>  $deliveryData  carrier and package data for the transport document
     */
    public function ship(TransferOrder $order, ?string $userId, array $deliveryData = []): TransferOrder
    {
        return DB::transaction(function () use ($order, $userId, $deliveryData): TransferOrder {
            $locked = $this->lock($order, [TransferStatus::Approved]);
            $source = Warehouse::query()->findOrFail($locked->source_warehouse_id);
            $destination = Warehouse::query()->findOrFail($locked->destination_warehouse_id);
            $transit = $this->transitBins->resolve($destination);

            foreach ($locked->lines()->get() as $line) {
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

            $locked->update(['status' => TransferStatus::InTransit->value, 'transit_bin_id' => $transit->id, 'shipped_at' => Carbon::now(), 'shipped_by' => $userId]);

            event(new TransferOrderShipped((string) $locked->id, (string) $locked->tenant_id, $deliveryData));

            return $locked->refresh()->load('lines.shippedLots');
        });
    }

    /**
     * Registers a receipt. Each receipt names a line and gives `quantity` (or `received_qty`): the good units arriving,
     * moved to the given bin or the default bin of the destination, and optionally `damaged_qty`: units that arrived
     * damaged, quarantined on the transit bin. Without receipts everything still outstanding is received.
     *
     * With `$final = false` the outstanding units are still on their way (partially_received, no discrepancy). With
     * `$final = true` whatever did not arrive is a shortage and the order moves to received or discrepancy_pending.
     * A receipt that accounts for every outstanding unit completes the order in any case.
     *
     * @param  list<array{line_id: string, quantity?: string|int|float, received_qty?: string|int|float, damaged_qty?: string|int|float, discrepancy_reason?: ?string, destination_bin_id?: ?string}>  $receipts
     */
    public function receive(TransferOrder $order, array $receipts, ?string $userId, bool $final = false): TransferOrder
    {
        return DB::transaction(function () use ($order, $receipts, $userId, $final): TransferOrder {
            $locked = $this->lock($order, [TransferStatus::InTransit, TransferStatus::PartiallyReceived]);
            $lines = $locked->lines()->get()->keyBy('id');

            if ($receipts === []) {
                $receipts = $lines
                    ->map(fn (TransferOrderLine $line): array => ['line_id' => (string) $line->id, 'quantity' => $this->outstanding($line)])
                    ->filter(static fn (array $receipt): bool => Quantity::isPositive($receipt['quantity']))
                    ->values()
                    ->all();
            }

            $defaultBin = null;

            foreach ($receipts as $receipt) {
                $line = $lines->get($receipt['line_id']);

                if ($line === null) {
                    throw new StockRuleViolation('Every receipt must name a line of this transfer order.');
                }

                $good = Quantity::of($receipt['quantity'] ?? $receipt['received_qty'] ?? 0);
                $damaged = Quantity::of($receipt['damaged_qty'] ?? 0);
                $outstanding = $this->outstanding($line);

                if (Quantity::isNegative($good) || Quantity::isNegative($damaged) || Quantity::compare(Quantity::add($good, $damaged), $outstanding) > 0) {
                    throw new StockRuleViolation("Line {$line->id} has {$outstanding} units in transit: {$good} received and {$damaged} damaged cannot be accounted for.");
                }

                if (Quantity::isPositive($good)) {
                    $binId = ($receipt['destination_bin_id'] ?? null) !== null
                        ? $this->destinationBin($locked, (string) $receipt['destination_bin_id'])
                        : (string) ($defaultBin ??= $this->defaultBins->resolve((string) $locked->destination_warehouse_id))->id;

                    $this->moveFromTransit($locked, $line, $good, $binId, 'transfer_in', 'received from transit', $userId, 'quantity_received');
                }

                $line->update([
                    'quantity_received' => Quantity::add((string) $line->quantity_received, $good),
                    'quantity_damaged' => Quantity::add((string) $line->quantity_damaged, $damaged),
                    'discrepancy_reason' => $receipt['discrepancy_reason'] ?? $line->discrepancy_reason,
                ]);
            }

            $lines = $locked->lines()->get();
            $complete = $lines->every(fn (TransferOrderLine $line): bool => ! Quantity::isPositive($this->outstanding($line)));

            if (! $final && ! $complete) {
                $locked->update(['status' => TransferStatus::PartiallyReceived->value, 'received_by' => $userId]);

                return $locked->load('lines.shippedLots');
            }

            $hasDiscrepancies = false;

            foreach ($lines as $line) {
                $shortage = $this->outstanding($line);
                $damage = Quantity::of((string) $line->quantity_damaged);
                $variance = Quantity::add($shortage, $damage);

                $line->update([
                    'discrepancy_qty' => $variance,
                    'discrepancy_reason' => Quantity::isPositive($variance) ? $line->discrepancy_reason : null,
                ]);

                if (! Quantity::isPositive($variance)) {
                    continue;
                }

                $hasDiscrepancies = true;
                TransferDiscrepancy::create([
                    'tenant_id' => $locked->tenant_id,
                    'transfer_order_id' => $locked->id,
                    'transfer_order_line_id' => $line->id,
                    'stock_item_id' => $line->stock_item_id,
                    'discrepancy_qty' => $variance,
                    'shortage_qty' => $shortage,
                    'damage_qty' => $damage,
                    'discrepancy_reason' => $line->discrepancy_reason,
                    'status' => DiscrepancyStatus::PendingApproval->value,
                ]);
            }

            $locked->update([
                'status' => $hasDiscrepancies ? TransferStatus::DiscrepancyPending->value : TransferStatus::Received->value,
                'received_at' => Carbon::now(),
                'received_by' => $userId,
            ]);

            event(new TransferOrderReceived((string) $locked->id, (string) $locked->tenant_id, $hasDiscrepancies));

            return $locked->load(['lines.shippedLots', 'discrepancies']);
        });
    }

    /**
     * Manager decision on a discrepancy: only now the corrective movements happen. The order closes with the last one.
     */
    public function resolveDiscrepancy(TransferDiscrepancy $discrepancy, DiscrepancyStatus $resolution, ?string $userId = null): TransferDiscrepancy
    {
        if (! in_array($resolution->value, DiscrepancyStatus::resolutions(), true)) {
            throw new StockRuleViolation('Invalid discrepancy resolution.');
        }

        return DB::transaction(function () use ($discrepancy, $resolution, $userId): TransferDiscrepancy {
            $locked = TransferDiscrepancy::query()->whereKey($discrepancy->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DiscrepancyStatus::PendingApproval->value) {
                throw new StockRuleViolation('This discrepancy has already been resolved.');
            }

            $order = TransferOrder::query()->findOrFail($locked->transfer_order_id);
            $line = TransferOrderLine::query()->findOrFail($locked->transfer_order_line_id);

            if ($resolution === DiscrepancyStatus::ReturnedToOrigin) {
                $binId = (string) $this->defaultBins->resolve((string) $order->source_warehouse_id)->id;
                $this->moveFromTransit($order, $line, Quantity::of((string) $locked->discrepancy_qty), $binId, 'transfer_return', 'returned to origin', $userId, 'quantity_resolved');
            } else {
                $movementType = $resolution === DiscrepancyStatus::CarrierClaim ? 'transfer_carrier_claim' : 'transfer_write_off';
                $this->moveFromTransit($order, $line, Quantity::of((string) $locked->discrepancy_qty), null, $movementType, (string) $locked->discrepancy_reason, $userId, 'quantity_resolved');
            }

            $locked->update([
                'status' => $resolution->value,
                'resolved_by' => $userId !== null && Str::isUuid($userId) ? $userId : null,
                'resolved_at' => Carbon::now(),
            ]);

            $stillPending = TransferDiscrepancy::query()
                ->where('transfer_order_id', $order->id)
                ->where('status', DiscrepancyStatus::PendingApproval->value)
                ->exists();

            if (! $stillPending) {
                $order->update(['status' => TransferStatus::Closed->value, 'closed_at' => Carbon::now()]);
            }

            return $locked->refresh();
        });
    }

    /** Closes a fully received transfer (the discrepancy path closes it with the last resolution). */
    public function close(TransferOrder $order): TransferOrder
    {
        return DB::transaction(function () use ($order): TransferOrder {
            $locked = $this->lock($order, [TransferStatus::Received]);
            $locked->update(['status' => TransferStatus::Closed->value, 'closed_at' => Carbon::now()]);

            return $locked->load('lines.shippedLots');
        });
    }

    public function cancel(TransferOrder $order): TransferOrder
    {
        return DB::transaction(function () use ($order): TransferOrder {
            $locked = $this->lock($order, [TransferStatus::Draft, TransferStatus::Approved]);
            $locked->update(['status' => TransferStatus::Cancelled->value]);

            return $locked->load('lines.shippedLots');
        });
    }

    /** Units shipped and not yet accounted for: not received in good condition and not reported damaged. */
    public function outstanding(TransferOrderLine $line): string
    {
        return Quantity::sub(Quantity::sub((string) $line->quantity_shipped, (string) $line->quantity_received), (string) $line->quantity_damaged);
    }

    /**
     * Takes `$quantity` of a line out of the transit bin, lot by lot in the order they were shipped, to a bin (or out of
     * the warehouse when `$destinationBinId` is null). `$counter` is the lot counter that records it.
     *
     * @param  'quantity_received'|'quantity_resolved'  $counter
     */
    private function moveFromTransit(TransferOrder $order, TransferOrderLine $line, string $quantity, ?string $destinationBinId, string $movementType, string $note, ?string $userId, string $counter): void
    {
        $remaining = $quantity;

        foreach (TransferOrderLineLot::query()->where('transfer_order_line_id', $line->id)->orderBy('id')->lockForUpdate()->get() as $lot) {
            $open = Quantity::sub(Quantity::sub((string) $lot->quantity_shipped, (string) $lot->quantity_received), (string) $lot->quantity_resolved);
            $take = Quantity::min($open, $remaining);

            if (! Quantity::isPositive($take)) {
                continue;
            }

            $this->movements->executeTransfer(new StockTransferDTO(
                stockItemId: (string) $line->stock_item_id,
                sourceBinId: (string) $order->transit_bin_id,
                destinationBinId: $destinationBinId,
                quantity: (float) $take,
                movementType: $movementType,
                lotId: $lot->lot_id,
                originDocumentType: TransferOrder::class,
                originDocumentId: (string) $order->id,
                userId: $userId,
                notes: "Transfer order {$order->transfer_number}: {$note}"
            ));

            $lot->update([$counter => Quantity::add((string) $lot->{$counter}, $take)]);
            $remaining = Quantity::sub($remaining, $take);

            if (! Quantity::isPositive($remaining)) {
                break;
            }
        }
    }

    /**
     * @param  list<TransferStatus>  $statuses
     */
    private function lock(TransferOrder $order, array $statuses): TransferOrder
    {
        $locked = TransferOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        $values = array_map(static fn (TransferStatus $status): string => $status->value, $statuses);

        if (! in_array($locked->status, $values, true)) {
            throw new StockRuleViolation("The transfer order is {$locked->status}: the operation needs status ".implode(' or ', $values).'.');
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
