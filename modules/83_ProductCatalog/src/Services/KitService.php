<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Modules\ProductCatalog\Domain\Enums\KitType;
use Modules\ProductCatalog\Domain\Enums\ProductType;
use Modules\ProductCatalog\Domain\Support\Dec;
use Modules\ProductCatalog\Exceptions\CatalogException;
use Modules\ProductCatalog\Models\CatalogKitComponent;

/**
 * The bill of materials of kits and its explosion. A phantom kit appears on the commercial lines (order, invoice) and is exploded
 * into its components for picking and the transport document; a physical kit is stocked under its own SKU and is never exploded.
 * Kits may contain other kits (a phantom inside a phantom explodes down to the leaves); cycles are refused.
 */
final class KitService
{
    public const int MAX_DEPTH = 8;

    /**
     * Replaces the components of a kit.
     *
     * @param  list<array{component_id: string, quantity: string|int|float, is_optional?: bool}>  $lines
     * @return list<CatalogKitComponent>
     *
     * @throws CatalogException
     */
    public function setComponents(Product $kit, array $lines): array
    {
        if ($kit->kit_type === null) {
            throw new CatalogException('not_a_kit');
        }

        $seen = [];

        foreach ($lines as $line) {
            $id = $line['component_id'];

            if ($id === $kit->id) {
                throw new CatalogException('kit_contains_itself');
            }

            if (isset($seen[$id])) {
                throw new CatalogException('duplicate_component', 422, ['component' => $id]);
            }

            $seen[$id] = true;

            if (Dec::cmp($line['quantity'], 0) <= 0) {
                throw new CatalogException('component_quantity_invalid', 422, ['component' => $id]);
            }
        }

        $components = Product::query()->whereIn('id', array_keys($seen))->get()->keyBy('id');

        foreach (array_keys($seen) as $id) {
            $component = $components->get($id);

            if ($component === null) {
                throw new CatalogException('component_not_found', 404, ['component' => $id]);
            }

            $type = ProductType::tryFrom((string) ($component->product_type ?? 'storable')) ?? ProductType::Storable;

            if (! $type->canBeKitComponent()) {
                throw new CatalogException('component_type_not_allowed', 422, ['sku' => $component->sku, 'type' => $type->value]);
            }

            if (! $component->is_active) {
                throw new CatalogException('component_inactive', 422, ['sku' => $component->sku]);
            }
        }

        if ($kit->kit_type === KitType::Physical->value && $kit->track_inventory === false) {
            throw new CatalogException('physical_kit_must_track_stock');
        }

        $this->assertNoCycle($kit->id, array_keys($seen));

        return DB::transaction(function () use ($kit, $lines): array {
            CatalogKitComponent::query()->where('kit_id', $kit->id)->forceDelete();
            $created = [];

            foreach ($lines as $position => $line) {
                $created[] = CatalogKitComponent::create([
                    'kit_id' => $kit->id,
                    'component_id' => $line['component_id'],
                    'quantity' => Dec::round($line['quantity'], 6),
                    'is_optional' => (bool) ($line['is_optional'] ?? false),
                    'sort_order' => $position,
                ]);
            }

            return $created;
        });
    }

