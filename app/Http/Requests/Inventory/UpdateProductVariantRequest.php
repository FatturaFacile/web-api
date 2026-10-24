<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Actions\Inventory\GenerateProductVariantsAction;
use App\Models\Product;
use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Update of one variant. Attributes sent replace the option attributes (size, colour, ...) of the variant.
 */
class UpdateProductVariantRequest extends ProductVariantRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sku' => ['sometimes', 'string', 'max:50', Rule::unique('products', 'sku')->where('tenant_id', app(TenantContext::class)->getTenantId())->ignore($this->route('variantId'))],
            'attributes' => ['sometimes', 'array', 'min:1'],
            'discontinue_on_zero_stock' => ['sometimes', 'boolean'],
            'price' => ['sometimes', 'numeric', 'min:0'],
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
                if (! $this->has('attributes')) {
                    return;
                }

                $this->validateOptionAttributes($validator);

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $variant = Product::variantsOnly()->findOrFail($this->route('variantId'));
                $parent = Product::findOrFail($variant->parent_id);

                if (app(GenerateProductVariantsAction::class)->combinationExists($parent, $this->optionAttributes(), $variant->id)) {
                    $validator->errors()->add('attributes', __('inventory.duplicate_combination'));
                }
            },
        ];
    }
}
