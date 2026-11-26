<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Services;

use App\Models\Product;
use App\Models\UnitOfMeasure;
use Illuminate\Support\Facades\DB;
use Modules\ProductCatalog\Domain\Enums\PackagingRole;
use Modules\ProductCatalog\Domain\Enums\RoundingMode;
use Modules\ProductCatalog\Domain\Support\Dec;
use Modules\ProductCatalog\Domain\Support\Gtin;
use Modules\ProductCatalog\Domain\Support\UomConverter;
use Modules\ProductCatalog\Exceptions\CatalogException;
use Modules\ProductCatalog\Models\CatalogPackaging;

/**
 * The multi-level packaging hierarchy of a product (base stock unit, sales pack, purchase carton, pallet, ...) and the exact
 * conversions across it. Each level says how many of its parent it holds; the factor to the base stock unit is chained from
 * there and stored, so a conversion is one multiplication and at most one rounding.
 */
final class PackagingService
{
    /**
     * Replaces the packaging levels of a product. Levels may be given in any order; a level without parent counts base units.
     *
     * @param  list<array{code: string, uom_id: string, units_per_parent: string|int|float, parent_code?: string|null, role?: string, gtin?: string|null}>  $levels
     * @return list<CatalogPackaging>
     *
     * @throws CatalogException
     */
    public function sync(Product $product, array $levels): array
    {
        $byCode = [];

        foreach ($levels as $level) {
            $code = strtoupper(trim($level['code']));

            if (isset($byCode[$code])) {
                throw new CatalogException('duplicate_packaging', 422, ['code' => $code]);
            }

            if (Dec::cmp($level['units_per_parent'], 0) <= 0) {
                throw new CatalogException('packaging_quantity_invalid', 422, ['code' => $code]);
            }

            $role = PackagingRole::tryFrom((string) ($level['role'] ?? 'logistic'));

            if ($role === null) {
                throw new CatalogException('packaging_role_invalid', 422, ['code' => $code]);
            }

            if (($level['gtin'] ?? null) !== null && $level['gtin'] !== '' && ! Gtin::isValid($level['gtin'])) {
                throw new CatalogException('gtin_invalid', 422, ['gtin' => $level['gtin']]);
            }

            $byCode[$code] = $level + ['role' => $role->value];
            $byCode[$code]['code'] = $code;
            $byCode[$code]['parent_code'] = isset($level['parent_code']) && $level['parent_code'] !== '' ? strtoupper((string) $level['parent_code']) : null;
        }

        $factors = $this->chain($byCode);
        $known = UnitOfMeasure::query()->whereIn('id', array_column($byCode, 'uom_id'))->pluck('id')->all();

        foreach ($byCode as $code => $level) {
            if (! in_array($level['uom_id'], $known, true)) {
                throw new CatalogException('unit_not_found', 404, ['code' => $code]);
            }
        }

        return DB::transaction(function () use ($product, $byCode, $factors): array {
            CatalogPackaging::query()->where('product_id', $product->id)->forceDelete();
            $created = [];
            $ids = [];

            foreach ($byCode as $code => $level) {
                $row = new CatalogPackaging;
                $ids[$code] = $row->id;
                $row->fill([
                    'product_id' => $product->id,
                    'code' => $code,
                    'role' => $level['role'],
                    'uom_id' => $level['uom_id'],
                    'units_per_parent' => Dec::round($level['units_per_parent'], 6),
                    'quantity_base' => Dec::round($factors[$code]['factor'], 6),
                    'level' => $factors[$code]['level'],
                    'gtin' => $level['gtin'] ?? null,
                ]);
                $row->save();
                $created[] = $row;
            }

            foreach ($created as $row) {
                $parentCode = $byCode[$row->code]['parent_code'];

                if ($parentCode !== null) {
                    $row->forceFill(['parent_packaging_id' => $ids[$parentCode]])->save();
                }
            }

            $this->syncDefaults($product, $created);

            return $created;
        });
    }

    /**
     * The converter of a product: its packaging codes with the base units each contains, and the rounding policy of the product.
     */
    public function converter(Product $product): UomConverter
    {
        $factors = CatalogPackaging::query()->where('product_id', $product->id)->orderBy('level')->get()
            ->mapWithKeys(static fn (CatalogPackaging $packaging): array => [$packaging->code => (string) $packaging->quantity_base])
            ->all();

        return new UomConverter(
            $factors,
            (int) ($product->uom_precision ?? 4),
            RoundingMode::tryFrom((string) ($product->uom_rounding ?? 'half_up')) ?? RoundingMode::HalfUp,
        );
    }

    /**
     * The quantity of a product in each of its units, plus the decomposition into whole packages.
     *
     * @return array{base: string, units: array<string, string>, split: array{parts: list<array{unit: string, quantity: string}>, remainder: string}}
     */
    public function describe(Product $product, string|int|float $quantity, string $unit): array
    {
        $converter = $this->converter($product);
        $base = $converter->toBase($quantity, $unit);
        $units = [];

        foreach ($converter->units() as $code) {
            $units[$code] = Dec::trim($converter->fromBase($base, $code));
        }

        return ['base' => Dec::trim($base), 'units' => $units, 'split' => $converter->split($base)];
    }

    /**
     * Chains the factors from the base unit up. Refuses unknown parents and cycles.
     *
     * @param  array<string, array{parent_code: string|null, units_per_parent: string|int|float}>  $levels
     * @return array<string, array{factor: string, level: int}>
     *
     * @throws CatalogException
     */
    private function chain(array $levels): array
    {
        $resolved = [];

        $resolve = function (string $code, array $trail) use (&$resolve, &$resolved, $levels): array {
            if (isset($resolved[$code])) {
                return $resolved[$code];
            }

            if (in_array($code, $trail, true)) {
                throw new CatalogException('packaging_cycle', 422, ['code' => $code]);
            }

            $level = $levels[$code];
            $parent = $level['parent_code'];

            if ($parent === null) {
                return $resolved[$code] = ['factor' => Dec::of($level['units_per_parent']), 'level' => 1];
            }

            if (! isset($levels[$parent])) {
                throw new CatalogException('packaging_parent_unknown', 422, ['code' => $code, 'parent' => $parent]);
            }

            $up = $resolve($parent, [...$trail, $code]);

            return $resolved[$code] = ['factor' => Dec::mul($up['factor'], $level['units_per_parent']), 'level' => $up['level'] + 1];
        };

        foreach (array_keys($levels) as $code) {
            $resolve($code, []);
        }

        return $resolved;
    }

    /**
     * The purchase and the sales unit of the product follow the first packaging of that role (largest unit first for purchases).
     *
     * @param  list<CatalogPackaging>  $packagings
     */
    private function syncDefaults(Product $product, array $packagings): void
    {
        $first = static function (PackagingRole $role) use ($packagings): ?CatalogPackaging {
            $matches = array_values(array_filter($packagings, static fn (CatalogPackaging $p): bool => $p->role === $role));

            return $matches[0] ?? null;
        };

        $product->forceFill([
            'purchase_uom_id' => $first(PackagingRole::Purchase)?->uom_id,
            'sales_uom_id' => $first(PackagingRole::Sales)?->uom_id,
        ])->save();
    }
}
