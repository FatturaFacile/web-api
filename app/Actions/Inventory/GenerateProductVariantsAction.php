<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\BarcodeType;
use App\Exceptions\BarcodeGenerationException;
use App\Models\Product;
use App\Services\Inventory\BarcodeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates the variants of a parent product: one at a time or the whole cartesian product of an attribute matrix
 * (for example `{"size": ["S", "M"], "color": ["Red"]}` gives two variants). Variants inherit the catalogue data of
 * the parent, get a deterministic SKU and a descriptive name, and combinations that already exist are skipped.
 */
class GenerateProductVariantsAction
{
    public const MAX_COMBINATIONS = 250;

    private const SKU_MAX_LENGTH = 50;

    private const BARCODE_ATTEMPTS = 20;

    public function __construct(private readonly BarcodeService $barcodes)
    {
    }

    /**
     * Generate every missing combination of the matrix.
     *
     * @param array<string, array<int, string>> $matrix
     * @param array{price_modifier?: ?array{type: string, value: float|int|string}, auto_generate_sku?: bool, generate_barcode?: bool, barcode_type?: string, discontinue_on_zero_stock?: ?bool} $options
     * @return array{created: Collection<int, Product>, skipped: int}
     *
     * @throws ValidationException
     */
    public function execute(Product $parent, array $matrix, array $options = []): array
    {
        $this->assertCanHaveVariants($parent);
        $combinations = $this->combinations($matrix);

        return DB::transaction(function () use ($parent, $combinations, $options): array {
            $known = $parent->variants()->get()->mapWithKeys(fn(Product $variant): array => [$this->signature($variant->optionAttributes()) => true])->all();
            $created = new Collection;
            $skipped = 0;
            $sequence = $parent->variants()->count();

            foreach ($combinations as $combination) {
                if (isset($known[$this->signature($combination)])) {
                    $skipped++;

                    continue;
                }

                $sequence++;
                $created->push($this->createVariant($parent, $combination, $options + ['sequence' => $sequence]));
                $known[$this->signature($combination)] = true;
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    /**
     * Create a single variant. `$overrides` may carry `sku`, `price`, `barcode` and `is_active`.
     *
     * @param array<string, string> $attributes
     * @param array{price_modifier?: ?array{type: string, value: float|int|string}, auto_generate_sku?: bool, generate_barcode?: bool, barcode_type?: string, discontinue_on_zero_stock?: ?bool, min_stock?: float|int|string|null, sequence?: int} $options
     * @param array{sku?: ?string, price?: float|int|string|null, barcode?: ?string, is_active?: bool, min_stock?: float|int|string|null} $overrides
     *
     * @throws ValidationException
     */
    public function createVariant(Product $parent, array $attributes, array $options = [], array $overrides = []): Product
    {
        $this->assertCanHaveVariants($parent);

        $sku = ($overrides['sku'] ?? null) ?: (($options['auto_generate_sku'] ?? true)
            ? $this->skuFor($parent, $attributes)
            : $this->sequentialSku($parent, (int)($options['sequence'] ?? $parent->variants()->count() + 1)));

        $barcode = $overrides['barcode'] ?? null;

        if ($barcode === null && ($options['generate_barcode'] ?? false)) {
            $barcode = $this->generateBarcode(BarcodeType::fromInput($options['barcode_type'] ?? BarcodeType::Ean13->value), $sku);
        }

        return Product::create([
            'sku' => $sku,
            'barcode' => $barcode,
            'name' => $this->nameFor($parent, $attributes),
            'description' => $parent->description,
            'unit_measure' => $parent->unit_measure,
            'cost_price' => $parent->cost_price,
            'sale_price' => isset($overrides['price']) ? round((float)$overrides['price'], 4) : $this->priceFor($parent, $options['price_modifier'] ?? null),
            'tax_rate' => $parent->tax_rate,
            'hs_code' => $parent->hs_code,
            'track_inventory' => $parent->track_inventory,
            'is_active' => $overrides['is_active'] ?? true,
            'category_id' => $parent->category_id,
            'brand_id' => $parent->brand_id,
            'unit_of_measure_id' => $parent->unit_of_measure_id,
            'parent_id' => $parent->id,
            'is_variant' => true,
            'min_stock' => array_key_exists('min_stock', $overrides) ? $overrides['min_stock'] : ($options['min_stock'] ?? $parent->min_stock),
            'attributes' => $attributes + [Product::DISCONTINUE_FLAG => (bool)($options['discontinue_on_zero_stock'] ?? false)],
        ]);
    }

    /**
     * Whether the parent already has a variant with exactly these attributes (names and values are compared
     * case-insensitively).
     *
     * @param array<string, string> $attributes
     */
    public function combinationExists(Product $parent, array $attributes, ?string $exceptVariantId = null): bool
    {
        $signature = $this->signature($attributes);

        return $parent->variants()
            ->when($exceptVariantId !== null, fn($query) => $query->whereKeyNot($exceptVariantId))
            ->get()
            ->contains(fn(Product $variant): bool => $this->signature($variant->optionAttributes()) === $signature);
    }

    /**
     * Cartesian product of the attribute values, in the order the attributes and values were given.
     *
     * @param array<string, array<int, string>> $matrix
     * @return array<int, array<string, string>>
     *
     * @throws ValidationException
     */
    public function combinations(array $matrix): array
    {
        $count = array_product(array_map('count', $matrix));

        if ($count > self::MAX_COMBINATIONS) {
            throw ValidationException::withMessages(['attributes' => __('inventory.too_many_combinations', ['count' => $count, 'max' => self::MAX_COMBINATIONS])]);
        }

        $combinations = [[]];

        foreach ($matrix as $name => $values) {
            $next = [];

            foreach ($combinations as $combination) {
                foreach ($values as $value) {
                    $next[] = $combination + [(string)$name => (string)$value];
                }
            }

            $combinations = $next;
        }

        return $combinations;
    }

    /**
     * Case-insensitive, order-independent identity of an attribute combination.
     *
     * @param array<string, string> $attributes
     */
    public function signature(array $attributes): string
    {
        $normalized = [];

        foreach ($attributes as $name => $value) {
            $normalized[mb_strtolower(trim((string)$name))] = mb_strtolower(trim((string)$value));
        }

        ksort($normalized);

        return (string)json_encode($normalized);
    }

    /**
     * `{PARENT_SKU}-{VALUE}-{VALUE}` in upper case without special characters; a numeric suffix keeps it unique.
     *
     * @param array<string, string> $attributes
     */
    public function skuFor(Product $parent, array $attributes): string
    {
        $parts = [strtoupper($parent->sku)];

        foreach ($attributes as $value) {
            $part = preg_replace('/[^A-Z0-9]/', '', strtoupper(Str::ascii((string)$value))) ?? '';
            $parts[] = $part !== '' ? $part : (string)$value
                    |> md5(...)
                    |> (fn($x) => substr($x, 0, 4))
                    |> strtoupper(...);
        }

        $sku = implode('-', $parts);

        if (strlen($sku) > self::SKU_MAX_LENGTH) {
            $sku = substr($sku, 0, self::SKU_MAX_LENGTH - 7) . '-' . strtoupper(substr(md5($sku), 0, 6));
        }

        return $this->uniqueSku($sku);
    }

    /**
     * `{Parent name} (Size: M, Color: Red)`.
     *
     * @param array<string, string> $attributes
     */
    public function nameFor(Product $parent, array $attributes): string
    {
        $described = collect($attributes)->map(fn(string $value, string|int $name): string => "{$name}: {$value}")->implode(', ');

        return __('inventory.variant_name', ['name' => $parent->name, 'attributes' => $described]);
    }

    /**
     * The parent price with the optional fixed or percentage modifier applied.
     *
     * @param array{type: string, value: float|int|string}|null $modifier
     *
     * @throws ValidationException
     */
    public function priceFor(Product $parent, ?array $modifier): float
    {
        $base = (float)$parent->sale_price;

        if ($modifier === null) {
            return round($base, 4);
        }

        $price = $modifier['type'] === 'percent'
            ? $base * (1 + (float)$modifier['value'] / 100)
            : $base + (float)$modifier['value'];

        if ($price < 0) {
            throw ValidationException::withMessages(['price_modifier' => __('inventory.price_modifier_invalid')]);
        }

        return round($price, 4);
    }

    private function assertCanHaveVariants(Product $parent): void
    {
        if ($parent->is_variant) {
            throw ValidationException::withMessages(['parent' => __('inventory.parent_is_variant')]);
        }
    }

    private function sequentialSku(Product $parent, int $sequence): string
    {
        do {
            $sku = strtoupper($parent->sku) . '-' . str_pad((string)$sequence++, 3, '0', STR_PAD_LEFT);
        } while (Product::withTrashed()->where('sku', $sku)->exists());

        return $sku;
    }

    private function uniqueSku(string $base): string
    {
        $sku = $base;
        $suffix = 1;

        while (Product::withTrashed()->where('sku', $sku)->exists()) {
            $suffix++;
            $tail = "-{$suffix}";
            $sku = substr($base, 0, self::SKU_MAX_LENGTH - strlen($tail)) . $tail;
        }

        return $sku;
    }

    /**
     * EAN-13 codes use the GS1 restricted-circulation prefix `2` (internal use) with a random body and a check digit;
     * Code 128 encodes the SKU itself.
     *
     * @throws ValidationException
     */
    private function generateBarcode(BarcodeType $type, string $sku): string
    {
        for ($attempt = 0; $attempt < self::BARCODE_ATTEMPTS; $attempt++) {
            try {
                $candidate = $type === BarcodeType::Ean13
                    ? $this->barcodes->normalize($type, '2' . str_pad((string)random_int(0, 99_999_999_999), 11, '0', STR_PAD_LEFT))
                    : $this->barcodes->normalize($type, $sku);
            } catch (BarcodeGenerationException $e) {
                throw ValidationException::withMessages(['barcode' => $e->getMessage()]);
            }

            if (!Product::withTrashed()->where('barcode', $candidate)->exists()) {
                return $candidate;
            }

            if ($type !== BarcodeType::Ean13) {
                break;
            }
        }

        throw ValidationException::withMessages(['barcode' => __('inventory.barcode_generation_failed')]);
    }
}
