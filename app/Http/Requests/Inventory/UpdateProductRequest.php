<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update of a product: only the fields sent change. `min_stock` may be set to null to stop monitoring the product.
 */
class UpdateProductRequest extends FormRequest
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
            'sku' => ['sometimes', 'string', 'max:50', Rule::unique('products', 'sku')->where('tenant_id', app(TenantContext::class)->getTenantId())->ignore($this->route('id'))],
            'barcode' => ['nullable', 'string', 'max:50'],
            'name' => ['sometimes', 'string'],
            'description' => ['nullable', 'string'],
            'unit_measure' => ['sometimes', 'string', 'max:10'],
            'cost_price' => ['sometimes', 'numeric', 'min:0'],
            'sale_price' => ['sometimes', 'numeric', 'min:0'],
            'tax_rate' => ['sometimes', 'numeric', 'min:0'],
            'hs_code' => ['nullable', 'string'],
            'track_inventory' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
