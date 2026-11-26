<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ProductCatalog\Models\CatalogKitComponent;
use Modules\ProductCatalog\Models\CatalogPackaging;
use Modules\ProductCatalog\Models\CatalogProductAxis;
use Modules\ProductCatalog\Models\CatalogVariantValue;

/**
 * A catalog product: type, kit, units, prices, barcode and the CH / IT classification.
 *
 * @mixin Product
 */
final class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'product_type' => $this->product_type ?? 'storable',
            'kit_type' => $this->kit_type,
            'is_variant' => (bool) $this->is_variant,
            'parent_id' => $this->parent_id,
            'parent_sku' => $this->whenLoaded('parent', fn (): ?string => $this->parent?->sku),
            'variants_count' => $this->whenCounted('variants'),
            'attributes' => $this->is_variant ? $this->optionAttributes() : (object) [],
            'unit_of_measure_id' => $this->unit_of_measure_id,
            'unit_measure' => $this->unit_measure,
            'purchase_uom_id' => $this->purchase_uom_id,
            'sales_uom_id' => $this->sales_uom_id,
            'uom_rounding' => $this->uom_rounding ?? 'half_up',
            'uom_precision' => (int) ($this->uom_precision ?? 4),
            'barcode' => $this->barcode,
            'cost_price' => $this->cost_price,
            'sale_price' => $this->sale_price,
            'tax_rate' => $this->tax_rate,
            'price_delta' => $this->price_delta,
            'price_delta_type' => $this->price_delta_type,
            'cost_delta' => $this->cost_delta,
            'cost_delta_type' => $this->cost_delta_type,
            'price_overridden' => (bool) $this->price_overridden,
            'cost_overridden' => (bool) $this->cost_overridden,
            'track_inventory' => (bool) $this->track_inventory,
            'is_active' => (bool) $this->is_active,
            'category_id' => $this->category_id,
            'brand_id' => $this->brand_id,
            'min_stock' => $this->min_stock,
            'tares_code' => $this->swiss_hs_code,
            'taric_code' => $this->taric_code,
            'customs_taric_code_id' => $this->customs_taric_code_id,
            'country_of_origin' => $this->country_of_origin,
            'customs_duty_rate' => $this->customs_duty_rate,
            'net_weight_kg' => $this->net_weight_kg,
            'gross_weight_kg' => $this->gross_weight_kg,
            'vat_code_it_id' => $this->vat_code_it_id,
            'vat_code_ch_id' => $this->vat_code_ch_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The product with its matrix axes, variants (with the attribute values they carry), packaging hierarchy and kit bill of materials.
     *
     * @return array<string, mixed>
     */
    public static function detail(Product $product, Request $request): array
    {
        $base = (new self($product))->resolve($request);

        $axes = CatalogProductAxis::query()->where('product_id', $product->id)->orderBy('sort_order')->get()
            ->map(static fn (CatalogProductAxis $axis): array => ['attribute_id' => $axis->attribute_id, 'sort_order' => $axis->sort_order])->all();

        $variants = $product->variants()->orderBy('sku')->get();
        $links = CatalogVariantValue::query()->whereIn('variant_id', $variants->pluck('id'))->get()->groupBy('variant_id');

        return $base + [
            'axes' => $axes,
            'variants' => $variants->map(static fn (Product $variant): array => (new self($variant))->resolve() + [
                'value_ids' => ($links->get($variant->id) ?? collect())->pluck('attribute_value_id')->values()->all(),
            ])->all(),
            'packagings' => CatalogPackaging::query()->where('product_id', $product->id)->orderBy('level')->get()
                ->map(static fn (CatalogPackaging $packaging): array => [
                    'id' => $packaging->id,
                    'code' => $packaging->code,
                    'role' => $packaging->role->value,
                    'uom_id' => $packaging->uom_id,
                    'parent_packaging_id' => $packaging->parent_packaging_id,
                    'units_per_parent' => $packaging->units_per_parent,
                    'quantity_base' => $packaging->quantity_base,
                    'level' => $packaging->level,
                    'gtin' => $packaging->gtin,
                ])->all(),
            'kit_components' => CatalogKitComponent::query()->where('kit_id', $product->id)->orderBy('sort_order')->with('component:id,sku,name,kit_type,product_type')->get()
                ->map(static fn (CatalogKitComponent $line): array => [
                    'id' => $line->id,
                    'component_id' => $line->component_id,
                    'sku' => $line->component?->sku,
                    'name' => $line->component?->name,
                    'quantity' => $line->quantity,
                    'is_optional' => $line->is_optional,
                ])->all(),
        ];
    }
}
