<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finds out whether a product is referenced by stock movements or by any document, order or production line, which
 * makes it unsafe to delete. Every table with a `product_id` (or BOM) column is checked, except catalogue-only ones.
 */
class ProductReferences
{
    private const COLUMNS = ['product_id', 'finished_product_id', 'raw_product_id'];

    /**
     * Tables whose rows are catalogue data removed together with the product.
     */
    private const IGNORED_TABLES = ['products', 'price_list_items', 'ecommerce_mappings'];

    /**
     * @var array<int, array{0: string, 1: string}>|null
     */
    private static ?array $references = null;

    public function exist(string $productId): bool
    {
        foreach ($this->references() as [$table, $column]) {
            if (DB::table($table)->where($column, $productId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function references(): array
    {
        if (self::$references !== null) {
            return self::$references;
        }

        $references = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (in_array($table, self::IGNORED_TABLES, true)) {
                continue;
            }

            foreach (array_intersect(self::COLUMNS, Schema::getColumnListing($table)) as $column) {
                $references[] = [$table, $column];
            }
        }

        return self::$references = $references;
    }
}
