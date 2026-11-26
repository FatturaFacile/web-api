<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Services;

use App\Models\Product;
use App\Models\UnitOfMeasure;
use App\Models\VatCode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\ProductCatalog\Domain\Enums\DeltaType;
use Modules\ProductCatalog\Domain\Enums\KitType;
use Modules\ProductCatalog\Domain\Enums\ProductType;
use Modules\ProductCatalog\Domain\Support\Gtin;
use Modules\ProductCatalog\Exceptions\CatalogException;
use Modules\ProductCatalog\Models\CatalogKitComponent;

/**
 * Creates, changes, searches and removes catalog products, applying the rules of the product type, of kits, of GTIN barcodes and
 * of the Swiss / Italian tax and customs classification, and keeping the variants of a parent in line with its price and cost.
 */
final class CatalogProductService
{
    public function __construct(private readonly VariantMatrixService $matrix, private readonly BarcodeAllocator $barcodes) {}

    /**
     * @param  array{q?: string, product_type?: string, kit_type?: string, kind?: string, category_id?: string, is_active?: bool, country_of_origin?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $kind = $filters['kind'] ?? 'catalog';

        return Product::query()
            ->with(['parent:id,sku,name'])
            ->withCount('variants')
            ->when($kind === 'variants', static fn ($query) => $query->where('is_variant', true))
            ->when($kind === 'catalog', static fn ($query) => $query->where('is_variant', false))
            ->when(isset($filters['q']) && $filters['q'] !== '', static function ($query) use ($filters): void {
                $needle = '%'.mb_strtolower((string) $filters['q']).'%';
                $query->where(static fn ($inner) => $inner->whereRaw('lower(sku) like ?', [$needle])
                    ->orWhereRaw('lower(name) like ?', [$needle])
                    ->orWhere('barcode', 'like', $needle));
            })
            ->when(isset($filters['product_type']), static fn ($query) => $query->where('product_type', $filters['product_type']))
            ->when(isset($filters['kit_type']), static fn ($query) => $query->where('kit_type', $filters['kit_type']))
            ->when(isset($filters['category_id']), static fn ($query) => $query->where('category_id', $filters['category_id']))
            ->when(isset($filters['country_of_origin']), static fn ($query) => $query->where('country_of_origin', strtoupper((string) $filters['country_of_origin'])))
            ->when(isset($filters['is_active']), static fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
            ->orderBy('sku')
            ->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 25))));
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws CatalogException
     */
    public function create(array $data, ?Product $parent = null): Product
    {
        if (($data['generate_barcode'] ?? false) && empty($data['barcode'])) {
            $data['barcode'] = $this->barcodes->next();
        }

        $data = $this->normalise($data, null);

        if ($parent !== null) {
            if ($parent->is_variant || $parent->kit_type !== null) {
                throw new CatalogException($parent->is_variant ? 'variant_cannot_have_variants' : 'kit_cannot_have_variants');
            }

            $data['parent_id'] = $parent->id;
            $data['is_variant'] = true;
            $data['kit_type'] = null;
        }

        return DB::transaction(function () use ($data): Product {
            $product = Product::create($data);
            $product->refresh();

            return $product;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws CatalogException
     */
    public function update(Product $product, array $data): Product
    {
        if (($data['generate_barcode'] ?? false) && empty($data['barcode']) && empty($product->barcode)) {
            $data['barcode'] = $this->barcodes->next();
        }

        $data = $this->normalise($data, $product);

        return DB::transaction(function () use ($product, $data): Product {
            $product->fill($data)->save();

            if (! $product->is_variant && $product->wasChanged(['sale_price', 'cost_price'])) {
                $this->matrix->reprice($product);
            }

            if ($product->is_variant && $product->wasChanged(['price_delta', 'price_delta_type', 'cost_delta', 'cost_delta_type', 'price_overridden', 'cost_overridden']) && $product->parent !== null) {
                $this->repriceVariant($product);
            }

            return $product->refresh();
        });
    }

    /**
     * @throws CatalogException
     */
    public function delete(Product $product): void
    {
        if (CatalogKitComponent::query()->where('component_id', $product->id)->exists()) {
            throw new CatalogException('product_in_use_by_kit', 409, ['sku' => $product->sku]);
        }

        DB::transaction(static function () use ($product): void {
            $product->variants()->get()->each(static fn (Product $variant): ?bool => $variant->delete());
            $product->delete();
        });
    }

    /**
     * Applies the figure rules of a variant after its surcharge or override changed.
     */
    private function repriceVariant(Product $variant): void
    {
        $parent = $variant->parent;

        if ($parent === null) {
            return;
        }

        $values = $this->matrix->valuesOf($variant);

        if (! $variant->price_overridden) {
            $variant->forceFill(['sale_price' => $this->matrix->variantFigure($parent->sale_price, $variant->price_delta, $variant->price_delta_type, $values, 'price')])->save();
        }

        if (! $variant->cost_overridden) {
            $variant->forceFill(['cost_price' => $this->matrix->variantFigure($parent->cost_price, $variant->cost_delta, $variant->cost_delta_type, $values, 'cost')])->save();
        }
    }

    /**
     * Resolves the rules between the fields of a product and returns the attributes to store.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws CatalogException
     */
    private function normalise(array $data, ?Product $existing): array
    {
        $type = ProductType::tryFrom((string) ($data['product_type'] ?? $existing->product_type ?? 'storable')) ?? ProductType::Storable;
        $kit = array_key_exists('kit_type', $data) ? $data['kit_type'] : $existing?->kit_type;
        $data['product_type'] = $type->value;
        $data['kit_type'] = $kit;

        if ($kit !== null) {
            $kitType = KitType::tryFrom((string) $kit);

            if ($kitType === null || ! $type->tracksStock()) {
                throw new CatalogException('kit_type_not_allowed', 422, ['type' => $type->value]);
            }

            if ($existing?->is_variant) {
                throw new CatalogException('kit_cannot_be_variant');
            }

            if ($existing !== null && $existing->kit_type === null && $existing->variants()->exists()) {
                throw new CatalogException('kit_cannot_have_variants');
            }

            // A phantom kit is never stocked; a physical kit always is.
            $data['track_inventory'] = $kitType === KitType::Physical;
        } elseif (! $type->tracksStock()) {
            $data['track_inventory'] = false;
        }

        if (isset($data['unit_of_measure_id'])) {
            $unit = UnitOfMeasure::query()->find($data['unit_of_measure_id']);

            if ($unit === null) {
                throw new CatalogException('unit_not_found', 404);
            }

            $data['unit_measure'] = $unit->code;
        }

        if (($data['barcode'] ?? null) !== null && $data['barcode'] !== '') {
            $this->assertBarcode((string) $data['barcode'], $existing?->id);
        } elseif (array_key_exists('barcode', $data)) {
            $data['barcode'] = null;
        }

        foreach (['price_delta_type', 'cost_delta_type'] as $field) {
            if (($data[$field] ?? null) !== null && DeltaType::tryFrom((string) $data[$field]) === null) {
                throw new CatalogException('delta_type_invalid');
            }
        }

        $this->assertVatCode($data, 'vat_code_it_id', 'IT');
        $this->assertVatCode($data, 'vat_code_ch_id', 'CH');

        if (isset($data['country_of_origin'])) {
            $data['country_of_origin'] = strtoupper((string) $data['country_of_origin']);
        }

        // A price or cost typed on a variant is its own: it stops following the parent unless the caller says otherwise.
        if ($existing?->is_variant) {
            foreach (['sale_price' => 'price_overridden', 'cost_price' => 'cost_overridden'] as $figure => $flag) {
                if (array_key_exists($figure, $data) && ! array_key_exists($flag, $data)) {
                    $data[$flag] = true;
                }
            }
        }

        // The Swiss tariff number (Tares) is stored in the swiss_hs_code column.
        if (array_key_exists('tares_code', $data)) {
            $data['swiss_hs_code'] = $data['tares_code'];
            unset($data['tares_code']);
        }

        unset($data['generate_barcode'], $data['parent_id'], $data['is_variant']);

        return $data;
    }

    /**
     * A barcode of a GTIN length must carry a correct GS1 check digit, and no two products of the company share a barcode.
     *
     * @throws CatalogException
     */
    private function assertBarcode(string $barcode, ?string $exceptId): void
    {
        if (preg_match('/^\d+$/', $barcode) && in_array(strlen($barcode), [8, 12, 13, 14], true) && ! Gtin::isValid($barcode)) {
            throw new CatalogException('gtin_invalid', 422, ['gtin' => $barcode]);
        }

        $taken = Product::withTrashed()->where('barcode', $barcode)->when($exceptId !== null, static fn ($query) => $query->whereKeyNot($exceptId))->exists();

        if ($taken) {
            throw new CatalogException('barcode_taken', 422, ['barcode' => $barcode]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws CatalogException
     */
    private function assertVatCode(array $data, string $field, string $country): void
    {
        if (($data[$field] ?? null) === null) {
            return;
        }

        $code = VatCode::query()->whereKey((string) $data[$field])->first();

        if ($code === null || strtoupper((string) $code->getAttribute('country_iso')) !== $country) {
            throw new CatalogException('vat_code_country_mismatch', 422, ['country' => $country]);
        }
    }
}
