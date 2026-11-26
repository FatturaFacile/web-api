<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\VatCode;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\ProductCatalog\Models\CatalogAttribute;
use Modules\ProductCatalog\Services\AttributeService;
use Modules\ProductCatalog\Services\CatalogProductService;
use Modules\ProductCatalog\Services\KitService;
use Modules\ProductCatalog\Services\PackagingService;
use Modules\ProductCatalog\Services\VariantMatrixService;

/**
 * Demo catalog (Mod 052) for the first company of the installation: the units of measure, the attributes of the variant matrices
 * (size, colour, material, with names and labels in it / de / fr / en and surcharges), one product of every type (storable,
 * consumable, service, digital), two matrices (5 sizes x 3 colours = 15 variants with in-house EAN-13 codes and surcharges; 3 sizes
 * x 2 colours x 2 materials = 12 variants), a packaging hierarchy (piece, pack of 6, carton of 24, pallet of 960), a phantom kit and a
 * physical kit, and a product with CH (Tares) and IT (TARIC) customs classification and both VAT codes. Idempotent by SKU; does
 * nothing without a company. Names are demo content; the interface texts are in the frontend dictionary seeders.
 */
final class ProductCatalogDemoSeeder extends Seeder
{
    /** @var array<string, string> unit code => English name (the interface texts come from the dictionary) */
    private const array UNITS = [
        'PZ' => 'Piece', 'PACK' => 'Pack', 'BOX' => 'Box', 'CT' => 'Carton', 'PLT' => 'Pallet', 'SET' => 'Set', 'KG' => 'Kilogram',
        'G' => 'Gram', 'L' => 'Litre', 'ML' => 'Millilitre', 'M' => 'Metre', 'H' => 'Hour', 'LIC' => 'Licence',
    ];

    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('created_at')->first();

        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->runWithTenant($tenant, function (): void {
            if (Product::query()->where('sku', 'TSH-ESS')->exists()) {
                return;
            }

            $units = $this->units();
            $attributes = $this->attributes();
            $products = app(CatalogProductService::class);
            $matrix = app(VariantMatrixService::class);
            $vatIt = (string) VatCode::query()->where('country_iso', 'IT')->where('code', 'IVA22')->value('id');
            $vatCh = (string) VatCode::query()->where('country_iso', 'CH')->where('code', 'CH_MWST_81')->value('id');

            // Storable parent with a 5 x 3 matrix: 15 variants, in-house EAN-13, surcharges from the sizes.
            $shirt = $products->create([
                'sku' => 'TSH-ESS', 'name' => 'Essential T-shirt', 'product_type' => 'storable', 'unit_of_measure_id' => $units['PZ'],
                'cost_price' => '6.5000', 'sale_price' => '19.9000', 'tax_rate' => '22', 'country_of_origin' => 'PT',
                'taric_code' => '610910', 'tares_code' => '61091000', 'vat_code_it_id' => $vatIt, 'vat_code_ch_id' => $vatCh,
            ]);
            $matrix->generate($shirt, [
                ['attribute_id' => $attributes['size']->id, 'value_ids' => $this->valueIds($attributes['size'], ['s', 'm', 'l', 'xl', 'xxl'])],
                ['attribute_id' => $attributes['color']->id, 'value_ids' => $this->valueIds($attributes['color'], ['black', 'white', 'navy'])],
            ], [], true);

            // Second matrix with three axes and a percentage surcharge on the material.
            $workShirt = $products->create([
                'sku' => 'WSH-PRO', 'name' => 'Work shirt', 'product_type' => 'storable', 'unit_of_measure_id' => $units['PZ'],
                'cost_price' => '14.0000', 'sale_price' => '39.0000', 'tax_rate' => '22', 'country_of_origin' => 'IT',
            ]);
            $matrix->generate($workShirt, [
                ['attribute_id' => $attributes['size']->id, 'value_ids' => $this->valueIds($attributes['size'], ['s', 'm', 'l'])],
                ['attribute_id' => $attributes['color']->id, 'value_ids' => $this->valueIds($attributes['color'], ['white', 'navy'])],
                ['attribute_id' => $attributes['material']->id, 'value_ids' => $this->valueIds($attributes['material'], ['cotton', 'linen'])],
            ], [], true);

            // Consumable with the full packaging hierarchy: piece -> pack of 6 (sales) -> carton of 4 packs (purchase) -> pallet of 40 cartons.
            $tape = $products->create([
                'sku' => 'TAPE-48', 'name' => 'Packing tape 48 mm', 'product_type' => 'consumable', 'unit_of_measure_id' => $units['PZ'],
                'cost_price' => '0.8500', 'sale_price' => '2.2000', 'tax_rate' => '22', 'generate_barcode' => true, 'uom_precision' => 3,
                'uom_rounding' => 'half_up',
            ]);
            app(PackagingService::class)->sync($tape, [
                ['code' => 'PACK6', 'uom_id' => $units['PACK'], 'units_per_parent' => '6', 'role' => 'sales'],
                ['code' => 'CARTON24', 'uom_id' => $units['CT'], 'units_per_parent' => '4', 'parent_code' => 'PACK6', 'role' => 'purchase'],
                ['code' => 'PALLET960', 'uom_id' => $units['PLT'], 'units_per_parent' => '40', 'parent_code' => 'CARTON24', 'role' => 'logistic'],
            ]);

            $products->create([
                'sku' => 'SRV-INSTALL', 'name' => 'On-site installation', 'product_type' => 'service', 'unit_of_measure_id' => $units['H'],
                'cost_price' => '35.0000', 'sale_price' => '95.0000', 'tax_rate' => '22', 'vat_code_it_id' => $vatIt, 'vat_code_ch_id' => $vatCh,
            ]);
            $products->create([
                'sku' => 'LIC-ANNUAL', 'name' => 'Software licence (annual)', 'product_type' => 'digital', 'unit_of_measure_id' => $units['LIC'],
                'cost_price' => '120.0000', 'sale_price' => '360.0000', 'tax_rate' => '22', 'vat_code_it_id' => $vatIt, 'vat_code_ch_id' => $vatCh,
            ]);

            // A kit sold as one line and exploded into its components for picking and the delivery note.
            $welcome = $products->create(['sku' => 'KIT-WELCOME', 'name' => 'Welcome bundle', 'product_type' => 'storable', 'kit_type' => 'phantom', 'unit_of_measure_id' => $units['SET'], 'cost_price' => '0', 'sale_price' => '29.0000', 'tax_rate' => '22']);
            app(KitService::class)->setComponents($welcome, [
                ['component_id' => $this->idOf('TSH-ESS-M-BLK'), 'quantity' => '1'],
                ['component_id' => $tape->id, 'quantity' => '2'],
            ]);

            // The same idea pre-assembled: stocked under its own SKU and cost.
            $starter = $products->create(['sku' => 'KIT-STARTER', 'name' => 'Starter box (assembled)', 'product_type' => 'storable', 'kit_type' => 'physical', 'unit_of_measure_id' => $units['BOX'], 'cost_price' => '11.0000', 'sale_price' => '34.0000', 'tax_rate' => '22', 'generate_barcode' => true]);
            app(KitService::class)->setComponents($starter, [
                ['component_id' => $this->idOf('TSH-ESS-L-WHT'), 'quantity' => '1'],
                ['component_id' => $tape->id, 'quantity' => '3'],
            ]);

            // Customs and tax classification for both countries.
            $products->create([
                'sku' => 'ELE-ROUTER', 'name' => 'Wireless router', 'product_type' => 'storable', 'unit_of_measure_id' => $units['PZ'],
                'cost_price' => '48.0000', 'sale_price' => '99.0000', 'tax_rate' => '22', 'tares_code' => '85176200', 'taric_code' => '8517620000',
                'country_of_origin' => 'CN', 'customs_duty_rate' => '0', 'net_weight_kg' => '0.450', 'gross_weight_kg' => '0.620',
                'vat_code_it_id' => $vatIt, 'vat_code_ch_id' => $vatCh, 'generate_barcode' => true,
            ]);
        });
    }

    /**
     * @return array<string, string> unit code => id
     */
    private function units(): array
    {
        $ids = [];

        foreach (self::UNITS as $code => $name) {
            $ids[$code] = (string) UnitOfMeasure::query()->firstOrCreate(['code' => $code], ['name' => $name])->id;
        }

        return $ids;
    }

    /**
     * @return array<string, CatalogAttribute>
     */
    private function attributes(): array
    {
        $service = app(AttributeService::class);
        $make = static fn (string $code, array $name, array $values): CatalogAttribute => CatalogAttribute::query()->where('code', $code)->first()
            ?? $service->create(['code' => $code, 'name' => $name, 'values' => $values]);

        $size = $make('size', ['it' => 'Taglia', 'en' => 'Size', 'de' => 'Grösse', 'fr' => 'Taille'], [
            ['code' => 's', 'label' => ['it' => 'S', 'en' => 'S', 'de' => 'S', 'fr' => 'S'], 'sku_token' => 'S'],
            ['code' => 'm', 'label' => ['it' => 'M', 'en' => 'M', 'de' => 'M', 'fr' => 'M'], 'sku_token' => 'M'],
            ['code' => 'l', 'label' => ['it' => 'L', 'en' => 'L', 'de' => 'L', 'fr' => 'L'], 'sku_token' => 'L'],
            ['code' => 'xl', 'label' => ['it' => 'XL', 'en' => 'XL', 'de' => 'XL', 'fr' => 'XL'], 'sku_token' => 'XL', 'price_delta' => '2.00', 'price_delta_type' => 'fixed', 'cost_delta' => '0.40', 'cost_delta_type' => 'fixed'],
            ['code' => 'xxl', 'label' => ['it' => 'XXL', 'en' => 'XXL', 'de' => 'XXL', 'fr' => 'XXL'], 'sku_token' => 'XXL', 'price_delta' => '3.50', 'price_delta_type' => 'fixed', 'cost_delta' => '0.80', 'cost_delta_type' => 'fixed'],
        ]);
        $color = $make('color', ['it' => 'Colore', 'en' => 'Colour', 'de' => 'Farbe', 'fr' => 'Couleur'], [
            ['code' => 'black', 'label' => ['it' => 'Nero', 'en' => 'Black', 'de' => 'Schwarz', 'fr' => 'Noir'], 'sku_token' => 'BLK'],
            ['code' => 'white', 'label' => ['it' => 'Bianco', 'en' => 'White', 'de' => 'Weiss', 'fr' => 'Blanc'], 'sku_token' => 'WHT'],
            ['code' => 'navy', 'label' => ['it' => 'Blu navy', 'en' => 'Navy', 'de' => 'Marineblau', 'fr' => 'Bleu marine'], 'sku_token' => 'NVY'],
        ]);
        $material = $make('material', ['it' => 'Materiale', 'en' => 'Material', 'de' => 'Material', 'fr' => 'Matière'], [
            ['code' => 'cotton', 'label' => ['it' => 'Cotone', 'en' => 'Cotton', 'de' => 'Baumwolle', 'fr' => 'Coton'], 'sku_token' => 'COT'],
            ['code' => 'linen', 'label' => ['it' => 'Lino', 'en' => 'Linen', 'de' => 'Leinen', 'fr' => 'Lin'], 'sku_token' => 'LIN', 'price_delta' => '10', 'price_delta_type' => 'percent', 'cost_delta' => '10', 'cost_delta_type' => 'percent'],
        ]);

        return ['size' => $size, 'color' => $color, 'material' => $material];
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function valueIds(CatalogAttribute $attribute, array $codes): array
    {
        $byCode = $attribute->values()->pluck('id', 'code');

        return array_map(static fn (string $code): string => (string) $byCode[$code], $codes);
    }

    private function idOf(string $sku): string
    {
        return (string) Product::query()->where('sku', $sku)->value('id');
    }
}
