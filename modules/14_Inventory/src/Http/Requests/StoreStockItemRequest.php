<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreStockItemRequest extends FormRequest
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
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
