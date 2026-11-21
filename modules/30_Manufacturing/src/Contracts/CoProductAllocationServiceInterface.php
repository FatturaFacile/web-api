<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Contracts;

use Modules\Manufacturing\Models\MfgBom;

/**
 * Integration contract of the cost allocation between the primary output and the co/by-products of a BOM revision
 * for product costing (Mod 067).
 */
interface CoProductAllocationServiceInterface
{
    /**
     * Replaces the co/by-products of a draft revision; the shares of the co/by-products may not exceed 100% (the
     * primary output carries the remainder).
     *
     * @param  list<array{product_item_id: string, product_type?: string|null, output_ratio_qty: string|int|float, cost_allocation_percentage: string|int|float}>  $lines
     */
    public function replace(MfgBom $bom, array $lines): MfgBom;

    /**
     * The cost shares of the revision: the primary output and every co/by-product, totalling 100%.
     *
     * @return array{bom_id: string, primary_item_id: string, primary_percentage: string, co_products: list<array{product_item_id: string, product_type: string, output_ratio_qty: string, cost_allocation_percentage: string}>, total_percentage: string}
     */
    public function allocation(MfgBom $bom): array;
}
