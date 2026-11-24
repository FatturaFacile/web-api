<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

/**
 * A new transfer order (source and destination warehouses, lines) or a receipt of one (lines received, optional
 * destination bins; without lines everything in transit is received).
 */
final class TransferOrderRequest extends FormRequest
{
    use ValidatesTenantRecords;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->routeIs('inventory.transfer-orders.receive')) {
            return [
                'lines' => ['sometimes', 'array', 'max:500'],
                'lines.*.line_id' => ['required', 'uuid', 'distinct', $this->tenantExists('transfer_order_lines')],
                'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
                'lines.*.destination_bin_id' => ['nullable', 'uuid', $this->tenantExists('warehouse_bins')],
            ];
        }

        return [
            'source_warehouse_id' => ['required', 'uuid', $this->tenantExists('warehouses')],
            'destination_warehouse_id' => ['required', 'uuid', 'different:source_warehouse_id', $this->tenantExists('warehouses')],
            'requested_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.stock_item_id' => ['required', 'uuid', $this->tenantExists('stock_items')],
            'lines.*.lot_id' => ['nullable', 'uuid', $this->tenantExists('stock_lots')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
