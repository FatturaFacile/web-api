<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Services;

use App\Models\Product;
use App\Models\UnitOfMeasure;
use App\Models\VatCode;
use Generator;
use Modules\ProductCatalog\Domain\Support\Dec;
use Modules\ProductCatalog\Models\CatalogKitComponent;
use Modules\ProductCatalog\Models\CatalogPackaging;

/**
 * Exports the catalog in the exchange schema of CatalogSpreadsheet: parents and simple products by SKU, each followed by its
 * variants, with the packaging hierarchy, the kit bill of materials and the CH / IT customs and tax fields, so the file imports
 * back without loss.
 */
final class CatalogExportService
{
    public function __construct(private readonly CatalogSpreadsheet $spreadsheet) {}

    /**
     * Writes the export to a file and returns its path.
     */
    public function export(string $extension): string
    {
        return $this->spreadsheet->write($this->rows(), $extension);
    }

    /**
     * An empty file with the header row only, as the starting point of an import.
     */
    public function template(string $extension): string
    {
        return $this->spreadsheet->write([], $extension);
    }

    /**
     * @return Generator<int, list<string|null>>
     */
    public function rows(): Generator
    {
        $units = UnitOfMeasure::query()->pluck('code', 'id')->all();
        $vat = VatCode::query()->pluck('code', 'id')->all();
        $skus = Product::query()->pluck('sku', 'id')->all();

        $packagings = CatalogPackaging::query()->orderBy('level')->get()->groupBy('product_id');
        $kits = CatalogKitComponent::query()->orderBy('sort_order')->get()->groupBy('kit_id');

        $parents = Product::query()->where('is_variant', false)->orderBy('sku')->get();

        foreach ($parents as $parent) {
            yield $this->row($parent, $units, $vat, $skus, $packagings->get($parent->id), $kits->get($parent->id));

            foreach (Product::query()->where('parent_id', $parent->id)->orderBy('sku')->get() as $variant) {
                yield $this->row($variant, $units, $vat, $skus, $packagings->get($variant->id), null);
            }
        }
    }

    /**
     * @param  array<string, string>  $units
     * @param  array<string, string>  $vat
     * @param  array<string, string>  $skus
     * @param  iterable<CatalogPackaging>|null  $packagings
     * @param  iterable<CatalogKitComponent>|null  $kit
     * @return list<string|null>
     */
    private function row(Product $product, array $units, array $vat, array $skus, ?iterable $packagings, ?iterable $kit): array
    {
        $levels = [];
        $codes = [];

        foreach ($packagings ?? [] as $packaging) {
            $codes[$packaging->id] = $packaging->code;
        }

        foreach ($packagings ?? [] as $packaging) {
            $levels[] = implode('|', [
                $packaging->code,
                $units[$packaging->uom_id] ?? '',
                Dec::trim($packaging->units_per_parent),
                $packaging->parent_packaging_id !== null ? ($codes[$packaging->parent_packaging_id] ?? '') : '',
                $packaging->role->value,
                $packaging->gtin ?? '',
            ]);
        }

        $components = [];

        foreach ($kit ?? [] as $line) {
            $components[] = ($skus[$line->component_id] ?? '').':'.Dec::trim($line->quantity).($line->is_optional ? ':optional' : '');
        }

        $attributes = [];

        foreach ($product->is_variant ? $product->optionAttributes() : [] as $name => $label) {
            $attributes[] = $name.'='.$label;
        }

        return [
            $product->sku,
            $product->name,
            $product->description,
            (string) ($product->product_type ?? 'storable'),
            $product->kit_type,
            $product->is_variant ? ($skus[$product->parent_id] ?? null) : null,
            $product->unit_of_measure_id !== null ? ($units[$product->unit_of_measure_id] ?? null) : null,
            implode(';', $levels),
            implode(';', $components),
            implode('|', $attributes),
            $product->barcode,
            $this->figure($product->cost_price),
            $this->figure($product->sale_price),
            $this->figure($product->price_delta),
            $product->price_delta_type,
            $this->figure($product->cost_delta),
            $product->cost_delta_type,
            $this->figure($product->tax_rate),
            $product->swiss_hs_code,
            $product->taric_code,
            $product->country_of_origin,
            $product->vat_code_it_id !== null ? ($vat[$product->vat_code_it_id] ?? null) : null,
            $product->vat_code_ch_id !== null ? ($vat[$product->vat_code_ch_id] ?? null) : null,
            $this->figure($product->net_weight_kg),
            $this->figure($product->gross_weight_kg),
            $product->track_inventory ? '1' : '0',
            $product->is_active ? '1' : '0',
        ];
    }

    private function figure(string|int|float|null $value): ?string
    {
        return $value === null ? null : Dec::trim($value);
    }
}
