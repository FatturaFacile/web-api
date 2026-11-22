<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Weight read from a connected scale or typed in manually.
 */
final class GelatoScaleReading extends BaseEnterpriseModel
{
    protected $table = 'gelato_scale_readings';

    protected function casts(): array
    {
        return [
            'grams' => 'float',
            'read_at' => 'datetime',
        ];
    }
}
