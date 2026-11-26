<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Http\Requests;

use App\Http\Requests\Concerns\CustomsOriginRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ProductCatalog\Domain\Enums\DeltaType;
use Modules\ProductCatalog\Domain\Enums\KitType;
use Modules\ProductCatalog\Domain\Enums\ProductType;
use Modules\ProductCatalog\Domain\Enums\RoundingMode;
use Modules\ProductCatalog\Http\Requests\Concerns\ScopesToTenant;

/**
 * Create and update of a catalog product (the type, kit, unit, price, barcode and CH / IT classification fields).
 */
final class SaveProductRequest extends FormRequest
{
    use CustomsOriginRules;
    use ScopesToTenant;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';
        $current = $this->route('id');
        $sku = $this->tenantUnique('products', 'sku');

        if (! $creating && is_string($current)) {
            $sku->ignore($current);
        }

        return [
            'sku' => [$required, 'string', 'max:50', 'regex:/^[A-Za-z0-9._\-\/]+$/', $sku],
            'name' => [$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'product_type' => ['sometimes', Rule::enum(ProductType::class)],
            'kit_type' => ['sometimes', 'nullable', Rule::enum(KitType::class)],
            'unit_of_measure_id' => ['sometimes', 'nullable', 'uuid', $this->sharedExists('units_of_measure')],
            'uom_rounding' => ['sometimes', Rule::enum(RoundingMode::class)],
            'uom_precision' => ['sometimes', 'integer', 'min:0', 'max:6'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:50'],
            'cost_price' => ['sometimes', 'numeric', 'min:0', 'max:999999999'],
            'sale_price' => ['sometimes', 'numeric', 'min:0', 'max:999999999'],
            'tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'category_id' => ['sometimes', 'nullable', 'uuid', $this->tenantExists('product_categories')],
            'brand_id' => ['sometimes', 'nullable', 'uuid', $this->tenantExists('product_brands')],
            'track_inventory' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'min_stock' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'price_delta' => ['sometimes', 'nullable', 'numeric', 'min:-999999999', 'max:999999999'],
            'price_delta_type' => ['sometimes', 'nullable', Rule::enum(DeltaType::class)],
            'cost_delta' => ['sometimes', 'nullable', 'numeric', 'min:-999999999', 'max:999999999'],
            'cost_delta_type' => ['sometimes', 'nullable', Rule::enum(DeltaType::class)],
            'price_overridden' => ['sometimes', 'boolean'],
            'cost_overridden' => ['sometimes', 'boolean'],
            'vat_code_it_id' => ['sometimes', 'nullable', 'uuid', $this->sharedExists('vat_codes')],
            'vat_code_ch_id' => ['sometimes', 'nullable', 'uuid', $this->sharedExists('vat_codes')],
            'tares_code' => ['sometimes', 'nullable', 'string', 'regex:/^\d{8}$/'],
            'generate_barcode' => ['sometimes', 'boolean'],
            ...$this->customsOriginRules(),
        ];
    }
}
