<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Praline box, gelato cake or take-away tub pre-order.
 */
final class GelatoOrder extends BaseEnterpriseModel
{
    protected $table = 'gelato_assorted_box_and_cake_orders';

    protected function casts(): array
    {
        return [
            'components' => 'array',
            'total_price' => 'float',
            'component_cost' => 'float',
            'pickup_at' => 'datetime',
        ];
    }
}
