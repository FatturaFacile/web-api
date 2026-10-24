<?php

declare(strict_types=1);

namespace App\Events\Inventory;

use App\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A product or variant has been switched off automatically because its stock ran out.
 */
class ProductVariantDeactivated
{
    use Dispatchable;

    public function __construct(public readonly Product $product) {}
}
