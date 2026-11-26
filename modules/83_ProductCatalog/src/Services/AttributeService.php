<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Modules\ProductCatalog\Domain\Support\SkuBuilder;
use Modules\ProductCatalog\Exceptions\CatalogException;
use Modules\ProductCatalog\Models\CatalogAttribute;
use Modules\ProductCatalog\Models\CatalogAttributeValue;
use Modules\ProductCatalog\Models\CatalogProductAxis;
use Modules\ProductCatalog\Models\CatalogVariantValue;

/**
 * Attribute definitions (the axes of a variant matrix) and their values, with the SKU tokens and the price / cost surcharges.
 * Saving the values of an attribute is a synchronisation: values are matched by code, created, updated or removed; a value that
 * variants already carry cannot be removed. Changing a surcharge reprices the variants that follow their parent.
 */
final class AttributeService
{
    public function __construct(private readonly VariantMatrixService $matrix) {}

    /**
     * @param  array{code: string, name: array<string, string>, sku_token?: string|null, sort_order?: int, is_active?: bool, values?: list<array<string, mixed>>}  $data
     *
     * @throws CatalogException
     */
    public function create(array $data): CatalogAttribute
    {
        return DB::transaction(function () use ($data): CatalogAttribute {
            $attribute = CatalogAttribute::create([
                'code' => strtolower($data['code']),
                'name' => $data['name'],
                'sku_token' => $data['sku_token'] ?? SkuBuilder::tokenOf($data['code']),
                'sort_order' => $data['sort_order'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->syncValues($attribute, $data['values'] ?? []);

            return $attribute->load('values');
        });
    }

    /**
     * @param  array{name?: array<string, string>, sku_token?: string|null, sort_order?: int, is_active?: bool, values?: list<array<string, mixed>>}  $data
     *
     * @throws CatalogException
     */
    public function update(CatalogAttribute $attribute, array $data): CatalogAttribute
    {
        return DB::transaction(function () use ($attribute, $data): CatalogAttribute {
            $attribute->fill(array_filter([
                'name' => $data['name'] ?? null,
                'sku_token' => $data['sku_token'] ?? null,
                'sort_order' => $data['sort_order'] ?? null,
                'is_active' => $data['is_active'] ?? null,
            ], static fn (mixed $value): bool => $value !== null))->save();

            if (array_key_exists('values', $data)) {
                $this->syncValues($attribute, $data['values']);
            }

            return $attribute->load('values');
        });
    }

    /**
     * @throws CatalogException
     */
    public function delete(CatalogAttribute $attribute): void
    {
        if (CatalogVariantValue::query()->where('attribute_id', $attribute->id)->exists() || CatalogProductAxis::query()->where('attribute_id', $attribute->id)->exists()) {
            throw new CatalogException('attribute_in_use', 409, ['code' => $attribute->code]);
        }

        DB::transaction(static function () use ($attribute): void {
            CatalogAttributeValue::query()->where('attribute_id', $attribute->id)->forceDelete();
            $attribute->forceDelete();
        });
    }

    /**
     * @param  list<array<string, mixed>>  $values
     *
     * @throws CatalogException
     */
    private function syncValues(CatalogAttribute $attribute, array $values): void
    {
        $existing = CatalogAttributeValue::query()->where('attribute_id', $attribute->id)->get()->keyBy('code');
        $keep = [];
        $tokens = [];
        $repriced = false;

        foreach ($values as $position => $data) {
            $code = strtolower((string) $data['code']);
            $token = SkuBuilder::clean((string) ($data['sku_token'] ?? SkuBuilder::tokenOf($code)));

            if ($token === '' || isset($tokens[$token])) {
                throw new CatalogException('sku_token_duplicate', 422, ['token' => $token]);
            }

            $tokens[$token] = true;
            $keep[] = $code;
            $attributes = [
                'label' => $data['label'],
                'sku_token' => $token,
                'price_delta' => $data['price_delta'] ?? null,
                'price_delta_type' => ($data['price_delta'] ?? null) !== null ? ($data['price_delta_type'] ?? 'fixed') : null,
                'cost_delta' => $data['cost_delta'] ?? null,
                'cost_delta_type' => ($data['cost_delta'] ?? null) !== null ? ($data['cost_delta_type'] ?? 'fixed') : null,
                'sort_order' => $data['sort_order'] ?? $position,
                'is_active' => $data['is_active'] ?? true,
            ];

            $current = $existing->get($code);

            if ($current === null) {
                CatalogAttributeValue::create($attributes + ['attribute_id' => $attribute->id, 'code' => $code]);

                continue;
            }

            $current->fill($attributes)->save();
            $repriced = $repriced || $current->wasChanged(['price_delta', 'price_delta_type', 'cost_delta', 'cost_delta_type']);
        }

        foreach ($existing as $code => $value) {
            if (in_array($code, $keep, true)) {
                continue;
            }

            if (CatalogVariantValue::query()->where('attribute_value_id', $value->id)->exists()) {
                throw new CatalogException('attribute_value_in_use', 409, ['code' => $code]);
            }

            $value->forceDelete();
        }

        if ($repriced) {
            $this->repriceFamilies($attribute);
        }
    }

    /**
     * Reprices every parent whose matrix uses the attribute.
     */
    private function repriceFamilies(CatalogAttribute $attribute): void
    {
        $parents = CatalogProductAxis::query()->where('attribute_id', $attribute->id)->pluck('product_id');

        Product::query()->whereIn('id', $parents)->get()->each(fn (Product $parent): int => $this->matrix->reprice($parent));
    }
}
