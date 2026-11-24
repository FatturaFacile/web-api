<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

final class AllocateStockReservationRequest extends FormRequest
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
            'source_type' => ['required', 'string', 'max:100'],
            'source_id' => ['required', 'uuid'],
            'warehouse_bin_id' => ['nullable', 'uuid', $this->tenantExists('warehouse_bins')],
            'lot_id' => ['nullable', 'uuid', $this->tenantExists('stock_lots')],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
