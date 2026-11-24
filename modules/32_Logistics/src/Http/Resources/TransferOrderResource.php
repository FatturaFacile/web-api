<?php

declare(strict_types=1);

namespace Modules\Logistics\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\TransferDiscrepancy;
use Modules\Inventory\Models\TransferOrder;
use Modules\Inventory\Models\TransferOrderLine;
use Modules\Inventory\Support\Quantity;

/**
 * Logistics shape of an inventory transfer order: warehouses as origin/destination, lines per catalog product with
 * shipped / received / damaged / outstanding quantities, and the discrepancies awaiting a manager decision.
 *
 * @mixin TransferOrder
 */
final class TransferOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TransferOrder $order */
        $order = $this->resource;

        return [
            'id' => (string) $order->id,
            'transfer_number' => (string) $order->transfer_number,
            'purpose' => (string) $order->purpose,
            'status' => (string) $order->status,
            'origin_warehouse_id' => (string) $order->source_warehouse_id,
            'source_warehouse_id' => (string) $order->source_warehouse_id,
            'destination_warehouse_id' => (string) $order->destination_warehouse_id,
            'transit_bin_id' => $order->transit_bin_id,
            'delivery_note_id' => $order->delivery_note_id,
            'inter_company_invoice_id' => $order->inter_company_invoice_id,
            'is_inter_company' => (bool) $order->is_inter_company,
            'shipped_at' => $order->shipped_at?->toIso8601String(),
            'received_at' => $order->received_at?->toIso8601String(),
            'approved_at' => $order->approved_at?->toIso8601String(),
            'closed_at' => $order->closed_at?->toIso8601String(),
            'notes' => $order->notes,
            'lines' => $this->whenLoaded('lines', fn (): array => $order->lines->map(fn (TransferOrderLine $line): array => self::line($line))->values()->all()),
            'discrepancies' => $this->whenLoaded('discrepancies', fn (): array => $order->discrepancies->map(fn (TransferDiscrepancy $d): array => self::discrepancy($d))->values()->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function line(TransferOrderLine $line): array
    {
        $item = $line->relationLoaded('item') ? $line->item : null;

        return [
            'id' => (string) $line->id,
            'stock_item_id' => (string) $line->stock_item_id,
            'product_id' => $item?->product_id !== null ? (string) $item->product_id : null,
            'description' => $item?->name,
            'quantity' => (string) $line->quantity,
            'shipped_qty' => (string) $line->quantity_shipped,
            'received_qty' => (string) $line->quantity_received,
            'damaged_qty' => (string) $line->quantity_damaged,
            'discrepancy_qty' => (string) $line->discrepancy_qty,
            'discrepancy_reason' => $line->discrepancy_reason,
            'outstanding_qty' => Quantity::sub(Quantity::sub((string) $line->quantity_shipped, (string) $line->quantity_received), (string) $line->quantity_damaged),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function discrepancy(TransferDiscrepancy $d): array
    {
        return [
            'id' => (string) $d->id,
            'transfer_order_id' => (string) $d->transfer_order_id,
            'stock_transfer_order_id' => (string) $d->transfer_order_id,
            'transfer_order_line_id' => (string) $d->transfer_order_line_id,
            'stock_transfer_order_line_id' => (string) $d->transfer_order_line_id,
            'stock_item_id' => (string) $d->stock_item_id,
            'discrepancy_qty' => (string) $d->discrepancy_qty,
            'shortage_qty' => (string) $d->shortage_qty,
            'damage_qty' => (string) $d->damage_qty,
            'discrepancy_reason' => $d->discrepancy_reason,
            'status' => (string) $d->status,
            'resolved_by' => $d->resolved_by,
            'resolved_at' => $d->resolved_at?->toIso8601String(),
        ];
    }
}
