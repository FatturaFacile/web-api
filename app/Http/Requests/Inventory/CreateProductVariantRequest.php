<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Actions\Inventory\GenerateProductVariantsAction;
use App\Models\Product;
use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Manual creation of one variant of a parent product. The SKU is generated from the attributes when omitted and the
 * price defaults to the parent price.
 */
class CreateProductVariantRequest extends ProductVariantRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sku' => ['nullable', 'string', 'max:50', Rule::unique('products', 'sku')->where('tenant_id', app(TenantContext::class)->getTenantId())],
            'attributes' => ['required', 'array', 'min:1'],
            'discontinue_on_zero_stock' => ['sometimes', 'boolean'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'barcode' => ['nullable', 'string', 'max:50'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateOptionAttributes($validator);

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $parent = Product::findOrFail($this->route('parentId'));

                if (app(GenerateProductVariantsAction::class)->combinationExists($parent, $this->optionAttributes())) {
                    $validator->errors()->add('attributes', __('inventory.duplicate_combination'));
                }
            },
        ];
    }
}
