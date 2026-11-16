<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\DTOs\LandedCostAllocationLine;
use Modules\Inventory\Enums\LandedCostAllocationKey;

interface LandedCostAllocationServiceInterface
{
    /**
     * Computes (without persisting) the allocation of $totalAmount (company currency, 2 decimals)
     * onto the receipt cost layers of $receiptIds by $key, using largest-remainder rounding so that
     * the parts sum exactly to $totalAmount, and splits each part between inventory (units still on
     * hand), COGS (units already sold) and, for standard-cost items, purchase price variance.
     *
     * @param  list<string>  $receiptIds
     * @return list<LandedCostAllocationLine>
     *
     * @throws \Illuminate\Validation\ValidationException when no target has a basis > 0, a receipt has
     *                                                    no inventory layers, or items lack weight/volume
     */
    public function allocate(LandedCostAllocationKey $key, string $totalAmount, array $receiptIds): array;
}
