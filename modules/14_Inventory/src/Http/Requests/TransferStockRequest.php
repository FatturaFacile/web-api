<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

final class TransferStockRequest extends FormRequest
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
        return [
            'stock_item_id' => ['required', 'uuid', $this->tenantExists('stock_items')],
            'warehouse_id' => ['required', 'uuid', $this->tenantExists('warehouses')],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'movement_type' => ['sometimes', 'string', 'in:INTERNAL_TRANSFER'],
            'source_bin_id' => ['required', 'uuid', $this->tenantExists('warehouse_bins')],
            'destination_bin_id' => ['required', 'uuid', 'different:source_bin_id', $this->tenantExists('warehouse_bins')],
            'stock_lot_id' => ['nullable', 'uuid', $this->tenantExists('stock_lots')],
            'stock_serial_number_id' => ['nullable', 'uuid', $this->tenantExists('stock_serial_numbers')],
            'unit_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'reference_type' => ['nullable', 'string', 'max:255'],
            'reference_id' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
