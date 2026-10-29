<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreInventoryAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'in:cycle_count_variance,damage_loss,obsolete_write_off,found_stock,scrap'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.stock_item_id' => ['required', 'uuid'],
            'lines.*.warehouse_bin_id' => ['required', 'uuid'],
            'lines.*.quantity_delta' => ['required', 'numeric', 'not_in:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'lines.*.lot_id' => ['nullable', 'uuid'],
        ];
    }
}
