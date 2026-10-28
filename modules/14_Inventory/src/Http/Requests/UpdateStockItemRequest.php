<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateStockItemRequest extends FormRequest
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
            'category_id' => ['sometimes', 'uuid'],
            'sku' => ['sometimes', 'string', 'max:100'],
            'name' => ['sometimes', 'string', 'max:255'],
            'uom' => ['sometimes', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:2000'],
            'barcode_ean13' => ['nullable', 'string', 'max:13'],
            'barcode_custom' => ['nullable', 'string', 'max:100'],
            'tracking_type' => ['sometimes', 'string', 'in:none,lot,serial'],
            'standard_cost' => ['sometimes', 'numeric', 'min:0'],
            'moving_average_cost' => ['sometimes', 'numeric', 'min:0'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
