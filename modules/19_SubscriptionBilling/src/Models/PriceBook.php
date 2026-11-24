<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class PriceBook extends BaseEnterpriseModel
{
    protected $table = 'sub_price_books';

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
            'rate_overrides' => 'array',
            'metadata' => 'array',
        ];
    }
}