    /**
     * Refuses a component set that would make the kit contain itself, directly or through nested kits.
     *
     * @param  list<string>  $componentIds
     *
     * @throws CatalogException
     */
    public function assertNoCycle(string $kitId, array $componentIds): void
    {
        $edges = CatalogKitComponent::query()->get(['kit_id', 'component_id'])
            ->groupBy('kit_id')
            ->map(static fn ($rows): array => $rows->pluck('component_id')->all())
            ->all();

        // The kit's current components are replaced, so they are not edges of the graph being checked.
        unset($edges[$kitId]);
        $stack = $componentIds;
        $visited = [];

        while ($stack !== []) {
            $id = array_pop($stack);

            if ($id === $kitId) {
                throw new CatalogException('kit_cycle');
            }

            if (isset($visited[$id])) {
                continue;
            }

            $visited[$id] = true;

            foreach ($edges[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }
    }

    /**
     * The flat components a quantity of a product resolves to. A phantom kit is replaced by its components (recursively), anything
     * else, including a physical kit, resolves to itself. Components of the same product are added up; the order is stable
     * (first appearance in the depth-first walk of the sorted bill of materials).
     *
     * @return list<array{product_id: string, sku: string, name: string, quantity: string, via_kits: list<string>}>
     *
     * @throws CatalogException
     */
    public function explode(Product $product, string|int|float $quantity = 1, bool $includeOptional = false): array
    {
        $leaves = [];
        $this->walk($product, Dec::of($quantity), $includeOptional, [], 0, $leaves);

        return array_values(array_map(static fn (array $leaf): array => [
            'product_id' => $leaf['product_id'],
            'sku' => $leaf['sku'],
            'name' => $leaf['name'],
            'quantity' => Dec::trim($leaf['quantity']),
            'via_kits' => array_values(array_unique($leaf['via_kits'])),
        ], $leaves));
    }

    /**
     * Splits commercial lines (what the customer sees on the order or the invoice) from the warehouse lines (what is picked and
     * written on the transport document).
     *
     * @param  list<array{product_id: string, quantity: string|int|float, include_optional?: bool}>  $lines
     * @return array{commercial: list<array{product_id: string, sku: string, name: string, quantity: string, kit_type: string|null}>, warehouse: list<array{product_id: string, sku: string, name: string, quantity: string, via_kits: list<string>}>}
     *
     * @throws CatalogException
     */
    public function explodeLines(array $lines): array
    {
        $products = Product::query()->whereIn('id', array_column($lines, 'product_id'))->get()->keyBy('id');
        $commercial = [];
        $warehouse = [];

        foreach ($lines as $line) {
            $product = $products->get($line['product_id']);

            if ($product === null) {
                throw new CatalogException('component_not_found', 404, ['component' => $line['product_id']]);
            }

            if (Dec::cmp($line['quantity'], 0) <= 0) {
                throw new CatalogException('component_quantity_invalid', 422, ['component' => $product->sku]);
            }

            $commercial[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'quantity' => Dec::trim($line['quantity']),
                'kit_type' => $product->kit_type,
            ];

            foreach ($this->explode($product, $line['quantity'], (bool) ($line['include_optional'] ?? false)) as $leaf) {
                $key = $leaf['product_id'];

                if (isset($warehouse[$key])) {
                    $warehouse[$key]['quantity'] = Dec::trim(Dec::add($warehouse[$key]['quantity'], $leaf['quantity']));
                    $warehouse[$key]['via_kits'] = array_values(array_unique([...$warehouse[$key]['via_kits'], ...$leaf['via_kits']]));
                } else {
                    $warehouse[$key] = $leaf;
                }
            }
        }

        return ['commercial' => $commercial, 'warehouse' => array_values($warehouse)];
    }

    /**
     * @param  array<string, string>  $path  the phantom kits being exploded: id => SKU
     * @param  array<string, array{product_id: string, sku: string, name: string, quantity: string, via_kits: list<string>}>  $leaves
     */
    private function walk(Product $product, string $quantity, bool $includeOptional, array $path, int $depth, array &$leaves): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new CatalogException('kit_too_deep', 422, ['max' => self::MAX_DEPTH]);
        }

        if (isset($path[$product->id])) {
            throw new CatalogException('kit_cycle');
        }

        if ($product->kit_type !== KitType::Phantom->value) {
            $key = $product->id;
            $via = array_values($path);

            if (isset($leaves[$key])) {
                $leaves[$key]['quantity'] = Dec::add($leaves[$key]['quantity'], $quantity);
                $leaves[$key]['via_kits'] = [...$leaves[$key]['via_kits'], ...$via];
            } else {
                $leaves[$key] = ['product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'quantity' => $quantity, 'via_kits' => $via];
            }

            return;
        }

        $components = CatalogKitComponent::query()
            ->where('kit_id', $product->id)
            ->when(! $includeOptional, static fn ($query) => $query->where('is_optional', false))
            ->orderBy('sort_order')
            ->with('component')
            ->get();

        if ($components->isEmpty()) {
            throw new CatalogException('phantom_kit_empty', 422, ['sku' => $product->sku]);
        }

        foreach ($components as $line) {
            if ($line->component === null) {
                continue;
            }

            $this->walk($line->component, Dec::mul($quantity, $line->quantity), $includeOptional, $path + [$product->id => $product->sku], $depth + 1, $leaves);
        }
    }
}
