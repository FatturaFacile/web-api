<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Modules\ProductCatalog\Domain\Support\Dec;
use Modules\ProductCatalog\Domain\Support\Gtin;
use Modules\ProductCatalog\Domain\Support\SkuBuilder;
use Modules\ProductCatalog\Domain\Support\VariantPricing;
use Modules\ProductCatalog\Exceptions\CatalogException;
use Modules\ProductCatalog\Models\CatalogAttribute;
use Modules\ProductCatalog\Models\CatalogAttributeValue;
use Modules\ProductCatalog\Models\CatalogProductAxis;
use Modules\ProductCatalog\Models\CatalogVariantValue;

/**
 * The multi-attribute variant matrix of a parent product: the cartesian product of the chosen attribute values (for example
 * 5 sizes x 3 colours = 15 variants), each with a deterministic SKU, an in-house EAN-13 on request, and a price and cost that
 * follow the parent through the surcharges of the attribute values unless the variant carries its own surcharge or override.
 */
final class VariantMatrixService
{
    public const int MAX_COMBINATIONS = 500;

    /**
     * What the matrix would create, without writing anything.
     *
     * @param  list<array{attribute_id: string, value_ids: list<string>}>  $axes
     * @param  array<string, array{sku?: string|null, barcode?: string|null, sale_price?: string|int|float|null, cost_price?: string|int|float|null}>  $overrides  keyed by the value ids of the combination joined with `|` in axis order
     * @return list<array{key: string, values: list<string>, labels: array<string, string>, sku: string, sale_price: string, cost_price: string, exists: bool}>
     *
     * @throws CatalogException
     */
    public function preview(Product $parent, array $axes, array $overrides = []): array
    {
        $this->assertParent($parent);
        [$attributes, $values] = $this->resolveAxes($axes);
        $combinations = $this->combinations($axes);
        $existing = $this->existingSignatures($parent);
        $taken = $this->takenSkus($parent);
        $rows = [];

        foreach ($combinations as $valueIds) {
            $key = implode('|', $valueIds);
            $set = $this->signature($valueIds);
            $exists = isset($existing[$set]);
            $override = $overrides[$key] ?? [];
            $chosen = array_map(static fn (string $id): CatalogAttributeValue => $values[$id], $valueIds);
            $sku = $exists
                ? $existing[$set]
                : (($override['sku'] ?? null) ?: SkuBuilder::build($parent->sku, array_map(static fn (CatalogAttributeValue $v): string => $v->sku_token, $chosen), $taken));
            $taken[strtoupper($sku)] = true;
            $labels = [];

            foreach ($chosen as $value) {
                $labels[$attributes[$value->attribute_id]->nameIn('en')] = $value->labelIn('en');
            }

            $rows[] = [
                'key' => $key,
                'values' => $valueIds,
                'labels' => $labels,
                'sku' => $sku,
                'sale_price' => isset($override['sale_price']) ? Dec::round($override['sale_price'], 4) : $this->figure($parent->sale_price, $chosen, 'price'),
                'cost_price' => isset($override['cost_price']) ? Dec::round($override['cost_price'], 4) : $this->figure($parent->cost_price, $chosen, 'cost'),
                'exists' => $exists,
            ];
        }

        return $rows;
    }

