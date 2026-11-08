<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

final class StoreInventoryAdjustmentRequest extends FormRequest
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
            'warehouse_id' => ['required', 'uuid', $this->tenantExists('warehouses')],
            'reason' => ['required', 'string', 'in:cycle_count_variance,damage_loss,obsolete_write_off,found_stock,scrap'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.stock_item_id' => ['required', 'uuid', $this->tenantExists('stock_items')],
            'lines.*.warehouse_bin_id' => ['required', 'uuid', $this->tenantExists('warehouse_bins')],
            'lines.*.quantity_delta' => ['required', 'numeric', 'not_in:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'lines.*.lot_id' => ['nullable', 'uuid', $this->tenantExists('stock_lots')],
        ];
    }
}
