<?php

declare(strict_types=1);

namespace Modules\Pricing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A price agreed with one customer for one product: a fixed price and/or a discount cascade on the base price.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $customer_id
 * @property string $product_id
 * @property string|null $fixed_price
 * @property string|null $discount_cascade
 * @property string $discount_mode
 * @property string $currency
 */
final class CustomerSpecialPrice extends BaseEnterpriseModel
{
    protected $table = 'customer_special_prices';

    protected function casts(): array
    {
        return [
            'fixed_price' => 'decimal:4',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }
}
