<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\RepairOrder;
use Modules\Inventory\Models\RepairOrderLine;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockSerialNumber;

/**
 * Warranty status of a serial and the repairs opened on it. A repair copies the customer and the purchase value of the
 * serial at intake, and whether the warranty covered that day, so later changes do not rewrite it. The warranty dates
 * are the ones entered on the serial: no period is assumed.
 */
final class RepairOrderService
{
    public function __construct(
        private readonly DoubleEntryMovementServiceInterface $movements
    ) {}

    /**
     * @return array{status: string, days_left: ?int}
     */
    public function warrantyStatus(StockSerialNumber $serial, ?CarbonImmutable $on = null): array
    {
        $on ??= CarbonImmutable::today();

        if ($serial->warranty_end_date === null) {
            return ['status' => 'none', 'days_left' => null];
        }

        $end = CarbonImmutable::parse($serial->warranty_end_date)->startOfDay();

        if ($serial->warranty_start_date !== null && $on->lt(CarbonImmutable::parse($serial->warranty_start_date)->startOfDay())) {
            return ['status' => 'not_started', 'days_left' => null];
        }

        return $on->gt($end)
            ? ['status' => 'expired', 'days_left' => 0]
            : ['status' => 'active', 'days_left' => (int) $on->diffInDays($end)];
    }

    /**
     * @param  array{fault_description: string, contact_id?: ?string}  $data
     */
    public function open(StockSerialNumber $serial, array $data, string $userId): RepairOrder
    {
        $contactId = $data['contact_id'] ?? $serial->contact_id;

        if ($contactId === null) {
            throw new StockRuleViolation('This serial has no customer: name one to open the repair.');
        }

        return DB::transaction(function () use ($serial, $data, $userId, $contactId): RepairOrder {
            $repair = RepairOrder::create([
                'tenant_id' => $serial->tenant_id,
                'repair_number' => 'REP-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
                'stock_serial_number_id' => $serial->id,
                'stock_item_id' => $serial->stock_item_id,
                'contact_id' => $contactId,
                'purchase_value' => $serial->purchase_value,
                'purchase_currency' => $serial->purchase_currency,
                'is_under_warranty' => $this->warrantyStatus($serial)['status'] === 'active',
                'status' => 'received',
                'fault_description' => $data['fault_description'],
                'received_at' => now(),
                'created_by' => $userId,
            ]);

            $serial->update(['status' => 'in_repair']);

            return $repair;
        });
    }

    /**
     * @param  array<string, mixed>  $changes  status and repair notes
     */
    public function advance(RepairOrder $repair, array $changes): RepairOrder
    {
        return DB::transaction(function () use ($repair, $changes): RepairOrder {
            $locked = RepairOrder::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            $status = $changes['status'];

            if (! in_array($status, RepairOrder::TRANSITIONS[$locked->status] ?? [], true)) {
                throw new StockRuleViolation("A repair in status [{$locked->status}] cannot become [{$status}].");
            }

            $locked->update(array_filter([
                'status' => $status,
                'diagnosis' => $changes['diagnosis'] ?? null,
                'resolution' => $changes['resolution'] ?? null,
                'labour_amount' => $changes['labour_amount'] ?? null,
                'delivered_at' => $status === 'delivered' ? now() : null,
            ], static fn (mixed $value): bool => $value !== null));

            if (in_array($status, ['delivered', 'cancelled'], true)) {
                StockSerialNumber::query()->whereKey($locked->stock_serial_number_id)->update(['status' => 'delivered']);
            }

            return $locked;
        });
    }

    /**
     * Takes a spare part out of stock for the repair. The value comes from the movement, so stock and repair agree.
     *
     * @param  array{stock_item_id: string, quantity: mixed, source_bin_id: string, lot_id?: ?string}  $data
     */
    public function addPart(RepairOrder $repair, array $data, string $userId): RepairOrderLine
    {
        return DB::transaction(function () use ($repair, $data, $userId): RepairOrderLine {
            $locked = RepairOrder::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['ready', 'delivered', 'cancelled'], true)) {
                throw new StockRuleViolation("A repair in status [{$locked->status}] takes no more parts.");
            }

            $part = StockItem::query()->findOrFail($data['stock_item_id']);
            $movement = $this->movements->executeTransfer(new StockTransferDTO(
                stockItemId: $part->id,
                sourceBinId: $data['source_bin_id'],
                destinationBinId: null,
                quantity: (float) $data['quantity'],
                movementType: 'repair_consumption',
                lotId: $data['lot_id'] ?? null,
                originDocumentType: 'repair_orders',
                originDocumentId: $locked->id,
                userId: $userId
            ));

            $total = Decimal::round2(Decimal::mul((string) $data['quantity'], (string) $movement->unit_cost));

            $line = RepairOrderLine::create([
                'tenant_id' => $locked->tenant_id,
                'repair_order_id' => $locked->id,
                'stock_item_id' => $part->id,
                'lot_id' => $data['lot_id'] ?? null,
                'quantity' => $data['quantity'],
                'unit_cost' => $movement->unit_cost,
                'line_total' => $total,
                'stock_movement_id' => $movement->id,
            ]);

            $locked->update(['parts_amount' => bcadd((string) $locked->parts_amount, $total, 2)]);

            return $line;
        });
    }
}
