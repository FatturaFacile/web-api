<?php

declare(strict_types=1);

namespace Modules\Distribution\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Distribution\Models\TransferMeta;
use Modules\Inventory\Enums\DiscrepancyStatus;
use Modules\Inventory\Enums\TransferStatus;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\TransferDiscrepancy;
use Modules\Inventory\Models\TransferOrder;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\TransferOrderService;

/**
 * The distribution view of the transfer order lifecycle (draft -> approved -> in transit -> partially received -> received /
 * discrepancy -> closed | cancelled). Stock moves only through the inventory TransferOrderService: dispatch takes it from the
 * source into the transit bin of the destination, receipt moves it from transit to the destination, and a discrepancy never
 * leaves stock orphaned in transit because the resolution (write-off, return to source, carrier claim) is posted here.
 * This service adds the lane lead time, the transport data and the guards of the network.
 */
final readonly class TransferWorkflowService
{
    /** Receipt resolutions the planner can choose for the units that did not arrive in good condition. */
    public const array RESOLUTIONS = ['keep_in_transit', 'write_off', 'return_to_source', 'carrier_claim'];

    public function __construct(
        private TransferOrderService $transfers,
        private NetworkService $network,
    ) {}

    /**
     * @param  list<array{stock_item_id: string, lot_id?: ?string, quantity: string|int|float}>  $lines
     */
    public function create(Warehouse $source, Warehouse $destination, array $lines, ?string $userId, ?string $requestedDate = null, ?string $notes = null, bool $autoApprove = false): TransferOrder
    {
        $this->network->assertTransferable($source, $destination);

        return DB::transaction(function () use ($source, $destination, $lines, $userId, $requestedDate, $notes, $autoApprove): TransferOrder {
            $order = $this->transfers->create($source, $destination, $lines, $userId, $requestedDate, $notes, $this->purposeFor($source, $destination));
            $lane = $this->network->laneBetween((string) $source->id, (string) $destination->id);
            $base = $requestedDate !== null ? Carbon::parse($requestedDate) : Carbon::today();

            TransferMeta::create([
                'transfer_order_id' => $order->id,
                'lane_id' => $lane?->id,
                'expected_arrival_date' => $lane !== null ? $base->copy()->addDays($lane->totalLeadDays())->toDateString() : $base->toDateString(),
            ]);

            return $autoApprove ? $this->transfers->approve($order, $userId) : $order;
        });
    }

    /**
     * The transfer purpose the route policy of the logistics module expects for the two warehouse types: goods sent to a customer
     * consignment warehouse, and unsold goods coming back from it, have their own purposes (and transport documents).
     */
    private function purposeFor(Warehouse $source, Warehouse $destination): string
    {
        return match (true) {
            $destination->warehouse_type === 'customer_consignment' => 'customer_consignment',
            $source->warehouse_type === 'customer_consignment' => 'consignment_return',
            default => 'inter_warehouse',
        };
    }

    public function approve(TransferOrder $order, ?string $userId): TransferOrder
    {
        return $this->transfers->approve($order, $userId);
    }

    /**
     * Ships the transfer and records carrier, tracking number, transport document and expected arrival.
     *
     * @param  array{carrier?: ?string, tracking_number?: ?string, ddt_reference?: ?string, expected_arrival_date?: ?string}  $transport
     */
    public function dispatch(TransferOrder $order, ?string $userId, array $transport = []): TransferOrder
    {
        $this->network->assertTransferable(
            Warehouse::query()->findOrFail($order->source_warehouse_id),
            Warehouse::query()->findOrFail($order->destination_warehouse_id),
        );

        return DB::transaction(function () use ($order, $userId, $transport): TransferOrder {
            $shipped = $this->transfers->ship($order, $userId, $transport);
            $meta = TransferMeta::query()->firstOrNew(['transfer_order_id' => $shipped->id]);

            if ($transport['expected_arrival_date'] ?? null) {
                $expected = Carbon::parse((string) $transport['expected_arrival_date'])->toDateString();
            } elseif ($meta->expected_arrival_date !== null && $meta->expected_arrival_date->greaterThanOrEqualTo(Carbon::today())) {
                $expected = $meta->expected_arrival_date->toDateString();
            } else {
                $lane = $this->network->laneBetween((string) $shipped->source_warehouse_id, (string) $shipped->destination_warehouse_id);
                $expected = Carbon::today()->addDays($lane?->totalLeadDays() ?? 0)->toDateString();
            }

            $meta->fill([
                'carrier' => $transport['carrier'] ?? $meta->carrier,
                'tracking_number' => $transport['tracking_number'] ?? $meta->tracking_number,
                'ddt_reference' => $transport['ddt_reference'] ?? $meta->ddt_reference,
                'expected_arrival_date' => $expected,
                'dispatched_at' => Carbon::now(),
            ])->save();

            return $shipped;
        });
    }

    /**
     * Receives units per line (good and damaged). With `$final` the receipt closes the transfer: whatever is still missing becomes a
     * discrepancy, and the chosen resolution is posted at once so nothing stays orphaned in transit. `keep_in_transit` leaves the
     * remainder on the way (a second parcel) and keeps the transfer partially received.
     *
     * @param  list<array{line_id: string, quantity: string|int|float, damaged_quantity?: string|int|float|null, destination_bin_id?: ?string}>  $lines
     */
    public function receive(TransferOrder $order, array $lines, ?string $userId, bool $final = false, ?string $resolution = null): TransferOrder
    {
        if ($resolution !== null && ! in_array($resolution, self::RESOLUTIONS, true)) {
            throw new StockRuleViolation('Invalid receipt resolution.');
        }

        $closing = $final && $resolution !== 'keep_in_transit';

        return DB::transaction(function () use ($order, $lines, $userId, $closing, $resolution): TransferOrder {
            $receipts = array_map(static fn (array $line): array => [
                'line_id' => $line['line_id'],
                'quantity' => $line['quantity'],
                'damaged_qty' => $line['damaged_quantity'] ?? 0,
                'destination_bin_id' => $line['destination_bin_id'] ?? null,
            ], $lines);

            $received = $this->transfers->receive($order, $receipts, $userId, $closing);

            if ($received->status !== TransferStatus::DiscrepancyPending->value || ! in_array($resolution, ['write_off', 'return_to_source', 'carrier_claim'], true)) {
                return $received;
            }

            $status = match ($resolution) {
                'return_to_source' => DiscrepancyStatus::ReturnedToOrigin,
                'carrier_claim' => DiscrepancyStatus::CarrierClaim,
                default => DiscrepancyStatus::ApprovedWriteOff,
            };

            foreach (TransferDiscrepancy::query()->where('transfer_order_id', $received->id)->where('status', DiscrepancyStatus::PendingApproval->value)->get() as $discrepancy) {
                $this->transfers->resolveDiscrepancy($discrepancy, $status, $userId);
            }

            return TransferOrder::query()->findOrFail($received->id);
        });
    }

    public function cancel(TransferOrder $order): TransferOrder
    {
        return $this->transfers->cancel($order);
    }
}
