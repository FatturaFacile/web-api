<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creation of a product. `min_stock` is the reorder threshold watched by the low stock dashboard widget; null means
 * the product is not monitored.
 */
class StoreProductRequest extends FormRequest
{
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
            'sku' => ['required', 'string', 'max:50'],
            'barcode' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'unit_measure' => ['required', 'string', 'max:10'],
            'cost_price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['required', 'numeric', 'min:0'],
            'hs_code' => ['nullable', 'string'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