    /**
     * Creates every missing combination; combinations that already exist are skipped. Everything happens in one transaction.
     *
     * @param  list<array{attribute_id: string, value_ids: list<string>}>  $axes
     * @param  array<string, array{sku?: string|null, barcode?: string|null, sale_price?: string|int|float|null, cost_price?: string|int|float|null}>  $overrides
     * @return array{created: list<Product>, skipped: int}
     *
     * @throws CatalogException
     */
    public function generate(Product $parent, array $axes, array $overrides = [], bool $generateBarcodes = false): array
    {
        $plan = $this->preview($parent, $axes, $overrides);
        [, $values] = $this->resolveAxes($axes);

        foreach ($overrides as $override) {
            if (($override['barcode'] ?? null) !== null && $override['barcode'] !== '' && ! Gtin::isValid($override['barcode'])) {
                throw new CatalogException('gtin_invalid', 422, ['gtin' => $override['barcode']]);
            }
        }

        $this->assertManualSkusFree($plan, $overrides);

        return DB::transaction(function () use ($parent, $axes, $plan, $values, $overrides, $generateBarcodes): array {
            $this->syncAxes($parent, $axes);
            $created = [];
            $skipped = 0;
            $sequence = $generateBarcodes ? $this->lastInHouseSequence() : 0;

            foreach ($plan as $row) {
                if ($row['exists']) {
                    $skipped++;

                    continue;
                }

                $override = $overrides[$row['key']] ?? [];
                $barcode = ($override['barcode'] ?? null) ?: null;

                if ($barcode === null && $generateBarcodes) {
                    $barcode = Gtin::inHouseEan13(++$sequence);
                }

                $chosen = array_map(static fn (string $id): CatalogAttributeValue => $values[$id], $row['values']);
                $created[] = $this->createVariant($parent, $row, $chosen, $barcode, isset($override['sale_price']), isset($override['cost_price']));
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    /**
     * Recomputes price and cost of the variants that follow their parent (those without an override). Call it after the price or
     * the cost of the parent, or the surcharge of an attribute value, changed.
     */
    public function reprice(Product $parent): int
    {
        $changed = 0;
        $variants = $parent->variants()->get();
        $links = CatalogVariantValue::query()->whereIn('variant_id', $variants->pluck('id'))->with('value')->get()->groupBy('variant_id');

        foreach ($variants as $variant) {
            $chosen = ($links->get($variant->id) ?? collect())->map(static fn (CatalogVariantValue $link): ?CatalogAttributeValue => $link->value)->filter()->values()->all();
            $price = $variant->price_overridden ? null : $this->variantFigure($parent->sale_price, $variant->price_delta, $variant->price_delta_type, $chosen, 'price');
            $cost = $variant->cost_overridden ? null : $this->variantFigure($parent->cost_price, $variant->cost_delta, $variant->cost_delta_type, $chosen, 'cost');
            $update = array_filter(['sale_price' => $price, 'cost_price' => $cost], static fn (?string $figure): bool => $figure !== null);

            if ($update !== []) {
                $variant->forceFill($update)->save();
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * The attribute values a variant carries, in the order of the matrix axes.
     *
     * @return list<CatalogAttributeValue>
     */
    public function valuesOf(Product $variant): array
    {
        return CatalogVariantValue::query()->where('variant_id', $variant->id)->with('value')->get()
            ->map(static fn (CatalogVariantValue $link): ?CatalogAttributeValue => $link->value)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Price or cost of a variant: its own surcharge when it has one, otherwise the surcharges of its attribute values.
     *
     * @param  list<CatalogAttributeValue>  $values
     */
    public function variantFigure(string|int|float|null $parentFigure, string|int|float|null $ownDelta, ?string $ownType, array $values, string $kind): string
    {
        if ($ownDelta !== null && $ownDelta !== '') {
            return VariantPricing::resolve($parentFigure, [['amount' => $ownDelta, 'type' => $ownType]]);
        }

        return $this->figure($parentFigure, $values, $kind);
    }

    /**
     * @param  list<CatalogAttributeValue>  $values
     */
    private function figure(string|int|float|null $parentFigure, array $values, string $kind): string
    {
        return VariantPricing::resolve($parentFigure, array_map(
            static fn (CatalogAttributeValue $value): array => ['amount' => $value->{$kind.'_delta'}, 'type' => $value->{$kind.'_delta_type'}],
            $values,
        ));
    }

    /**
     * @param  array{key: string, values: list<string>, labels: array<string, string>, sku: string, sale_price: string, cost_price: string, exists: bool}  $row
     * @param  list<CatalogAttributeValue>  $chosen
     */
    private function createVariant(Product $parent, array $row, array $chosen, ?string $barcode, bool $priceOverridden, bool $costOverridden): Product
    {
        $variant = Product::create([
            'sku' => $row['sku'],
            'barcode' => $barcode,
            'name' => $parent->name.' - '.implode(' / ', array_values($row['labels'])),
            'description' => $parent->description,
            'unit_measure' => $parent->unit_measure,
            'cost_price' => $row['cost_price'],
            'sale_price' => $row['sale_price'],
            'tax_rate' => $parent->tax_rate,
            'hs_code' => $parent->hs_code,
            'track_inventory' => $parent->track_inventory,
            'is_active' => true,
            'category_id' => $parent->category_id,
            'brand_id' => $parent->brand_id,
            'unit_of_measure_id' => $parent->unit_of_measure_id,
            'parent_id' => $parent->id,
            'is_variant' => true,
            'min_stock' => $parent->min_stock,
            'attributes' => $row['labels'],
            'product_type' => $parent->product_type ?? 'storable',
            'purchase_uom_id' => $parent->purchase_uom_id,
            'sales_uom_id' => $parent->sales_uom_id,
            'uom_rounding' => $parent->uom_rounding ?? 'half_up',
            'uom_precision' => $parent->uom_precision ?? 4,
            'price_overridden' => $priceOverridden,
            'cost_overridden' => $costOverridden,
            'customs_taric_code_id' => $parent->customs_taric_code_id,
            'taric_code' => $parent->taric_code,
            'swiss_hs_code' => $parent->swiss_hs_code,
            'country_of_origin' => $parent->country_of_origin,
            'origin_type' => $parent->origin_type,
            'customs_duty_rate' => $parent->customs_duty_rate,
            'net_weight_kg' => $parent->net_weight_kg,
            'gross_weight_kg' => $parent->gross_weight_kg,
            'vat_code_it_id' => $parent->vat_code_it_id,
            'vat_code_ch_id' => $parent->vat_code_ch_id,
        ]);

        foreach ($chosen as $value) {
            CatalogVariantValue::create([
                'variant_id' => $variant->id,
                'attribute_id' => $value->attribute_id,
                'attribute_value_id' => $value->id,
            ]);
        }

        return $variant;
    }

    /**
     * @param  list<array{attribute_id: string, value_ids: list<string>}>  $axes
     */
    private function syncAxes(Product $parent, array $axes): void
    {
        CatalogProductAxis::query()->where('product_id', $parent->id)->forceDelete();

        foreach ($axes as $position => $axis) {
            CatalogProductAxis::create(['product_id' => $parent->id, 'attribute_id' => $axis['attribute_id'], 'sort_order' => $position]);
        }
    }

    /**
     * @throws CatalogException
     */
    private function assertParent(Product $parent): void
    {
        if ($parent->is_variant) {
            throw new CatalogException('variant_cannot_have_variants');
        }

        if ($parent->kit_type !== null) {
            throw new CatalogException('kit_cannot_have_variants');
        }
    }

    /**
     * @param  list<array{attribute_id: string, value_ids: list<string>}>  $axes
     * @return array{0: array<string, CatalogAttribute>, 1: array<string, CatalogAttributeValue>}
     *
     * @throws CatalogException
     */
    private function resolveAxes(array $axes): array
    {
        if ($axes === []) {
            throw new CatalogException('matrix_needs_axis');
        }

        $attributeIds = array_column($axes, 'attribute_id');

        if (count(array_unique($attributeIds)) !== count($attributeIds)) {
            throw new CatalogException('matrix_duplicate_attribute');
        }

        $attributes = CatalogAttribute::query()->whereIn('id', $attributeIds)->where('is_active', true)->get()->keyBy('id');
        $valueIds = array_merge(...array_column($axes, 'value_ids'));
        $values = CatalogAttributeValue::query()->whereIn('id', $valueIds)->where('is_active', true)->get()->keyBy('id');

        foreach ($axes as $axis) {
            if (! $attributes->has($axis['attribute_id'])) {
                throw new CatalogException('attribute_not_found', 404);
            }

            if ($axis['value_ids'] === [] || count(array_unique($axis['value_ids'])) !== count($axis['value_ids'])) {
                throw new CatalogException('matrix_axis_values_invalid');
            }

            foreach ($axis['value_ids'] as $valueId) {
                $value = $values->get($valueId);

                if ($value === null || $value->attribute_id !== $axis['attribute_id']) {
                    throw new CatalogException('attribute_value_not_found', 404);
                }
            }
        }

        return [$attributes->all(), $values->all()];
    }

    /**
     * The cartesian product of the value ids, in the order of the axes and of the values.
     *
     * @param  list<array{attribute_id: string, value_ids: list<string>}>  $axes
     * @return list<list<string>>
     *
     * @throws CatalogException
     */
    public function combinations(array $axes): array
    {
        $count = array_product(array_map(static fn (array $axis): int => count($axis['value_ids']), $axes));

        if ($count > self::MAX_COMBINATIONS) {
            throw new CatalogException('too_many_combinations', 422, ['count' => $count, 'max' => self::MAX_COMBINATIONS]);
        }

        $combinations = [[]];

        foreach ($axes as $axis) {
            $next = [];

            foreach ($combinations as $combination) {
                foreach ($axis['value_ids'] as $valueId) {
                    $next[] = [...$combination, $valueId];
                }
            }

            $combinations = $next;
        }

        return $combinations;
    }

    /**
     * @param  list<string>  $valueIds
     */
    private function signature(array $valueIds): string
    {
        sort($valueIds);

        return implode('|', $valueIds);
    }

    /**
     * The combinations the parent already has, as the signature of their value ids => the SKU of the variant.
     *
     * @return array<string, string>
     */
    private function existingSignatures(Product $parent): array
    {
        $skus = $parent->variants()->pluck('sku', 'id');
        $links = CatalogVariantValue::query()
            ->whereIn('variant_id', $skus->keys())
            ->get(['variant_id', 'attribute_value_id'])
            ->groupBy('variant_id');
        $known = [];

        foreach ($links as $variantId => $rows) {
            $known[$this->signature($rows->pluck('attribute_value_id')->all())] = (string) ($skus[$variantId] ?? '');
        }

        return $known;
    }

    /**
     * Every SKU of the company that could collide with the generated ones (they all start with the SKU of the parent).
     *
     * @return array<string, true>
     */
    private function takenSkus(Product $parent): array
    {
        $taken = [];

        foreach (Product::withTrashed()->where('sku', 'like', $parent->sku.'%')->pluck('sku') as $sku) {
            $taken[strtoupper((string) $sku)] = true;
        }

        return $taken;
    }

    /**
     * @param  list<array{key: string, sku: string, exists: bool}>  $plan
     * @param  array<string, array{sku?: string|null}>  $overrides
     *
     * @throws CatalogException
     */
    private function assertManualSkusFree(array $plan, array $overrides): void
    {
        $seen = [];

        foreach ($plan as $row) {
            if ($row['exists'] || ! (($overrides[$row['key']]['sku'] ?? null))) {
                continue;
            }

            $sku = strtoupper($row['sku']);

            if (isset($seen[$sku]) || Product::withTrashed()->whereRaw('upper(sku) = ?', [$sku])->exists()) {
                throw new CatalogException('sku_taken', 422, ['sku' => $row['sku']]);
            }

            $seen[$sku] = true;
        }
    }

    /**
     * The highest in-house EAN-13 sequence of the company (codes starting with 2), so the next generated code is unique.
     */
    private function lastInHouseSequence(): int
    {
        return (new BarcodeAllocator)->lastSequence();
    }
}
