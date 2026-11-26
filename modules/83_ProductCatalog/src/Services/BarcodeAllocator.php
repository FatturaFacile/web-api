<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Services;

use App\Models\Product;
use Modules\ProductCatalog\Domain\Support\Gtin;

/**
 * Hands out in-house EAN-13 codes (prefix 2) that no product of the company uses yet.
 */
final class BarcodeAllocator
{
    /**
     * The highest in-house EAN-13 sequence of the company (codes `2` + 11 digits + check digit), 0 when there is none.
     */
    public function lastSequence(): int
    {
        $max = Product::withTrashed()->where('barcode', 'like', '2____________')->max('barcode');

        return $max === null ? 0 : (int) substr((string) $max, 1, 11);
    }

    public function next(): string
    {
        return Gtin::inHouseEan13($this->lastSequence() + 1);
    }
}
