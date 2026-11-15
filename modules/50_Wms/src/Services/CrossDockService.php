<?php

declare(strict_types=1);

namespace Modules\Wms\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Wms\Models\WmsCrossDockLine;
use Modules\Wms\Models\WmsCrossDockOrder;
use Modules\Wms\Models\WmsDockDoor;

/**
 * Cross-dock orders: goods received on the inbound side leave on the outbound side without being stored. The order is
 * planned, received (what arrived is recorded per line), matched to the outbound side (outbound quantity per line, never
 * more than what is available) and shipped.
 */
final class CrossDockService
{
    private const array LoadRelations = ['lines.stockItem:id,sku,name'];

    public function __construct(private readonly WmsNumbering $numbering) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): WmsCrossDockOrder
    {
        return DB::transaction(function () use ($tenantId, $data): WmsCrossDockOrder {
            $this->assertDoors($tenantId, (string) $data['warehouse_id'], $data);
            $lines = $this->normalizeLines((array) ($data['lines'] ?? []));

            $order = WmsCrossDockOrder::create([
                ...array_diff_key($data, ['lines' => true]),
                'tenant_id' => $tenantId,
                'number' => $this->numbering->next(WmsCrossDockOrder::class, 'number', 'CD', $tenantId),
            ]);
            $this->writeLines($order, $lines);

            return $this->present($order);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(WmsCrossDockOrder $order, array $data): WmsCrossDockOrder
    {
        return DB::transaction(function () use ($order, $data): WmsCrossDockOrder {
            $locked = $this->lock($order);
            $this->assertStatus($locked, [WmsCrossDockOrder::Planned]);
            $this->assertDoors((string) $locked->tenant_id, (string) $locked->warehouse_id, $data);

            $lines = array_key_exists('lines', $data) ? $this->normalizeLines((array) $data['lines']) : null;
            $locked->update(array_diff_key($data, ['lines' => true, 'warehouse_id' => true]));

            if ($lines !== null) {
                $locked->lines()->delete();
                $this->writeLines($locked, $lines);
            }

            return $this->present($locked);
        });
    }

    public function delete(WmsCrossDockOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            $locked = $this->lock($order);
            $this->assertStatus($locked, [WmsCrossDockOrder::Planned, WmsCrossDockOrder::Cancelled]);
            $locked->lines()->delete();
            $locked->delete();
        });
    }

    /**
     * @param  list<array{line_id: string, quantity: string|int|float}>|null  $received  Without lines the whole inbound quantity is received.
     */
    public function receive(WmsCrossDockOrder $order, ?array $received): WmsCrossDockOrder
    {
        return DB::transaction(function () use ($order, $received): WmsCrossDockOrder {
            $locked = $this->lock($order);
            $this->assertStatus($locked, [WmsCrossDockOrder::Planned]);
            $lines = $locked->lines()->get()->keyBy('id');
            $quantities = $received === null
                ? $lines->map(static fn (WmsCrossDockLine $line): string => (string) $line->inbound_quantity)->all()
                : $this->quantitiesByLine($lines->all(), $received, 'quantity');

            foreach ($lines as $line) {
                $quantity = $quantities[$line->id] ?? '0';

                if (bccomp($quantity, (string) $line->inbound_quantity, 4) > 0) {
                    throw ValidationException::withMessages(['lines' => [__('wms_errors.cross_dock.received_over_inbound', ['inbound' => (string) $line->inbound_quantity])]]);
                }

                if (bccomp($quantity, (string) $line->outbound_quantity, 4) < 0) {
                    throw ValidationException::withMessages(['lines' => [__('wms_errors.cross_dock.received_below_outbound', ['outbound' => (string) $line->outbound_quantity])]]);
                }

                $line->update(['received_quantity' => $quantity]);
            }

            $locked->update(['status' => WmsCrossDockOrder::Received, 'received_at' => now()]);

            return $this->present($locked);
        });
    }

    /**
     * @param  list<array{line_id: string, outbound_quantity: string|int|float}>  $allocation
     */
    public function allocate(WmsCrossDockOrder $order, array $allocation): WmsCrossDockOrder
    {
        return DB::transaction(function () use ($order, $allocation): WmsCrossDockOrder {
            $locked = $this->lock($order);
            $this->assertStatus($locked, [WmsCrossDockOrder::Planned, WmsCrossDockOrder::Received]);
            $lines = $locked->lines()->get()->keyBy('id');
            $quantities = $this->quantitiesByLine($lines->all(), $allocation, 'outbound_quantity');

            foreach ($quantities as $lineId => $quantity) {
                /** @var WmsCrossDockLine $line */
                $line = $lines->get($lineId);
                $available = $locked->status === WmsCrossDockOrder::Received ? (string) $line->received_quantity : (string) $line->inbound_quantity;

                if (bccomp($quantity, $available, 4) > 0) {
                    throw ValidationException::withMessages(['lines' => [__('wms_errors.cross_dock.outbound_over_available', ['available' => $available])]]);
                }

                $line->update(['outbound_quantity' => $quantity]);
            }

            return $this->present($locked);
        });
    }

    public function ship(WmsCrossDockOrder $order): WmsCrossDockOrder
    {
        return DB::transaction(function () use ($order): WmsCrossDockOrder {
            $locked = $this->lock($order);
            $this->assertStatus($locked, [WmsCrossDockOrder::Received]);

            if ($locked->lines()->get()->every(static fn (WmsCrossDockLine $line): bool => bccomp((string) $line->outbound_quantity, '0', 4) <= 0)) {
                throw ValidationException::withMessages(['status' => [__('wms_errors.cross_dock.nothing_matched')]]);
            }

            $locked->update(['status' => WmsCrossDockOrder::Shipped, 'shipped_at' => now()]);

            return $this->present($locked);
        });
    }

    public function cancel(WmsCrossDockOrder $order): WmsCrossDockOrder
    {
        return DB::transaction(function () use ($order): WmsCrossDockOrder {
            $locked = $this->lock($order);
            $this->assertStatus($locked, [WmsCrossDockOrder::Planned, WmsCrossDockOrder::Received]);
            $locked->update(['status' => WmsCrossDockOrder::Cancelled]);

            return $this->present($locked);
        });
    }

    private function present(WmsCrossDockOrder $order): WmsCrossDockOrder
    {
        return $order->refresh()->load(self::LoadRelations);
    }

    private function lock(WmsCrossDockOrder $order): WmsCrossDockOrder
    {
        return WmsCrossDockOrder::query()->lockForUpdate()->findOrFail($order->id);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertStatus(WmsCrossDockOrder $order, array $allowed): void
    {
        if (! in_array($order->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => [__('wms_errors.cross_dock.invalid_transition', ['status' => $order->status])]]);
        }
    }

    /**
     * The doors must belong to the warehouse of the order and accept the direction they are used for.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertDoors(string $tenantId, string $warehouseId, array $data): void
    {
        foreach (['inbound_door_id' => 'inbound', 'outbound_door_id' => 'outbound'] as $field => $direction) {
            if (($data[$field] ?? null) === null) {
                continue;
            }

            $door = WmsDockDoor::query()->where('tenant_id', $tenantId)->find($data[$field]);

            if ($door === null || $door->warehouse_id !== $warehouseId) {
                throw ValidationException::withMessages([$field => [__('wms_errors.cross_dock.door_other_warehouse')]]);
            }

            if (! in_array($door->direction, [$direction, 'both'], true)) {
                throw ValidationException::withMessages([$field => [__('wms_errors.cross_dock.door_direction', ['direction' => $direction])]]);
            }
        }
    }

    /**
     * One line per stock item, the outbound quantity within the inbound one.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<array{stock_item_id: string, inbound_quantity: string, outbound_quantity: string}>
     */
    private function normalizeLines(array $lines): array
    {
        $seen = [];
        $normalized = [];

        foreach ($lines as $index => $line) {
            $item = (string) $line['stock_item_id'];
            $inbound = bcadd((string) $line['inbound_quantity'], '0', 4);
            $outbound = bcadd((string) ($line['outbound_quantity'] ?? '0'), '0', 4);

            if (isset($seen[$item])) {
                throw ValidationException::withMessages(["lines.$index.stock_item_id" => [__('wms_errors.cross_dock.duplicate_item')]]);
            }

            if (bccomp($outbound, $inbound, 4) > 0) {
                throw ValidationException::withMessages(["lines.$index.outbound_quantity" => [__('wms_errors.cross_dock.outbound_over_inbound', ['inbound' => $inbound])]]);
            }

            $seen[$item] = true;
            $normalized[] = ['stock_item_id' => $item, 'inbound_quantity' => $inbound, 'outbound_quantity' => $outbound];
        }

        return $normalized;
    }

    /**
     * @param  list<array{stock_item_id: string, inbound_quantity: string, outbound_quantity: string}>  $lines
     */
    private function writeLines(WmsCrossDockOrder $order, array $lines): void
    {
        foreach ($lines as $line) {
            WmsCrossDockLine::create([...$line, 'tenant_id' => $order->tenant_id, 'cross_dock_order_id' => $order->id]);
        }
    }

    /**
     * @param  array<string, WmsCrossDockLine>  $lines
     * @param  array<int, array<string, mixed>>  $given
     * @return array<string, string>
     */
    private function quantitiesByLine(array $lines, array $given, string $key): array
    {
        $quantities = [];

        foreach ($given as $index => $row) {
            $lineId = (string) $row['line_id'];

            if (! isset($lines[$lineId])) {
                throw ValidationException::withMessages(["lines.$index.line_id" => [__('wms_errors.cross_dock.line_not_found')]]);
            }

            $quantities[$lineId] = bcadd((string) $row[$key], '0', 4);
        }

        return $quantities;
    }
}
