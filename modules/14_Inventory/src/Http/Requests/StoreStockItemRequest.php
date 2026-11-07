<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Enums\PickingStrategy;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

final class StoreStockItemRequest extends FormRequest
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
            'category_id' => ['required', 'uuid'],
            'sku' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'uom' => ['required', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:2000'],
            'barcode_ean13' => ['nullable', 'string', 'max:13'],
            'barcode_custom' => ['nullable', 'string', 'max:100'],
            'tracking_type' => ['required', 'string', 'in:none,lot,serial'],
            'standard_cost' => ['nullable', 'numeric', 'min:0'],
            'moving_average_cost' => ['nullable', 'numeric', 'min:0'],
            'product_id' => ['nullable', 'uuid', $this->tenantExists('products')],
            'picking_strategy' => ['nullable', Rule::enum(PickingStrategy::class)],
            'shelf_life_days' => ['nullable', 'integer', 'min:1'],
            'min_remaining_shelf_life_days' => ['nullable', 'integer', 'min:0'],
            'net_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'volume_m3' => ['nullable', 'numeric', 'min:0'],
            'units_per_carton' => ['nullable', 'integer', 'min:1'],
            'cartons_per_pallet' => ['nullable', 'integer', 'min:1'],
            'carton_tare_kg' => ['nullable', 'numeric', 'min:0'],
            'pallet_tare_kg' => ['nullable', 'numeric', 'min:0'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
